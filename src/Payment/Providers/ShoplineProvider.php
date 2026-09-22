<?php

declare(strict_types=1);

namespace YangSheep\CRM\Payment\Providers;

use YangSheep\CRM\Payment\PaymentProviderInterface;
use YangSheep\CRM\Setting\SettingService;

/**
 * SHOPLINE Payments 金流商（對應架構設計 §7.9）。
 *
 * 採用 SHOPLINE Payments 導轉式結帳：
 *   - createCheckout 呼叫「建立結帳交易」API（POST /api/v1/trade/sessions/create）取得 sessionUrl，
 *     瀏覽器導向該 URL（SHOPLINE 付款頁）。
 *   - 付款後 SHOPLINE 以 Webhook（server-to-server）回呼，verifyCallback 以 HMAC-SHA256 驗章入帳。
 *
 * 簽章規格（與 SHOPLINE Payments Webhook 文件一致）：
 *   - payload = timestamp . '.' . bodyString（原始未重新序列化的 body 字串）。
 *   - sign    = HMAC-SHA256(payload, signKey)（hex）。
 *   - 驗證：重算 sign 與回呼 header `sign` 做 constant-time 比對（hash_equals），
 *     並檢查 timestamp 是否在容許時窗內（防重放）。
 *
 * 金鑰來源：設定 payment 群組（shopline_merchant_id / shopline_secret，後者 encrypted:true）。
 *   缺漏 → createCheckout / 入帳前置一律 throw \RuntimeException（fail-closed）。
 *   注意：SHOPLINE 區分 apiKey（API 認證）與 signKey（Webhook 簽章）；本骨架以單一
 *   shopline_secret 承載（實務上線時可拆為兩個設定欄位）。
 *
 * 環境：設定 payment_env（test→api-sandbox / prod→api）。
 *
 * 本檔為「介面齊全 + 可被選用」的串接骨架：Webhook 驗章為完整正確實作（純邏輯、可測試）；
 * createCheckout 需實際呼叫 SHOPLINE API 取得 sessionUrl，需以正式核發金鑰於 sandbox 實測。退款留待後續。
 */
final class ShoplineProvider implements PaymentProviderInterface
{
    /** Webhook timestamp 容許時窗（毫秒）：5 分鐘。 */
    private const TIMESTAMP_TOLERANCE_MS = 5 * 60 * 1000;

    private SettingService $settings;

    public function __construct(?SettingService $settings = null)
    {
        $this->settings = $settings ?? new SettingService();
    }

    public function key(): string
    {
        return 'shopline';
    }

    /**
     * 建立結帳交易，回傳導向 sessionUrl。
     *
     * @return array{mode: 'redirect', url: string}
     * @throws \RuntimeException 金鑰未設定或建立交易失敗時。
     */
    public function createCheckout(array $payment, string $returnUrl, string $callbackUrl): array
    {
        $creds = $this->credentials(); // 缺漏即 throw

        $paymentNo = (string) ($payment['payment_no'] ?? '');
        // 金額一律取伺服器端 payment.amount；SHOPLINE 以「分」為單位（×100）。
        $amount   = (int) round((float) ($payment['amount'] ?? 0));
        $currency = (string) ($payment['currency'] ?? 'TWD');

        $body = [
            'referenceId'   => $paymentNo,
            'amount'        => ['value' => $amount * 100, 'currency' => $currency],
            'returnUrl'     => $returnUrl,
            'mode'          => 'regular',
            'idempotentKey' => $paymentNo,
        ];

        $sessionUrl = $this->createSession($creds, $body);
        if ($sessionUrl === null || $sessionUrl === '') {
            throw new \RuntimeException('SHOPLINE 建立結帳交易失敗');
        }

        return [
            'mode' => 'redirect',
            'url'  => $sessionUrl,
        ];
    }

