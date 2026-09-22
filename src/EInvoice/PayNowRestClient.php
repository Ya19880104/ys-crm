<?php

declare(strict_types=1);

namespace YangSheep\CRM\EInvoice;

use YangSheep\CRM\Core\HttpClient;

/**
 * PayNow 電子發票 REST v1 傳輸層。
 *
 * 移植自 ys-enhance-hosting 的 YSPayNowRestInvoiceClient（正式機實測定案）。
 *
 * 【端點】正式 https://invoiceapi-prod.paynow.com.tw/、測試 https://invoiceapi-dev.paynow.com.tw/
 * 【認證】商家 JWT 放 Authorization: Bearer
 * 【成功判定】三重白名單：HTTP 2xx + 回應 status 2xx + type === 'success'
 *            —— 任一不符即非成功。這是「HTTP 200 ≠ 業務成功」的具體防線。
 *
 * 本類別**不丟例外**，一律回傳結構化陣列；特別是 indeterminate 旗標：
 * 開立或作廢若未取得明確成功或拒絕證據，外部副作用可能已完成但回應遺失，
 * 呼叫端必須補查而非直接重送（包含所有 5xx 與無法判讀的 2xx）。
 */
final class PayNowRestClient implements InvoiceClientInterface
{
    /** These 4xx responses cannot prove that an irreversible write was rejected. */
    private const AMBIGUOUS_CLIENT_STATUSES = [408, 409, 429];

    private string $baseUrl;
    private string $token;
    private string $environment;
    private HttpClient $http;

    /** @var callable|null fn(array $entry): void — API log 寫入器（注入以便測試） */
    private $logger;

    public function __construct(
        string $baseUrl,
        string $token,
        string $environment = 'test',
        ?HttpClient $http = null,
        ?callable $logger = null
    ) {
        $this->baseUrl     = rtrim(trim($baseUrl), '/') . '/';
        $this->token       = trim($token);
        $this->environment = $environment === 'production' ? 'production' : 'test';
        $this->http        = $http ?? new HttpClient();
        $this->logger      = $logger;
    }

    /**
     * 依環境取得預設端點。
     */
    public static function defaultBaseUrl(string $environment): string
    {
        return $environment === 'production'
            ? 'https://invoiceapi-prod.paynow.com.tw/'
            : 'https://invoiceapi-dev.paynow.com.tw/';
    }

    public function issueInvoice(array $payload, array $context = []): array
    {
        $result = $this->request('issue', 'POST', '/api/invoices/issue', $payload, [], $context);
        if (empty($result['success'])) {
            return $result;
        }

        $invoice = is_array($result['data']['result'] ?? null) ? $result['data']['result'] : [];
        $number  = self::responseString($invoice['invoice_number'] ?? null);
        $validFields = (!isset($invoice['invoice_date']) || is_string($invoice['invoice_date']))
            && (!isset($invoice['random_number']) || is_string($invoice['random_number']));
        if ($number === '' || !$validFields) {
            // 回應宣稱成功卻沒有發票號碼＝狀態未知，必須當作不確定而非成功，
            // 否則會把一張不存在號碼的發票標成已開立。
            return array_merge($result, [
                'success'       => false,
                'indeterminate' => true,
                'message'       => 'PayNow 回應成功但發票號碼或資料格式無法確認。',
            ]);
        }

        return array_merge($result, [
            'invoice_number' => $number,
            'invoice_date'   => self::responseString($invoice['invoice_date'] ?? null),
            'random_number'  => self::responseString($invoice['random_number'] ?? null),
            'invoice'        => $invoice,
        ]);
    }

    public function queryByOrder(string $orderNo, array $context = []): array
    {
        return $this->query(['OrderNo' => $orderNo, 'Limit' => 50, 'Page' => 1], $context);
    }

    public function queryByNumber(string $invoiceNumber, array $context = []): array
    {
        return $this->query(
            ['InvoiceNumber' => $invoiceNumber, 'Limit' => 1, 'Page' => 1],
            $context,
            'invoice_number',
            $invoiceNumber
        );
    }

    public function cancelInvoice(string $invoiceNumber, array $context = []): array
    {
        $result = $this->request(
            'cancel',
            'POST',
            '/api/invoices/cancel',
            ['invoice_number' => $invoiceNumber],
            [],
            $context
        );
        if (empty($result['success'])) {
            return $result;
        }

        $invoice = is_array($result['data']['result'] ?? null) ? $result['data']['result'] : [];
        $cancelled = self::responseString($invoice['invoice_number'] ?? null);
        if ($cancelled === '' || !hash_equals(trim($invoiceNumber), $cancelled)) {
            return array_merge($result, [
                'success'       => false,
                'indeterminate' => true,
                'message'       => 'PayNow 回應成功但已作廢發票號碼缺漏或不符。',
            ]);
        }

        return array_merge($result, ['invoice_number' => $cancelled]);
    }

