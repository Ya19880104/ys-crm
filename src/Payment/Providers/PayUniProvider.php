<?php

declare(strict_types=1);

namespace YangSheep\CRM\Payment\Providers;

use YangSheep\CRM\Payment\PayUni\PayUniClient;
use YangSheep\CRM\Payment\PayUni\PayUniCrypto;
use YangSheep\CRM\Payment\PaymentProviderInterface;
use YangSheep\CRM\Payment\RefundableProviderInterface;
use YangSheep\CRM\Setting\SettingService;

/**
 * PayUni（統一金）金流商（對應架構設計 §7.9）。
 *
 * 採用 PayUni UPP 跳轉式支付（信用卡 / ATM / CVS / LINE Pay 等）：
 *   - createCheckout 產生自動送出的 POST 表單到 PayUni /api/upp，瀏覽器導向 PayUni 付款頁。
 *   - 付款後 PayUni 以 NotifyURL（server-to-server webhook）回呼，verifyCallback 驗章 + 解密入帳。
 *
 * 加密規格（與 PayUni API 文件一致；演算法取自 ys-cart YSCrypto，去除所有 WordPress 相依）：
 *   - EncryptInfo：AES-256-GCM 加密 http_build_query(params)，輸出 hex(ciphertext . ':::' . base64(tag))。
 *   - HashInfo：strtoupper(SHA256(HashKey . EncryptInfo . HashIV))。
 *   - 回呼驗證：以相同公式重算 HashInfo 與回呼 HashInfo 做 constant-time 比對（hash_equals），
 *     通過後 AES-256-GCM 解密 EncryptInfo 取得交易結果。
 *
 * 金鑰來源：設定 payment 群組（payuni_merchant_id / payuni_hash_key / payuni_hash_iv，
 *   後兩者 encrypted:true 自動 AES-256-GCM 解密）。任一缺漏 → createCheckout / 入帳前置一律
 *   throw \RuntimeException（fail-closed）。
 *
 * 環境：設定 payment_env（test→sandbox-api / prod→api）。
 *
 * 主動呼叫類的 API（退款 / 查詢 / Token 扣款）委派 PayUniClient；本檔只負責
 * 導轉表單與回呼驗章，兩者共用 PayUniCrypto。
 *
 * 實際上線需以 PayUni 後台核發之 MerID/HashKey/HashIV 設定並於 sandbox 實測。
 */
final class PayUniProvider implements PaymentProviderInterface, RefundableProviderInterface
{
    private SettingService $settings;
    private ?PayUniClient $client;

    public function __construct(?SettingService $settings = null, ?PayUniClient $client = null)
    {
        $this->settings = $settings ?? new SettingService();
        $this->client   = $client;
    }

    public function key(): string
    {
        return 'payuni';
    }

    /**
     * 取得 API client（延遲建立，讓金鑰設定變更即時生效）。
     */
    private function client(): PayUniClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $creds = $this->credentials(); // 缺漏即 throw（fail-closed）

