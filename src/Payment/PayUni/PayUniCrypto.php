<?php

declare(strict_types=1);

namespace YangSheep\CRM\Payment\PayUni;

/**
 * PayUni 加解密與簽章（AES-256-GCM + SHA256）。
 *
 * 演算法與 ys-cart 的 YSCrypto PayUni 區段同源，四套自家實作與官方生態外掛
 * （wpbr-payuni-payment）全部一致，已於 sandbox 與正式環境驗證。
 *
 * 【格式重點】密文最終形態是 `bin2hex(ciphertext . ':::' . base64(tag))`——
 * GCM 的 authentication tag 以 `:::` 分隔附在密文之後再整體轉 hex。
 * 這不是常見寫法，改動任何一段都會讓 PayUni 直接回加密錯誤。
 *
 * 本類別為純函式（不碰 DB、不發 HTTP），可完整單元測試。
 */
final class PayUniCrypto
{
    private const CIPHER = 'aes-256-gcm';

    /** tag 與密文的分隔符（PayUni 規格） */
    private const TAG_SEPARATOR = ':::';

    /**
     * 加密：array → http_build_query → AES-256-GCM → hex(ciphertext:::base64(tag))
     *
     * @param array<string, mixed> $data
     * @throws \RuntimeException 加密失敗（金鑰長度不符等）
     */
    public static function encrypt(array $data, string $key, string $iv): string
    {
        $tag       = '';
        $encrypted = openssl_encrypt(
            http_build_query($data),
            self::CIPHER,
            trim($key),
            0,
            trim($iv),
            $tag
        );

        if ($encrypted === false) {
            throw new \RuntimeException('PayUni 加密失敗');
        }

        return trim(bin2hex($encrypted . self::TAG_SEPARATOR . base64_encode($tag)));
    }

    /**
     * 解密：hex → bin → split(:::) → AES-256-GCM decrypt → query string
     *
     * 失敗一律回 null（不丟例外）：解密失敗在回呼情境代表「來源不可信」，
     * 呼叫端應拒絕該筆資料，而不是讓例外中斷回呼流程。
     */
    public static function decrypt(string $encryptStr, string $key, string $iv): ?string
    {
        $binary = @hex2bin($encryptStr);
        if ($binary === false) {
            return null;
        }

        $parts = explode(self::TAG_SEPARATOR, $binary, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$encryptData, $tag] = $parts;

        $decrypted = openssl_decrypt(
            $encryptData,
            self::CIPHER,
            trim($key),
            0,
            trim($iv),
            base64_decode($tag)
        );

        return $decrypted !== false ? $decrypted : null;
    }

    /**
     * 解密並解析為陣列。
     *
     * @return array<string, string>|null
     */
    public static function decryptToArray(string $encryptStr, string $key, string $iv): ?array
    {
        $decrypted = self::decrypt($encryptStr, $key, $iv);
        if ($decrypted === null) {
            return null;
        }

        $result = [];
        parse_str($decrypted, $result);

        /** @var array<string, string> $result */
        return $result;
    }

    /**
     * 簽章：strtoupper(SHA256(HashKey . EncryptInfo . HashIV))
     */
    public static function hash(string $encryptStr, string $key, string $iv): string
    {
        return strtoupper(hash('sha256', $key . $encryptStr . $iv));
    }

    /**
     * 驗章（constant-time 比對，避免時序側通道）。
     */
    public static function verifyHash(string $encryptStr, string $receivedHash, string $key, string $iv): bool
    {
        return hash_equals(self::hash($encryptStr, $key, $iv), strtoupper(trim($receivedHash)));
    }
}