    public function testConnection(): array
    {
        // REST v1 沒有專用測試 API，以唯讀查詢當探測（驗 JWT 與端點是否正確）。
        $result = $this->request('connection_test', 'GET', '/api/invoices', [], ['Limit' => 1, 'Page' => 1]);

        return [
            'success'    => !empty($result['success']),
            'message'    => (string) ($result['message'] ?? ''),
            'request_id' => (string) ($result['request_id'] ?? ''),
            'raw'        => (array) ($result['raw'] ?? []),
        ];
    }

    /**
     * 查詢並挑出「可沿用」的發票。
     *
     * 挑選規則（缺一不可）：
     *   - 排除本地已作廢的號碼（作廢重開時，絕不能把剛作廢的那張認成有效發票）
     *   - 排除 PayNow 端狀態為作廢的發票
     *   - 若指定 expected_total_amount，金額必須相符（避免張冠李戴）
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function query(array $query, array $context, string $matchKey = '', string $matchValue = ''): array
    {
        $result = $this->request('query', 'GET', '/api/invoices', [], $query, $context);
        if (empty($result['success'])) {
            $result['found']   = false;
            $result['invoice'] = null;
            return $result;
        }

        $collection = is_array($result['data']['result'] ?? null) ? $result['data']['result'] : [];
        $invoices = $collection['invoices'] ?? null;
        $validCollection = is_array($invoices) && array_is_list($invoices);
        if ($validCollection) {
            foreach ($invoices as $candidate) {
                if (!is_array($candidate) || self::responseString($candidate['invoice_number'] ?? null) === '') {
                    $validCollection = false;
                    break;
                }
                foreach (['status', 'invoice_date', 'random_number'] as $field) {
                    if (isset($candidate[$field]) && !is_string($candidate[$field])) {
                        $validCollection = false;
                    }
                }
                if (isset($candidate['total_amount']) && !is_numeric($candidate['total_amount'])) {
                    $validCollection = false;
                }
            }
        }
        if (!$validCollection) {
            // Malformed evidence is not a successful "not found" lookup. In
            // particular, it must not authorize a fresh irreversible issue call.
            return array_merge($result, [
                'success' => false,
                'found' => false,
                'invoice' => null,
                'message' => 'PayNow 查詢回應資料格式無法確認。',
            ]);
        }
        $invoice  = null;

        $excludedNumbers = array_values(array_filter(
            array_map(
                static fn(mixed $number): string => self::responseString($number),
                (array) ($context['exclude_invoice_numbers'] ?? [])
            ),
            static fn(string $number): bool => $number !== ''
        ));

        $hasExpectedTotal = array_key_exists('expected_total_amount', $context);
        $expectedTotal    = $hasExpectedTotal ? (int) round((float) $context['expected_total_amount']) : 0;

        if (is_array($invoices)) {
            foreach ($invoices as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                if ($matchKey !== ''
                    && self::responseString($candidate[$matchKey] ?? null) !== trim($matchValue)
                ) {
                    continue;
                }
                if ($matchKey === '') {
                    $number = self::responseString($candidate['invoice_number'] ?? null);
                    $status = strtolower(self::responseString($candidate['status'] ?? null));
                    if ($number === ''
                        || in_array($number, $excludedNumbers, true)
                        || in_array($status, ['cancel', 'cancelled', 'void', 'voided'], true)
                    ) {
                        continue;
                    }
                    if ($hasExpectedTotal
                        && (!array_key_exists('total_amount', $candidate)
                            || (int) round((float) $candidate['total_amount']) !== $expectedTotal)
                    ) {
                        continue;
                    }
                }
                $invoice = $candidate;
                break;
            }
        }

        $result['found']   = $invoice !== null;
        $result['invoice'] = $invoice;
        return $result;
    }

    /**
     * 發送請求並統一解析回應，同時寫入 API log。
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $query
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function request(
        string $operation,
        string $method,
        string $path,
        array $payload = [],
        array $query = [],
        array $context = []
    ): array {
        $started       = microtime(true);
        $correlationId = (string) ($context['correlation_id'] ?? HttpClient::uuid4());
        $url           = $this->baseUrl . ltrim($path, '/');

        if ($this->token === '') {
            $result = [
                'success'       => false,
                'indeterminate' => false,
                'message'       => 'PayNow REST JWT Token 尚未設定。',
                'request_id'    => '',
                'raw'           => [],
            ];
            $this->writeLog($operation, $method, $url, $payload ?: $query, $result, 0, $started, $correlationId, $context);
            return $result;
        }

        $options = [
            'headers' => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $this->token,
            ],
            'query'   => $query,
            'timeout' => 20,
        ];
        if ($method !== 'GET') {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        $response   = $this->http->request($method, $url, $options);
        $httpStatus = (int) $response['status'];
        $body       = (string) $response['body'];

        $isIrreversibleWrite = in_array($operation, ['issue', 'cancel'], true);

        // 連線層失敗（DNS/timeout/TLS）：不可逆寫入視為不確定，必須補查。
        if ($httpStatus === 0) {
            $result = [
                'success'       => false,
                'indeterminate' => $isIrreversibleWrite,
                'message'       => 'PayNow REST API 連線失敗。',
                'request_id'    => '',
                'raw'           => ['error' => (string) ($response['error'] ?? '')],
                'http_status'   => 0,
            ];
            $this->writeLog($operation, $method, $url, $payload ?: $query, $result, 0, $started, $correlationId, $context);
            return $result;
        }

        $data      = json_decode($body, true);
        $data      = is_array($data) ? $data : [];
        $requestId = self::responseString($data['request_id'] ?? $data['requestId'] ?? null);
        $statusValue = $data['status'] ?? null;
        $apiStatus = is_int($statusValue) || (is_string($statusValue) && preg_match('/^[1-5][0-9]{2}$/D', $statusValue))
            ? (int) $statusValue : 0;
        $type = strtolower(self::responseString($data['type'] ?? null));

        // 三重白名單：HTTP 2xx + API status 2xx + type === 'success'
        $success = $httpStatus >= 200 && $httpStatus < 300
            && $apiStatus >= 200 && $apiStatus < 300
            && $type === 'success';

        // A non-success response alone is not proof of rejection. Conservatively
        // accept only a matching client-error envelope; this is our safety rule,
        // not a claim that arbitrary HTML/error bodies describe PayNow's outcome.
        $provenRejection = $httpStatus >= 400 && $httpStatus < 500
            && $apiStatus === $httpStatus && $type === 'error'
            && !in_array($httpStatus, self::AMBIGUOUS_CLIENT_STATUSES, true);

        $message = self::responseString($data['message'] ?? null);
        if ($message === '') {
            $message = $success
                ? 'PayNow REST API 呼叫成功。'
                : sprintf('PayNow REST API HTTP %d。', $httpStatus);
        }

        $result = [
            'success'       => $success,
            'indeterminate' => $isIrreversibleWrite && !$success && !$provenRejection,
            'message'       => $message,
            'request_id'    => $requestId,
            'data'          => $data,
            'raw'           => $data,
            'http_status'   => $httpStatus,
        ];
        if ($data === [] && $body !== '') {
            $result['raw'] = ['unparsed_body' => $body];
        }

        $this->writeLog($operation, $method, $url, $payload ?: $query, $result, $httpStatus, $started, $correlationId, $context);
        return $result;
    }

    /** Provider-controlled arrays/objects must never be cast to strings. */
    private static function responseString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string,mixed> $requestPayload
     * @param array<string,mixed> $result
     * @param array<string,mixed> $context
     */
    private function writeLog(
        string $operation,
        string $method,
        string $url,
        array $requestPayload,
        array $result,
        int $httpStatus,
        float $started,
        string $correlationId,
        array $context
    ): void {
        $entry = [
            'invoice_id'       => (int) ($context['invoice_id'] ?? 0),
            'payment_id'       => (int) ($context['payment_id'] ?? 0),
            'correlation_id'   => $correlationId,
            'operation'        => $operation,
            'environment'      => $this->environment,
            'http_method'      => $method,
            'endpoint'         => $url,
            'http_status'      => $httpStatus,
            'success'          => !empty($result['success']) ? 1 : 0,
            'request_id'       => (string) ($result['request_id'] ?? ''),
            'duration_ms'      => (int) round((microtime(true) - $started) * 1000),
            'request_payload'  => $this->encodeForLog($this->scrubToken($requestPayload)),
            'response_payload' => $this->encodeForLog($this->scrubToken((array) ($result['raw'] ?? []))),
            'error_message'    => empty($result['success']) ? (string) ($result['message'] ?? '') : '',
        ];

        if ($this->logger !== null) {
            ($this->logger)($entry);
            return;
        }
        InvoiceApiLogRepository::record($entry);
    }

    /** @param array<string,mixed> $value */
    private function encodeForLog(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * 遮蔽 log 中的 JWT，避免憑證外洩到資料庫。
     */
    private function scrubToken(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->token !== '' ? str_replace($this->token, '***REDACTED***', $value) : $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->scrubToken($child);
        }
        return $value;
    }
}
