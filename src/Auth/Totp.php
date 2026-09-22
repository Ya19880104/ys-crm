<?php

declare(strict_types=1);

namespace YangSheep\CRM\Auth;

/**
 * RFC 6238 TOTP（時間型一次性密碼）— 自寫實作，無外部相依。
 *
 * 規格：HMAC-SHA1、6 碼、30 秒時間步，相容 Google Authenticator / Authy / 1Password 等。
 * secret 以 Base32（RFC 4648，無 padding）表示，供 provisioning URI 與驗證使用。
 *
 * 安全要點：
 * - secret 由 random_bytes() 產生（CSPRNG），不使用任何可預測來源。
 * - verify() 以 hash_equals() 常數時間比較，且不在比對成功時提前 return，
 *   避免「哪一個時間步命中」洩漏為時間側通道。
 */
final class Totp
{
    /** TOTP 碼位數 */
    private const DIGITS = 6;

    /** 時間步長（秒） */
    private const PERIOD = 30;

    /** secret bytes 長度（20 bytes = 160 bits，RFC 4226 建議的 SHA1 金鑰長度） */
    private const SECRET_BYTES = 20;

    /** Base32 字母表（RFC 4648） */
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * 產生新的隨機 secret，回傳 Base32 字串。
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    /**
     * 驗證使用者輸入的 TOTP 碼。
     *
     * @param string $base32Secret Base32 編碼的 secret（已解密）
     * @param string $code         使用者輸入（容許含空白；只取數字）
     * @param int    $window       容許的前後時間步數（預設 ±1 = 容許時鐘偏移 30 秒）
     * @return bool                驗證是否通過
     */
    public static function verify(string $base32Secret, string $code, int $window = 1): bool
    {
        return self::verifyWithStep($base32Secret, $code, $window) !== null;
    }

    /**
     * 驗證 TOTP 碼，並回傳「命中的時間步（absolute step）」以供重放保護（RFC 6238 單次使用）。
     *
     * 與 verify() 行為一致，差別僅在回傳值：
     *  - 命中 → 回傳該碼對應的絕對時間步（int，呼叫端據此拒絕 step <= last_step）。
     *  - 未命中 / 輸入不合法 → 回傳 null。
     *
     * 安全要點（與既有 verify() 相同，務必維持）：
     *  - hash_equals() 常數時間比較。
     *  - 不在命中時提前 return / break，逐一比較全部時間步後才結束，
     *    避免「哪一個時間步命中」洩漏為時間側通道。
     *  - 多個時間步同時命中（極罕見）時，採最後一個（最新）命中步，
     *    以最大化重放保護的單調性。
     *
     * @param string $base32Secret Base32 編碼的 secret（已解密）
     * @param string $code         使用者輸入（容許含空白；只取數字）
     * @param int    $window       容許的前後時間步數（預設 ±1）
     * @return int|null            命中的絕對時間步；未命中回 null
     */
    public static function verifyWithStep(string $base32Secret, string $code, int $window = 1): ?int
    {
        // 正規化輸入：移除空白，僅保留數字
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $key = self::base32Decode($base32Secret);
        if ($key === '') {
            return null;
        }

        if ($window < 0) {
            $window = 0;
        }

        $currentStep = self::timeStep();

        // 不提前 return：逐一比較全部時間步，避免命中位置成為時間側通道。
        $matchedStep = null;
        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $currentStep + $offset;
            $candidate = self::computeCode($key, $step);
            if (hash_equals($candidate, $code)) {
                // 不 break：持續走完全部 offset；保留最後（最新）命中步。
                $matchedStep = $step;
            }
        }

        return $matchedStep;
    }

    /**
     * 產生 otpauth:// provisioning URI，供驗證器 App 掃描 QR 或手動匯入。
     *
     * 格式：otpauth://totp/{issuer}:{label}?secret=...&issuer=...&algorithm=SHA1&digits=6&period=30
     *
     * @param string $base32Secret Base32 secret
     * @param string $label        帳號標籤（通常為使用者名稱或 email）
     * @param string $issuer       發行者名稱（系統名稱）
     */
    public static function provisioningUri(string $base32Secret, string $label, string $issuer): string
    {
        // label 採 "issuer:account" 形式，issuer 與 label 皆需 URL 編碼
        $account = rawurlencode($label);
        $issuerEnc = rawurlencode($issuer);

        $query = http_build_query([
            'secret'    => $base32Secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);

        return "otpauth://totp/{$issuerEnc}:{$account}?{$query}";
    }

    /**
     * 目前的時間步（unix time / period，向下取整）。
     */
    private static function timeStep(): int
    {
        return (int) floor(time() / self::PERIOD);
    }

    /**
     * 依 RFC 6238 / 4226 計算指定時間步的 TOTP 碼（含前導零，長度固定 DIGITS）。
     *
     * @param string $key  原始 secret bytes
     * @param int    $step 時間步
     */
    private static function computeCode(string $key, int $step): string
    {
        // 計數器：8-byte big-endian
        $counter = pack('N*', 0, $step);

        $hash = hash_hmac('sha1', $counter, $key, true);

        // 動態截斷（RFC 4226 §5.3）
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $otp = $binary % (10 ** self::DIGITS);

        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Base32 編碼（RFC 4648，無 padding，大寫）。
     */
    private static function base32Encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $alphabet = self::BASE32_ALPHABET;
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $buffer = ($buffer << 8) | ord($bytes[$i]);
            $bitsLeft += 8;
            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $output .= $alphabet[($buffer >> $bitsLeft) & 0x1F];
            }
        }

        // 處理剩餘不足 5 bits 的尾端
        if ($bitsLeft > 0) {
            $output .= $alphabet[($buffer << (5 - $bitsLeft)) & 0x1F];
        }

        return $output;
    }

    /**
     * Base32 解碼（RFC 4648）。容許小寫、空白與 '=' padding；非法字元一律回傳空字串。
     *
     * @return string 原始 bytes；輸入無效時回傳 ''
     */
    private static function base32Decode(string $input): string
    {
        // 正規化：去除空白與 padding，轉大寫
        $input = strtoupper(preg_replace('/[\s=]+/', '', $input) ?? '');
        if ($input === '') {
            return '';
        }

        $map = array_flip(str_split(self::BASE32_ALPHABET));

        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        $len = strlen($input);
        for ($i = 0; $i < $len; $i++) {
            $char = $input[$i];
            if (!isset($map[$char])) {
                // 含非 Base32 字元 → 視為無效
                return '';
            }
            $buffer = ($buffer << 5) | $map[$char];
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}
