<?php

declare(strict_types=1);

namespace YangSheep\CRM\Core;

use YangSheep\CRM\Install\InstallationState;

class Session
{
    /** Shared fingerprint policy for middleware and public-route admin previews. */
    public static function bindingMatches(Request $request, array $binding): bool
    {
        return (($_ENV['SESSION_BIND_IP'] ?? 'false') !== 'true'
                || ($binding['ip'] ?? '') === $request->ip())
            && (($_ENV['SESSION_BIND_UA'] ?? 'true') === 'false'
                || ($binding['ua'] ?? '') === hash('sha256', $request->userAgent()));
    }

    private static bool $started = false;
    private static ?InstallationState $installationState = null;

    public static function start(?InstallationState $installationState = null): void
    {
        if ($installationState !== null) {
            self::$installationState = $installationState;
        }

        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $lifetime = (int) ($_ENV['SESSION_LIFETIME'] ?? 7200);

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => ($_ENV['APP_ENV'] ?? 'production') === 'production',
            'httponly'  => true,
            'samesite'  => 'Lax',
        ]);

        // 安全加固
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        // 註：session.sid_length / sid_bits_per_character 於 PHP 8.4+ 已 deprecated，
        // 不再以 ini_set 設定（PHP 8.5 預設 sid 長度/熵已足夠安全）。

        session_name('yscrm_session');

        // Zero Trust：已安裝（DB 可用）時改用 DB-backed session handler（可撤銷 + 逾時）
        if (self::shouldUseDbHandler()) {
            try {
                $idle = (int) ($_ENV['SESSION_IDLE_TIMEOUT'] ?? 1800);
                $absolute = (int) ($_ENV['SESSION_ABSOLUTE_TIMEOUT'] ?? 28800);
                session_set_save_handler(new DbSessionHandler($idle, $absolute), true);
            } catch (\Throwable) {
                // DB 不可用 → 回退原生檔案 session（安裝流程 / DB 故障時）
            }
        }

        session_start();
        self::$started = true;
    }

    /** 同一份安裝狀態同時控制 route 與 session backend，避免 marker 補寫失敗後分裂。 */
    public static function shouldUseDatabase(InstallationState $installationState): bool
    {
        return $installationState === InstallationState::Installed;
    }

    /** 是否使用 DB-backed session handler；安裝流程用原生 session。 */
    private static function shouldUseDbHandler(): bool
    {
        if (self::$installationState !== null) {
            return self::shouldUseDatabase(self::$installationState);
        }

        // 非 HTTP 舊入口尚未傳入狀態時保留相容判定；index/App 一律走上方 state。
        if (!defined('STORAGE_PATH') || !defined('ENV_PATH')) {
            return false;
        }
        return file_exists(STORAGE_PATH . '/install.lock')
            && file_exists(ENV_PATH . '/.env');
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Flash 訊息 — 設定後只能讀取一次
     */
    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    public static function hasFlash(string $key): bool
    {
        return isset($_SESSION['_flash'][$key]);
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /**
     * Zero Trust：登入後綁定 session 至 user + IP + UA 指紋。
     * 必須在 regenerate() 之後呼叫。同步寫入 {prefix}sessions 綁定欄位（供管理員 kill-switch）。
     */
    public static function bindAuth(string $userType, int $userId, string $ip, string $userAgent, bool $required = false): void
    {
        $uaHash = hash('sha256', $userAgent);
        $_SESSION['_sec'] = [
            'utype' => $userType,
            'uid'   => $userId,
            'ip'    => $ip,
            'ua'    => $uaHash,
            'at'    => time(),
        ];

        if (!self::shouldUseDbHandler()) {
            if ($required) {
                unset($_SESSION['_sec']);
                throw new \RuntimeException('無法建立可撤銷的登入狀態。');
            }
            return;
        }
        try {
            $now = time();
            Database::getInstance()->execute(
                "INSERT INTO {prefix}sessions
                    (id, user_type, user_id, ip_address, user_agent_hash, payload, created_at, last_activity, revoked)
                 VALUES (:id, :ut, :uid, :ip, :ua, '', :ca, :la, 0)
                 ON DUPLICATE KEY UPDATE
                    user_type = :ut_u, user_id = :uid_u, ip_address = :ip_u,
                    user_agent_hash = :ua_u, revoked = 0, revoked_at = NULL,
                    created_at = :ca_u, last_activity = :la_u",
                [
                    'id' => session_id(),
                    'ut' => $userType, 'uid' => $userId, 'ip' => $ip, 'ua' => $uaHash, 'ca' => $now, 'la' => $now,
                    'ut_u' => $userType, 'uid_u' => $userId, 'ip_u' => $ip, 'ua_u' => $uaHash, 'ca_u' => $now, 'la_u' => $now,
                ]
            );
        } catch (\Throwable $e) {
            if ($required) {
                unset($_SESSION['_sec']);
                throw new \RuntimeException('無法儲存登入狀態。', 0, $e);
            }
            // Legacy callers retain best-effort binding; completed admin login is strict.
        }
    }

    /** Persist the completed login before releasing its admission lock or redirecting. */
    public static function persistAuth(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || !self::shouldUseDbHandler()) {
            throw new \RuntimeException('登入狀態尚未就緒。');
        }
        $payload = session_encode();
        if ($payload === false || !(new DbSessionHandler())->write(session_id(), $payload)) {
            throw new \RuntimeException('無法儲存完整登入狀態。');
        }
    }

    /** Never leave a reported-failed login depending on a later shutdown write. */
    public static function invalidateFailedLogin(): void
    {
        unset($_SESSION['user'], $_SESSION['_sec'], $_SESSION['_2fa_pending']);
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600, 'path' => $params['path'], 'domain' => $params['domain'],
                'secure' => $params['secure'], 'httponly' => true, 'samesite' => $params['samesite'] ?: 'Lax',
            ]);
        }
        try {
            // Keep a tombstone: a delayed writer must not recreate an active row.
            Database::getInstance()->execute(
                "UPDATE {prefix}sessions SET revoked = 1, revoked_at = :now, payload = '' WHERE id = :id",
                ['now' => time(), 'id' => session_id()]
            );
        } catch (\Throwable) {
            // The response also expires the new cookie if the DB is unreachable.
            error_log('[single-session] Failed-login revocation unconfirmed; authentication cookie expired.');
        }
    }

    /**
     * 撤銷目前 session（DB 標記 revoked），用於登出 / 綁定不符。
     */
    public static function revokeCurrent(): void
    {
        if (!self::shouldUseDbHandler()) {
            return;
        }
        try {
            Database::getInstance()->execute(
                "UPDATE {prefix}sessions SET revoked = 1, revoked_at = :t WHERE id = :id",
                ['t' => time(), 'id' => session_id()]
            );
        } catch (\Throwable) {
        }
    }

    /**
     * Kill-switch：撤銷某使用者「所有」既存 session（DB 標記 revoked）。
     *
     * 用於帳號被停用 / 刪除 / 密碼被重設或變更時，立即讓該使用者目前所有登入態失效
     * （含被竊取的其他裝置 session）。下一個請求 DbSessionHandler::read() 即視同登出。
     *
     * 注意：此操作會連同「呼叫者本人當前 session」一併撤銷（若 utype/uid 相符）。
     * 例如使用者改自己密碼後，本機 session 也會於下個請求失效、需重新登入——
     * 此為改密碼後要求重新認證的標準安全行為，呼叫端視情況提示使用者。
     *
     * @param string $userType 'admin' | 'customer'，須與 bindAuth() 寫入值一致
     * @param int    $userId   對應平面的使用者 id（admin=users.id、customer=customer_users.id）
     */
    public static function revokeAllForUser(string $userType, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        if (!self::shouldUseDbHandler()) {
            // 測試 / 安裝流程（無 DB sessions 表）為 no-op，與 revokeCurrent() 一致。
            return;
        }
        try {
            Database::getInstance()->execute(
                "UPDATE {prefix}sessions
                    SET revoked = 1, revoked_at = :t
                  WHERE user_type = :ut AND user_id = :uid AND revoked = 0",
                ['t' => time(), 'ut' => $userType, 'uid' => $userId]
            );
        } catch (\Throwable) {
            // 撤銷失敗不阻斷上層流程（帳號狀態變更/改密碼本身已落地）；
            // 仍有閒置/絕對逾時與 IP/UA 指紋作為縱深防線。
        }
    }

    /**
     * 撤銷某使用者所有 session，**並回報實際撤銷筆數；做不到就丟例外**。
     *
     * 【為何需要一個會失敗的版本】（複審 2026-08-17）
     * revokeAllForUser() 是 best-effort：它在 DB session handler 沒啟用時直接 return，
     * 出錯時也吞掉例外。這對「使用者從介面改密碼」是對的——撤銷失敗不該讓改密碼失敗。
     *
     * 但用在**憑證輪替**上，同樣的寬容就變成謊言：CLI 會照樣印出「所有 session 已撤銷」，
     * 而實際上一次 UPDATE 都沒跑。輪替的整個目的就是讓舊憑證與舊登入態失效，
     * 「以為撤銷了」比「知道沒撤銷」危險得多。
     *
     * 故此版本：撤銷不了就丟例外，撤銷了就回傳筆數供呼叫端印出來核對。
     *
     * @return int 實際被標記 revoked 的列數
     * @throws \RuntimeException sessions 表不存在（無法撤銷）
     */
    public static function revokeAllForUserOrFail(string $userType, int $userId): int
    {
        if ($userId <= 0) {
            throw new \RuntimeException('撤銷 session 需要有效的 user id。');
        }

        $db = Database::getInstance();

        // 直接驗證「撤銷的目標」存不存在，而不是問 shouldUseDbHandler()——
        // 後者回答的是「這次請求要不要用 DB session handler」，不是「我能不能撤銷」。
        try {
            $db->fetchColumn("SELECT COUNT(*) FROM {prefix}sessions");
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'sessions 資料表不可用，無法撤銷既存登入態：' . $e->getMessage(),
                0,
                $e
            );
        }

        return $db->execute(
            "UPDATE {prefix}sessions
                SET revoked = 1, revoked_at = :t
              WHERE user_type = :ut AND user_id = :uid AND revoked = 0",
            ['t' => time(), 'ut' => $userType, 'uid' => $userId]
        );
    }

    /**
     * 原子化單一 session 登入：以 GET_LOCK 序列化「撤銷舊 session → 建新 session」，
     * 防止兩個併發登入各自撤銷、各自建立，最後留下兩個有效 session。
     *
     * @throws \RuntimeException 撤銷失敗（呼叫端應中止登入流程）
     */
    public static function enforceExclusiveLogin(string $userType, int $userId, callable $establish): void
    {
        $db = Database::getInstance();
        $lockName = $db->namedLockName("single_session:{$userType}:{$userId}");

        $acquired = (int) $db->fetchColumn(
            'SELECT GET_LOCK(:name, :timeout)',
            ['name' => $lockName, 'timeout' => 5]
        ) === 1;

        if (!$acquired) {
            throw new \RuntimeException('無法取得單一登入鎖，請稍候再試。');
        }

        try {
            self::revokeAllForUserOrFail($userType, $userId);
            $establish();
        } finally {
            try {
                if ((int) $db->fetchColumn('SELECT RELEASE_LOCK(:name)', ['name' => $lockName]) !== 1) {
                    error_log('[single-session] Lock cleanup not confirmed; connection teardown will release ownership.');
                }
            } catch (\Throwable) {
                // The completed durable login is the outcome; cleanup cannot undo it.
                // PDO uses nonpersistent connections; request teardown releases the lock.
                error_log('[single-session] Lock cleanup failed; connection teardown will release ownership.');
            }
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        self::$started = false;
    }

    public static function all(): array
    {
        return $_SESSION ?? [];
    }
}