        return new PayUniClient(
            $creds['merchant_id'],
            $creds['hash_key'],
            $creds['hash_iv'],
            (string) ($this->settings->get('payment', 'payment_env') ?? 'test')
        );
    }

    /**
     * 退款（信用卡走 /api/trade/close，CloseType=2）。
     *
     * @param array<string, mixed> $payment
     * @return array{ok: bool, outcome: string, message: string, data?: array<string,mixed>}
     */
    public function refund(array $payment, int $amount = 0): array
    {
        $tradeNo = trim((string) ($payment['provider_txn_id'] ?? ''));
        if ($tradeNo === '') {
            return [
                'ok'      => false,
                'outcome' => 'rejected_terminal',
                'message' => '此筆付款沒有 PayUni 交易序號，無法線上退款。',
            ];
        }

        $client = $this->client();
        $result = $client->refund($tradeNo, $amount);

        if (($result['outcome'] ?? '') !== 'indeterminate') {
            return $result;
        }

        // ── 結果不確定 → 立刻補一次交易查詢，把證據撈回來 ──
        //
        // 【為何是「單次立即查詢」而不是輪詢或重送】
        // 重送退款在不確定的狀態下就是雙退；輪詢則是拿量測到的延遲當 SLA，
        // 對方慢一點就整條 request 卡住。查詢是唯讀的，做一次沒有副作用。
        //
        // 【為何查到了也不自動改判】
        // PayUni 的 trade/query 回應中，哪個欄位、哪個值代表「這筆已退款」，
        // 我方沒有實測證據可依據（僅有官方文件的一般敘述）。用猜的欄位語意去
        // 自動解除退款鎖，等於把「不確定」換成「看起來很確定的錯誤」。
        // 故這裡只把**經過驗章**的查詢結果附在訊息與 data 裡供人判讀，
        // 狀態仍維持不確定、鎖仍保留。
        try {
            $probe = $client->queryTrade($tradeNo);
        } catch (\Throwable) {
            return $result;
        }

        // 只採信驗章通過的查詢結果；未簽章的內容附上去只會誤導判讀。
        if (($probe['signed'] ?? false) !== true) {
            return $result;
        }

        if (!isset($result['data']) || !is_array($result['data'])) {
            $result['data'] = [];
        }
        $result['data']['_refund_probe'] = $probe['data'] ?? [];
        $result['message'] = trim((string) $result['message'])
            . ' ｜ 退款後即時查詢（僅供判讀，未據以改寫狀態）：'
            . (trim((string) ($probe['message'] ?? '')) !== ''
                ? (string) $probe['message']
                : '查詢已回應，詳見稽核紀錄。');

        return $result;
    }

    /**
     * 交易查詢。
     *
     * @return array{ok: bool, outcome: string, message: string, data?: array<string,mixed>}
     */
    public function queryTrade(string $tradeNo, string $merTradeNo = ''): array
    {
        return $this->client()->queryTrade($tradeNo, $merTradeNo);
    }

    /**
     * 建立 UPP 跳轉表單。
     *
     * @return array{mode: 'form', url: string, fields: array<string, string>}
     * @throws \RuntimeException 金鑰未設定時。
     */
    public function createCheckout(array $payment, string $returnUrl, string $callbackUrl): array
    {
        $creds = $this->credentials(); // 缺漏即 throw

        $paymentNo = (string) ($payment['payment_no'] ?? '');
        // 金額一律取伺服器端 payment.amount（整數元），不信任前端。
        $amount = (int) round((float) ($payment['amount'] ?? 0));

        // UPP 交易參數（PayUni 規格）。MerID/Timestamp 進 EncryptInfo。
        $tradeData = [
            'MerID'      => $creds['merchant_id'],
            'MerTradeNo' => $paymentNo,
            'TradeAmt'   => $amount,
            'Timestamp'  => time(),
            'ProdDesc'   => '報價單付款 ' . $paymentNo,
            'ReturnURL'  => $returnUrl,   // 使用者付款後導回（顯示結果）
            'NotifyURL'  => $callbackUrl, // server-to-server 入帳通知（實際入帳依據）
        ];

        $encryptInfo = $this->encrypt($tradeData, $creds['hash_key'], $creds['hash_iv']);
        $hashInfo    = $this->hash($encryptInfo, $creds['hash_key'], $creds['hash_iv']);

        return [
            'mode'   => 'form',
            'url'    => $this->apiUrl('upp'),
            'fields' => [
                'MerID'       => $creds['merchant_id'],
                'Version'     => '1.0',
                'EncryptInfo' => $encryptInfo,
                'HashInfo'    => $hashInfo,
            ],
        ];
    }

    /**
     * 驗證 PayUni 回呼（NotifyURL / ReturnURL）。
     *
     * 回呼欄位：EncryptInfo / HashInfo。
     * 解密後取得：MerTradeNo（=payment_no）、TradeStatus（'1'=成功）、TradeNo（金流序號）、
     *   TradeAmt（金額）、PaymentType（付款方式）。
     */
    public function verifyCallback(array $post, array $server): array
    {
        // 金鑰缺漏 → 視為無法驗章（fail-closed），回 ok=false（呼叫端回 4xx 不入帳）。
        try {
            $creds = $this->credentials();
        } catch (\RuntimeException) {
            return ['ok' => false];
        }

        $encryptInfo = (string) ($post['EncryptInfo'] ?? '');
        $hashInfo    = (string) ($post['HashInfo'] ?? '');
        if ($encryptInfo === '' || $hashInfo === '') {
            return ['ok' => false];
        }

        // 驗章：重算 HashInfo 與回呼比對（constant-time）。
        $expected = $this->hash($encryptInfo, $creds['hash_key'], $creds['hash_iv']);
        if (!hash_equals($expected, $hashInfo)) {
            return ['ok' => false];
        }

        // 解密取得交易結果。
        $decrypted = $this->decrypt($encryptInfo, $creds['hash_key'], $creds['hash_iv']);
        if ($decrypted === null) {
            return ['ok' => false];
        }

        $data = [];
        parse_str($decrypted, $data);

        // TradeStatus：'1'=交易成功；其餘視為失敗。
        $tradeStatus = (string) ($data['TradeStatus'] ?? '');
        $status      = $tradeStatus === '1' ? 'paid' : 'failed';
        $txnId       = (string) ($data['TradeNo'] ?? '');
        $amount      = (int) round((float) ($data['TradeAmt'] ?? 0));
        $method      = isset($data['PaymentType']) ? (string) $data['PaymentType'] : null;
        // MerTradeNo = 本系統 payment_no（建立 checkout 時帶入）。
        $reference   = (string) ($data['MerTradeNo'] ?? '');

        if ($txnId === '') {
            // 無交易序號無法防重放 → 拒絕入帳。
            return ['ok' => false];
        }

        return [
            'ok'        => true,
            'txn_id'    => $txnId,
            'status'    => $status,
            'amount'    => $amount,
            'method'    => $method,
            'reference' => $reference,
        ];
    }

    // ───────────────────────── 設定 / 金鑰 ─────────────────────────

    /**
     * 取得並驗證金鑰（缺漏即 throw，fail-closed）。
     *
     * @return array{merchant_id: string, hash_key: string, hash_iv: string}
     * @throws \RuntimeException
     */
    private function credentials(): array
    {
        $merchantId = trim((string) ($this->settings->get('payment', 'payuni_merchant_id') ?? ''));
        $hashKey    = trim((string) ($this->settings->get('payment', 'payuni_hash_key') ?? ''));
        $hashIv     = trim((string) ($this->settings->get('payment', 'payuni_hash_iv') ?? ''));

        if ($merchantId === '' || $hashKey === '' || $hashIv === '') {
            throw new \RuntimeException('未設定金流金鑰');
        }

        return [
            'merchant_id' => $merchantId,
            'hash_key'    => $hashKey,
            'hash_iv'     => $hashIv,
        ];
    }

    /**
     * API 基底 URL（依環境 test/prod）。
     */
    private function apiUrl(string $endpoint): string
    {
        $env  = (string) ($this->settings->get('payment', 'payment_env') ?? 'test');
        $base = $env === 'prod'
            ? 'https://api.payuni.com.tw/api/'
            : 'https://sandbox-api.payuni.com.tw/api/';
        return $base . ltrim($endpoint, '/');
    }

    // ───────────────── PayUni 加解密（委派共用實作） ─────────────────
    //
    // 演算法本體在 Payment\PayUni\PayUniCrypto，與 PayUniClient 共用同一份。
    // 保留這三個薄包裝是為了讓上方的導轉／驗章流程讀起來仍是連貫的，
    // 但**不得**在此重新實作演算法——兩份實作只要有一處被改動就會不對稱，
    // 而加解密不對稱的症狀（PayUni 回加密錯誤）極難從表象追回根因。

    /**
     * @param array<string, mixed> $data
     */
    private function encrypt(array $data, string $key, string $iv): string
    {
        return PayUniCrypto::encrypt($data, $key, $iv);
    }

    private function decrypt(string $encryptStr, string $key, string $iv): ?string
    {
        return PayUniCrypto::decrypt($encryptStr, $key, $iv);
    }

    private function hash(string $encryptStr, string $key, string $iv): string
    {
        return PayUniCrypto::hash($encryptStr, $key, $iv);
    }
}
