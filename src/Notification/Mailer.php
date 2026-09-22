<?php

declare(strict_types=1);

namespace YangSheep\CRM\Notification;

use YangSheep\CRM\Setting\SettingService;

/**
 * 極簡自寫 SMTP 寄信客戶端（對應架構設計 §7.10、§7.2 SMTP 設定）。
 *
 * 為何自寫：本專案無 vendor / composer 套件（內建 Autoloader），不得引入 PHPMailer。
 * 以 fsockopen + SMTP 對話（EHLO / STARTTLS / AUTH LOGIN / MAIL FROM / RCPT TO / DATA）
 * 完成寄送，足以支撐通知信。
 *
 * 設定來源（{prefix}settings group=notification，見 SettingController SETTING_SCHEMA）：
 *   smtp_host / smtp_port / smtp_username / smtp_password(加密) / smtp_encryption(none|tls|ssl)
 *   notify_email（寄件者顯示信箱；缺則用 smtp_username）
 *
 * Zero Trust / 健壯性：
 *   - 無 SMTP 設定時「不丟致命錯」：回傳 retryable outcome，
 *     呼叫端（cron mail）據此把信留在 queued，於後台檢視。
 *   - SMTP 密碼自設定解密（is_encrypted）。
 *   - Header 防注入：主旨/收件者剝除 CR/LF，避免 SMTP header injection。
 *   - 連線/讀寫逾時，避免 cron 卡死。
 */
class Mailer
{
    private SettingService $settings;

    /** 連線與讀寫逾時（秒）。 */
    private const TIMEOUT = 15;

    public function __construct(?SettingService $settings = null)
    {
        $this->settings = $settings ?? new SettingService();
    }

    /**
     * 是否已完成基本 SMTP 設定（host + port 至少要有）。
     */
    public function isConfigured(): bool
    {
        $cfg = $this->config();
        return $cfg['host'] !== '' && $cfg['port'] > 0;
    }

