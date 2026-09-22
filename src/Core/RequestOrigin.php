<?php

declare(strict_types=1);

namespace YangSheep\CRM\Core;

/**
 * 請求來源的信任邊界 —— 全站「這個請求是不是 HTTPS」與「站台網址是什麼」的唯一判定處。
 *
 * 【為什麼需要這個類別】（複審 2026-08-17）
 * 原本共有五處各自判斷 scheme 與 host：index.php 的 HTTPS 強制轉址、
 * PublicPaymentController、PortalPaymentController、QuoteController、InstallController。
 * 五份實作都做了同樣的兩個錯誤假設：
 *
 *   1. **相信 X-Forwarded-Proto**。那是一個純粹由客戶端送來的 header，
 *      沒有任何東西阻止任何人自己加上去。實測：
 *          curl http://…/login                              → 301、body 0
 *          curl -H 'X-Forwarded-Proto: https' http://…/login → 200、完整登入表單
 *      也就是說「明文端永遠只有空 301」這個保證，一個 header 就繞過了。
 *      只有當請求**確實來自我方已知的反向代理**時，這個 header 才有意義。
 *
 *   2. **拿 Host header 直接拼 URL**。Host 同樣由客戶端控制。它會被拼進
 *      301 的 Location（開放轉址）、拼進寄給客戶的報價連結、
 *      更嚴重的是拼進送給金流商的 NotifyURL / ReturnURL —— 那等於讓外人
 *      指定「付款完成後通知誰」。目前公開 vhost 會先對陌生 Host 回 404，
 *      但那是 vhost 的行為，不是程式的保證，不能拿來當防線。
 *
 * 【判定順序】
 *   scheme：$_SERVER['HTTPS'] → SERVER_PORT=443 → （僅當來自信任代理）X-Forwarded-Proto
 *   host  ：APP_URL / ALLOWED_HOSTS 白名單 → process env 的 INSTALL_HOST（僅 fresh bootstrap）
 *
 * 兩者都拿不到可信值時一律 fail-closed，不會用不可信的輸入拼出網址。
 */
final class RequestOrigin
{
    /**
     * 這個請求是否確實走在 TLS 上。
     *
     * 只有伺服器自己設定的證據（HTTPS / SERVER_PORT）無條件採信；
     * X-Forwarded-Proto 必須先證明請求來自 TRUSTED_PROXIES 名單內的位址。
     */
    public static function isSecure(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }

        if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        if (self::behindTrustedProxy()
            && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }

