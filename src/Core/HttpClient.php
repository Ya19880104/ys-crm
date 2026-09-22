<?php

declare(strict_types=1);

namespace YangSheep\CRM\Core;

/**
 * 極簡 HTTP 用戶端（cURL），供外部 API 串接使用（電子發票、金流查詢／退款…）。
 *
 * 設計重點：
 *   1. **不丟例外**。連線層失敗（DNS/timeout/TLS）回傳 status=0 + error 訊息，
 *      由呼叫端決定如何處理。對金流／發票而言「不確定成功與否」和「明確失敗」
 *      必須能區分，把連線失敗轉成例外會讓這個區分消失。
 *   2. **可注入**。建構子接受 $transport callable，測試時可餵假回應，
 *      不需真的發網路請求即可覆蓋所有分支。
 *   3. 逾時預設 20 秒、不跟隨轉址（金流回應不該轉址，跟隨等於放寬攻擊面）。
 *
 * @phpstan-type HttpResponse array{status:int, body:string, error:?string, duration_ms:int}
 */
final class HttpClient
{
    /** @var callable|null 自訂 transport：fn(string $method, string $url, array $options): array */
    private $transport;

    private int $defaultTimeout;

    public function __construct(?callable $transport = null, int $defaultTimeout = 20)
    {
        $this->transport      = $transport;
        $this->defaultTimeout = max(1, $defaultTimeout);
    }

    /**
     * 發送請求。
     *
     * @param array{headers?:array<string,string>, body?:?string, timeout?:int, query?:array<string,mixed>} $options
     * @return array{status:int, body:string, error:?string, duration_ms:int}
     */
    public function request(string $method, string $url, array $options = []): array
    {
        $method = strtoupper($method);
        $query  = $options['query'] ?? [];
        if (is_array($query) && $query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        if ($this->transport !== null) {
            $started  = microtime(true);
            $response = ($this->transport)($method, $url, $options);
            return [
                'status'      => (int) ($response['status'] ?? 0),
                'body'        => (string) ($response['body'] ?? ''),
                'error'       => isset($response['error']) ? (string) $response['error'] : null,
                'duration_ms' => (int) ($response['duration_ms'] ?? round((microtime(true) - $started) * 1000)),
            ];
        }

        return $this->curlRequest($method, $url, $options);
    }

    /**
     * @param array{headers?:array<string,string>, body?:?string, timeout?:int} $options
     * @return array{status:int, body:string, error:?string, duration_ms:int}
     */
    private function curlRequest(string $method, string $url, array $options): array
    {
        $started = microtime(true);

        if (!function_exists('curl_init')) {
            return [
                'status'      => 0,
                'body'        => '',
                'error'       => 'PHP cURL 擴充未安裝，無法呼叫外部 API。',
                'duration_ms' => 0,
            ];
        }

        $headers = [];
        foreach (($options['headers'] ?? []) as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'status'      => 0,
                'body'        => '',
                'error'       => 'cURL 初始化失敗。',
                'duration_ms' => 0,
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) ($options['timeout'] ?? $this->defaultTimeout),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $body = $options['body'] ?? null;
        if ($body !== null && $method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errNo  = curl_errno($ch);
        $errMsg = $errNo !== 0 ? curl_error($ch) : null;
        // PHP 8.0 起 curl handle 已是物件（CurlHandle），離開作用域即由 GC 釋放；
        // curl_close() 在 8.5 為 deprecated 的 no-op，呼叫只會產生警告。

        return [
            'status'      => $status,
            'body'        => $raw === false ? '' : (string) $raw,
            'error'       => $errMsg,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * 產生 UUID v4（取代 WordPress 的 wp_generate_uuid4，供 correlation id 使用）。
     */
    public static function uuid4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
