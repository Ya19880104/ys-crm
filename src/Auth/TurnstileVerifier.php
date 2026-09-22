<?php

declare(strict_types=1);

namespace YangSheep\CRM\Auth;

class TurnstileVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * 驗證 Cloudflare Turnstile Token
     *
     * 若 TURNSTILE_SECRET_KEY 環境變數為空，則跳過驗證（開發模式）
     */
    public function verify(?string $token, string $ip): bool
    {
        $secretKey = $_ENV['TURNSTILE_SECRET_KEY'] ?? '';

        // 未設定密鑰則跳過驗證
        if ($secretKey === '') {
            return true;
        }

        if ($token === null || $token === '') {
            return false;
        }

        $response = $this->sendRequest($secretKey, $token, $ip);

        if ($response === null) {
            return false;
        }

        return $response['success'] === true;
    }

    /**
     * 發送驗證請求到 Cloudflare
     */
    private function sendRequest(string $secretKey, string $token, string $ip): ?array
    {
        $data = [
            'secret'   => $secretKey,
            'response' => $token,
            'remoteip' => $ip,
        ];

        $ch = curl_init(self::VERIFY_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // PHP 8.0 起 curl handle 已是物件（CurlHandle），離開作用域即由 GC 釋放；
        // curl_close() 在 8.5 為 deprecated 的 no-op，呼叫只會產生警告。

        if ($result === false || $httpCode !== 200) {
            return null;
        }

        $decoded = json_decode($result, true);
        return is_array($decoded) ? $decoded : null;
    }
}