    /**
     * 驗證 SHOPLINE Webhook。
     *
     * @param array<string, mixed> $post   已解析的 body（含 type / data；用於取交易結果）。
     * @param array<string, mixed> $server 伺服器變數（取 HTTP_TIMESTAMP / HTTP_SIGN 與原始 body）。
     *
     * 注意：簽章必須以「原始 body 字串」計算（重新序列化會改變格式導致驗章失敗）。
     * 原始 body 由呼叫端置於 $server['__raw_body']（PublicPaymentController 讀 php://input 後填入）。
     */
    public function verifyCallback(array $post, array $server): array
    {
        try {
            $creds = $this->credentials();
        } catch (\RuntimeException) {
            return ['ok' => false];
        }

        $timestamp = (string) ($server['HTTP_TIMESTAMP'] ?? '');
        $sign      = (string) ($server['HTTP_SIGN'] ?? '');
        $rawBody   = (string) ($server['__raw_body'] ?? '');

        if ($timestamp === '' || $sign === '' || $rawBody === '') {
            return ['ok' => false];
        }

        // 驗章：HMAC-SHA256(timestamp . '.' . rawBody, signKey)。
        $payload  = $timestamp . '.' . $rawBody;
        $expected = hash_hmac('sha256', $payload, $creds['secret']);
        if (!hash_equals($expected, $sign)) {
            return ['ok' => false];
        }

        // 防重放：timestamp（毫秒）需在容許時窗內。
        $nowMs     = (int) round(microtime(true) * 1000);
        $webhookMs = (int) $timestamp;
        if (abs($nowMs - $webhookMs) > self::TIMESTAMP_TOLERANCE_MS) {
            return ['ok' => false];
        }

        // 解析事件：type=trade.succeeded → paid；trade.failed/expired/cancelled → failed。
        $type = (string) ($post['type'] ?? '');
        $data = (array) ($post['data'] ?? []);

        $status = $type === 'trade.succeeded' ? 'paid' : 'failed';
        $txnId  = (string) ($data['tradeOrderId'] ?? '');

        // 金額：data.payment.paidAmount.value（分）→ 元。
        $valueCents = (int) ($data['payment']['paidAmount']['value']
            ?? $data['order']['amount']['value']
            ?? 0);
        $amount = (int) round($valueCents / 100);

        $method = isset($data['payment']['paymentMethod'])
            ? (string) $data['payment']['paymentMethod']
            : null;

        // referenceOrderId / referenceId = 本系統 payment_no（建立交易時帶入）。
        $reference = (string) ($data['referenceOrderId']
            ?? $data['order']['referenceOrderId']
            ?? '');

        if ($txnId === '') {
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
     * @return array{merchant_id: string, secret: string}
     * @throws \RuntimeException
     */
    private function credentials(): array
    {
        $merchantId = trim((string) ($this->settings->get('payment', 'shopline_merchant_id') ?? ''));
        $secret     = trim((string) ($this->settings->get('payment', 'shopline_secret') ?? ''));

        if ($merchantId === '' || $secret === '') {
            throw new \RuntimeException('未設定金流金鑰');
        }

        return [
            'merchant_id' => $merchantId,
            'secret'      => $secret,
        ];
    }

    /**
     * API 基底 URL（依環境 test/prod）。
     */
    private function apiBase(): string
    {
        $env = (string) ($this->settings->get('payment', 'payment_env') ?? 'test');
        return $env === 'prod'
            ? 'https://api.shoplinepayments.com'
            : 'https://api-sandbox.shoplinepayments.com';
    }

    /**
     * 呼叫「建立結帳交易」API 取得 sessionUrl。
     *
     * @param array{merchant_id: string, secret: string} $creds
     * @param array<string, mixed>                       $body
     * @return string|null sessionUrl；失敗回 null。
     */
    private function createSession(array $creds, array $body): ?string
    {
        $url = $this->apiBase() . '/api/v1/trade/sessions/create';

        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return null;
        }

        // 以 cURL 呼叫（無 WP 相依）。合理逾時，fail-closed。
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'merchantId: ' . $creds['merchant_id'],
                'apiKey: ' . $creds['secret'],
                'requestId: ' . bin2hex(random_bytes(8)),
            ],
            CURLOPT_POSTFIELDS     => $payload,
        ]);

        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // PHP 8.0 起 curl handle 已是物件（CurlHandle），離開作用域即由 GC 釋放；
        // curl_close() 在 8.5 為 deprecated 的 no-op，呼叫只會產生警告。

        if ($resp === false || $code < 200 || $code >= 300) {
            return null;
        }

        $json = json_decode((string) $resp, true);
        if (!is_array($json)) {
            return null;
        }

        // 依文件，回傳含 sessionUrl（可能在頂層或 data 內）。
        return $json['sessionUrl']
            ?? $json['data']['sessionUrl']
            ?? null;
    }
}