    /**
     * 寄送一封 HTML 信。
     *
     * @return array{ok: bool, outcome: 'accepted'|'retryable'|'indeterminate', error?: string}
     */
    public function send(string $toEmail, string $subject, string $bodyHtml): array
    {
        $toEmail = $this->sanitizeHeader($toEmail);
        $subject = $this->sanitizeHeader($subject);

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'outcome' => 'retryable', 'error' => '收件者信箱格式不正確。'];
        }

        $cfg = $this->config();
        if ($cfg['host'] === '' || $cfg['port'] <= 0) {
            // 未設定 SMTP：不丟致命錯，回報以便信件留在 queued 於後台檢視。
            return ['ok' => false, 'outcome' => 'retryable', 'error' => '未設定 SMTP，信件已保留於佇列，請於系統設定填寫 SMTP 後重送。'];
        }

        $fromEmail = $cfg['from'] !== '' ? $cfg['from'] : $cfg['username'];
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'outcome' => 'retryable', 'error' => '寄件者信箱未設定或格式不正確（請於系統設定填寫通知信箱或 SMTP 帳號）。'];
        }

        try {
            return $this->deliver($cfg, $fromEmail, $toEmail, $subject, $bodyHtml);
        } catch (\Throwable $e) {
            // deliver 會依 DATA 邊界分類；這裡只涵蓋尚未開始 SMTP 的本地例外。
            return ['ok' => false, 'outcome' => 'retryable', 'error' => 'SMTP 寄送失敗：' . $e->getMessage()];
        }
    }

    /**
     * 寄送測試信（系統設定頁「寄送測試信」按鈕用）。
     *
     * @return array{ok: bool, outcome: 'accepted'|'retryable'|'indeterminate', error?: string}
     */
    public function sendTest(string $toEmail): array
    {
        $subject = 'YS CRM SMTP 測試信';
        $body = '<p>這是一封來自 YS CRM 的 SMTP 測試信。</p>'
              . '<p>若您收到此信，表示 SMTP 設定正確。</p>'
              . '<p style="color:#64748B;font-size:12px;">寄送時間：' . date('Y-m-d H:i:s') . '</p>';
        return $this->send($toEmail, $subject, $body);
    }

    // ───────────────────────── 內部 ─────────────────────────

    /**
     * 讀取並解密 SMTP 設定。
     *
     * @return array{host: string, port: int, username: string, password: string, encryption: string, from: string}
     */
    private function config(): array
    {
        return [
            'host'       => trim((string) ($this->settings->get('notification', 'smtp_host') ?? '')),
            'port'       => (int) ($this->settings->get('notification', 'smtp_port') ?? 0),
            'username'   => trim((string) ($this->settings->get('notification', 'smtp_username') ?? '')),
            'password'   => (string) ($this->settings->get('notification', 'smtp_password') ?? ''),
            'encryption' => strtolower((string) ($this->settings->get('notification', 'smtp_encryption') ?? 'none')),
            'from'       => trim((string) ($this->settings->get('notification', 'notify_email') ?? '')),
        ];
    }

    /**
     * 實際 SMTP 對話與寄送。
     *
     * @param array{host: string, port: int, username: string, password: string, encryption: string, from: string} $cfg
     * @return array{ok: bool, outcome: 'accepted'|'retryable'|'indeterminate', error?: string}
     */
    private function deliver(array $cfg, string $fromEmail, string $toEmail, string $subject, string $bodyHtml): array
    {
        $transport = $cfg['encryption'] === 'ssl' ? 'ssl://' : '';
        $errno = 0;
        $errstr = '';

        $socket = @fsockopen($transport . $cfg['host'], $cfg['port'], $errno, $errstr, self::TIMEOUT);
        if ($socket === false) {
            return [
                'ok' => false,
                'outcome' => 'retryable',
                'error' => "無法連線 SMTP 伺服器（{$cfg['host']}:{$cfg['port']}）：{$errstr}",
            ];
        }
        stream_set_timeout($socket, self::TIMEOUT);
        $dataStarted = false;

        try {
            $this->expect($socket, 220);

            $ehloHost = $this->ehloHost();
            $this->command($socket, "EHLO {$ehloHost}", 250);

            // STARTTLS（顯式 TLS）
            if ($cfg['encryption'] === 'tls') {
                $this->command($socket, 'STARTTLS', 220);
                $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (!@stream_socket_enable_crypto($socket, true, $crypto)) {
                    throw new \RuntimeException('STARTTLS 加密協商失敗。');
                }
                // TLS 後需重新 EHLO。
                $this->command($socket, "EHLO {$ehloHost}", 250);
            }

            // AUTH LOGIN（有帳密才認證；部分內網 relay 可免認證）。
            if ($cfg['username'] !== '') {
                $password = $this->resolvePassword($cfg['password']);
                $this->command($socket, 'AUTH LOGIN', 334);
                $this->command($socket, base64_encode($cfg['username']), 334);
                // 認證失敗時 SMTP 回 535；expect 會丟例外，訊息不含明文密碼。
                $this->command($socket, base64_encode($password), 235);
            }

            $this->command($socket, 'MAIL FROM:<' . $fromEmail . '>', 250);
            $this->command($socket, 'RCPT TO:<' . $toEmail . '>', 250);
            $this->command($socket, 'DATA', 354);

            $message = $this->buildMessage($fromEmail, $toEmail, $subject, $bodyHtml);
            // DATA 內文結尾以單獨一行「.」結束。
            // 從這一刻起，任何 write/response failure 都無法證明 SMTP 沒有接受信件。
            $dataStarted = true;
            $this->write($socket, $message . "\r\n.");
            $this->expect($socket, 250);

            // 盡力 QUIT（失敗不影響已被接受的信件）。
            try {
                $this->command($socket, 'QUIT', 221);
            } catch (\Throwable) {
            }

            return ['ok' => true, 'outcome' => 'accepted'];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'outcome' => $dataStarted ? 'indeterminate' : 'retryable',
                'error' => 'SMTP 寄送失敗：' . $e->getMessage(),
            ];
        } finally {
            @fclose($socket);
        }
    }

    /**
     * 解密 SMTP 密碼。
     *
     * 設定以 is_encrypted=1 儲存時，SettingService::get 已自動解密回明文；
     * 故此處直接回傳（保留方法作為語意邊界，未來若改為傳入密文可在此解）。
     */
    private function resolvePassword(string $stored): string
    {
        return $stored;
    }

    /**
     * 組裝 RFC 822 / MIME 信件（UTF-8、HTML、Base64 編碼內文避免行長/編碼問題）。
     */
    private function buildMessage(string $fromEmail, string $toEmail, string $subject, string $bodyHtml): string
    {
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $date = date('r');
        $messageId = '<' . bin2hex(random_bytes(16)) . '@' . $this->ehloHost() . '>';

        $headers = [
            'Date: ' . $date,
            'From: YS CRM <' . $fromEmail . '>',
            'To: <' . $toEmail . '>',
            'Subject: ' . $encodedSubject,
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        // 內文 base64 並每 76 字元斷行（RFC 2045）。dot-stuffing 不需要（base64 不會產生行首單獨的 .）。
        $body = chunk_split(base64_encode($bodyHtml), 76, "\r\n");

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    /**
     * 送出一條 SMTP 指令並驗證回應碼。
     *
     * @throws \RuntimeException
     */
    private function command($socket, string $command, int $expectedCode): void
    {
        $this->write($socket, $command);
        $this->expect($socket, $expectedCode, $command);
    }

    /**
     * 寫入一行（自動補 CRLF）。
     */
    private function write($socket, string $data): void
    {
        if (fwrite($socket, $data . "\r\n") === false) {
            throw new \RuntimeException('SMTP 寫入失敗（連線中斷）。');
        }
    }

    /**
     * 讀取 SMTP 回應並驗證狀態碼。支援多行回應（如 250-…多行後 250 …）。
     *
     * @param string $context 失敗訊息用的指令名稱（已過濾，不含密碼明文）
     * @throws \RuntimeException
     */
    private function expect($socket, int $expectedCode, string $context = ''): void
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            // 多行回應：第 4 字元為 '-' 表示還有後續行；為空白表示最後一行。
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        if ($response === '') {
            $meta = stream_get_meta_data($socket);
            if (!empty($meta['timed_out'])) {
                throw new \RuntimeException('SMTP 回應逾時。');
            }
            throw new \RuntimeException('SMTP 無回應（連線中斷）。');
        }

        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            // 不回傳可能含敏感資訊的整段回應細節；僅給狀態碼與指令名（context 經 sanitize）。
            $safeContext = $context !== '' ? $this->commandName($context) : '';
            throw new \RuntimeException(sprintf(
                'SMTP 回應碼異常（預期 %d，實得 %d）%s。',
                $expectedCode,
                $code,
                $safeContext !== '' ? "：{$safeContext}" : ''
            ));
        }
    }

    /**
     * 取指令動詞（避免把 base64 密碼等內容帶進錯誤訊息）。
     */
    private function commandName(string $command): string
    {
        $verb = strtok($command, ' ');
        $verb = $verb === false ? '' : strtoupper($verb);
        // AUTH 後續的 base64 行不是動詞，統一以已知動詞白名單回報，其餘標示為資料行。
        $known = ['EHLO', 'HELO', 'STARTTLS', 'AUTH', 'MAIL', 'RCPT', 'DATA', 'QUIT'];
        return in_array($verb, $known, true) ? $verb : '(資料)';
    }

    /**
     * EHLO 用主機名（取站台網域；缺則 localhost）。
     */
    private function ehloHost(): string
    {
        $url = (string) ($this->settings->get('site', 'site_url') ?? '');
        $host = $url !== '' ? (parse_url($url, PHP_URL_HOST) ?: '') : '';
        if ($host === '') {
            $host = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
        }
        // 僅保留合法主機字元。
        $host = preg_replace('/[^A-Za-z0-9.\-]/', '', $host) ?? 'localhost';
        return $host !== '' ? $host : 'localhost';
    }

    /**
     * 剝除 header 值中的 CR/LF（防 SMTP header injection）。
     */
    private function sanitizeHeader(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }
}