        return false;
    }

    /**
     * 請求是否來自已設定的信任代理。
     *
     * TRUSTED_PROXIES 未設定時一律回 false —— 「沒設定」必須等於「不信任」，
     * 反過來的預設值會讓所有未設定的部署都可被 header 繞過。
     */
    public static function behindTrustedProxy(): bool
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remote === '') {
            return false;
        }

        return ClientIpResolver::isTrustedProxy($remote, self::csvEnv('TRUSTED_PROXIES'));
    }

    /**
     * 可信的主機名稱（含 port）。取不到時回空字串，呼叫端必須據此 fail-closed。
     *
     * 優先序：白名單命中的 Host → APP_URL 的 host → process env 的 INSTALL_HOST。
     * 之所以讓命中白名單的 Host 排在最前，是為了支援同一套程式服務多個網域；
     * 沒命中就退回伺服器端自己知道的值，絕不採用請求帶進來的字串。
     */
    public static function host(): string
    {
        $allowed = self::allowedHosts();
        $host    = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));

        // 語法檢查只是最低門檻，真正的把關是白名單。
        $syntaxOk = $host !== '' && preg_match('/^[a-z0-9.\-]+(:\d+)?$/', $host) === 1;

        if ($syntaxOk && in_array($host, $allowed, true)) {
            return $host;
        }

        $fromAppUrl = self::appUrlHost();
        if ($fromAppUrl !== '') {
            return $fromAppUrl;
        }

        return self::installHost();
    }

    /**
     * 入站 Host 是否屬於部署者允許的站台名稱。
     *
     * host() 可為組 URL 而回退到部署者明示的 APP_URL / INSTALL_HOST；那個 fallback
     * 不能拿來驗證請求本身，否則任意 HTTP_HOST 都會因 fallback 非空而被放行。
     */
    public static function requestHostAllowed(bool $allowFreshBootstrap = false): bool
    {
        $requestHost = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
        if ($requestHost === '' || preg_match('/^[a-z0-9.\-]+(:\d+)?$/', $requestHost) !== 1) {
            return false;
        }

        $allowed = self::allowedHosts();
        if (in_array($requestHost, $allowed, true)) {
            return true;
        }

        // HTTP_HOST 與 SERVER_NAME 在多數 SAPI 可能來自同一個 request header，
        // 兩者相等不是獨立證據。fresh bootstrap 只認部署者在 PHP process
        // environment 明示的 INSTALL_HOST。
        if (!$allowFreshBootstrap || $allowed !== []) {
            return false;
        }

        $installHost = self::installHost();
        return $installHost !== '' && hash_equals($installHost, $requestHost);
    }

    /**
     * HTTPS 強制轉址用的唯一 canonical host。
     *
     * ALLOWED_HOSTS 是接受哪些入站 Host 的 allowlist，不是 redirect 的目的地清單；
     * redirect 必須只採部署者在 APP_URL 宣告的 canonical host。
     */
    public static function canonicalHost(): string
    {
        return self::appUrlHost();
    }

    /**
     * HTTPS redirect 的目的 host。
     *
     * 已配置站台永遠只用 APP_URL。全新安裝尚無 APP_URL 時，則僅在入站 Host 已
     * 通過 fresh-bootstrap INSTALL_HOST 比對後，採用該部署 anchor，讓第一個
     * HTTP request 能安全轉到 HTTPS，而不是因 canonical host 尚不存在直接 400。
     */
    public static function redirectHost(bool $freshBootstrap = false): string
    {
        $canonical = self::canonicalHost();
        if ($canonical !== '' || !$freshBootstrap || !self::requestHostAllowed(true)) {
            return $canonical;
        }

        return self::installHost();
    }

    /**
     * 站台基底網址（scheme://host），取不到可信 host 時回空字串。
     *
     * APP_URL 若已設定則直接採用 —— 那是部署者明確宣告的正式網址，
     * 比任何由請求推導出來的值都可靠，也讓寄出去的連結在 CLI／cron 下同樣正確。
     */
    public static function baseUrl(): string
    {
        $appUrl = rtrim(trim((string) ($_ENV['APP_URL'] ?? '')), '/');
        if ($appUrl !== '' && preg_match('#^https?://[^/]+$#i', $appUrl) === 1) {
            return $appUrl;
        }

        $host = self::host();
        if ($host === '') {
            return '';
        }

        return (self::isSecure() ? 'https' : 'http') . '://' . $host;
    }

    /**
     * 組出對外的絕對網址；沒有可信基底時回傳相對路徑（呼叫端至少不會拿到偽造網域）。
     */
    public static function absoluteUrl(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $base = self::baseUrl();

        return $base !== '' ? $base . $path : $path;
    }

    /**
     * 允許的主機名稱白名單（小寫）。
     *
     * @return list<string>
     */
    public static function allowedHosts(): array
    {
        $hosts = self::csvEnv('ALLOWED_HOSTS');

        $fromAppUrl = self::appUrlHost();
        if ($fromAppUrl !== '') {
            $hosts[] = $fromAppUrl;
        }

        return array_values(array_unique(array_map('strtolower', $hosts)));
    }

    private static function appUrlHost(): string
    {
        $appUrl = trim((string) ($_ENV['APP_URL'] ?? ''));
        if ($appUrl === '') {
            return '';
        }

        $parsed = parse_url($appUrl);
        $host   = strtolower((string) ($parsed['host'] ?? ''));
        if ($host === '') {
            return '';
        }

        $port = (int) ($parsed['port'] ?? 0);
        // 標準 port 不寫進 Host header，寫進去反而不會相符。
        if ($port > 0 && $port !== 80 && $port !== 443) {
            $host .= ':' . $port;
        }

        return $host;
    }

    /**
     * fresh installer 的部署者明示 Host。
     *
     * 必須用 getenv() 讀 PHP process environment；不能用 HTTP_HOST 或
     * SERVER_NAME 推導，也不把這個 bootstrap-only anchor 寫進應用程式 .env。
     */
    private static function installHost(): string
    {
        $raw = getenv('INSTALL_HOST');
        if (!is_string($raw)) {
            return '';
        }

        $host = strtolower(trim($raw));
        if ($host === '' || preg_match('/^[a-z0-9.\-]+(?::\d+)?$/', $host) !== 1) {
            return '';
        }

        return $host;
    }

    /**
     * @return list<string>
     */
    private static function csvEnv(string $key): array
    {
        $raw = trim((string) ($_ENV[$key] ?? ''));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $v): bool => $v !== ''));
    }
}
