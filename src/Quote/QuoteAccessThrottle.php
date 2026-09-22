<?php

declare(strict_types=1);

namespace YangSheep\CRM\Quote;

use YangSheep\CRM\Core\Database;

/**
 * 公開報價存取節流（Zero Trust）。
 *
 * 防護：報價 token 一旦外洩（轉寄 / 瀏覽器歷史 / referer），攻擊者可對
 *   - 密碼保護報價 `/q/{token}/unlock`：無限猜 access_password；
 *   - `/q/{token}/sign`：濫發簽署。
 * 本服務以 DB 持久計數（重用 {prefix}login_attempts 表）限流，**以 scope 與管理員 /
 * 客戶登入節流完全隔離**（scope=quote_unlock / quote_sign），互不污染。
 *
 * key 粒度：scope + ip_address + username='q{quoteId}'（per-quote + per-ip），
 * 故對單一報價的暴力不會誤鎖同 IP 對其他報價的正常操作。
 */
class QuoteAccessThrottle
{
    /** 解鎖：窗口內失敗上限（達到即鎖）。 */
    public const UNLOCK_SCOPE   = 'quote_unlock';
    public const UNLOCK_MAX     = 8;
    public const UNLOCK_MINUTES = 15;
    public const UNLOCK_GLOBAL_MAX = 24;
    public const UNLOCK_QUOTE_MAX = 40;

    /** 簽署：窗口內提交上限（防濫發）。 */
    public const SIGN_SCOPE     = 'quote_sign';
    public const SIGN_MAX       = 20;
    public const SIGN_MINUTES   = 10;
    public const SIGN_GLOBAL_MAX = 60;
    public const SIGN_QUOTE_MAX = 100;

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 是否已被鎖定（窗口內失敗/提交次數達上限）。
     */
    public function isLocked(string $scope, string $ip, int $quoteId, int $max, int $minutes): bool
    {
        $count = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}login_attempts
             WHERE scope = :scope AND ip_address = :ip AND username = :u AND success = 0
               AND attempted_at >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)",
            ['scope' => $scope, 'ip' => $ip, 'u' => 'q' . $quoteId, 'minutes' => $minutes]
        );

        return $count >= $max;
    }

    /**
     * 同一可信來源跨 quote 的總量 rail。
     *
     * username 在既有表中保存 quote id；這條 query 刻意不使用它，才能防止
     * 以多張 quote 分散嘗試而逐張都停在 per-quote 上限以下。
     */
    public function isGlobalLocked(string $scope, string $ip, int $max, int $minutes): bool
    {
        $count = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}login_attempts
             WHERE scope = :scope AND ip_address = :ip AND success = 0
               AND attempted_at >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)",
            ['scope' => $scope, 'ip' => $ip, 'minutes' => $minutes]
        );

        return $count >= $max;
    }

    /**
     * 單張 quote 的 non-IP budget：即使來源不可信或輪替 IP，仍保留最後一道上限。
     * 成功列是單調 id checkpoint，避免成功後仍被較早的猜測紀錄鎖住。
     */
    public function isQuoteLocked(string $scope, int $quoteId, int $max, int $minutes): bool
    {
        $username = 'q' . $quoteId;
        $lastSuccessId = $this->db->fetchColumn(
            "SELECT MAX(id) FROM {prefix}login_attempts
             WHERE scope = :scope AND username = :u AND success = 1
               AND attempted_at >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)",
            ['scope' => $scope, 'u' => $username, 'minutes' => $minutes]
        );

        $sql = "SELECT COUNT(*) FROM {prefix}login_attempts
                WHERE scope = :scope AND username = :u AND success = 0
                  AND attempted_at >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)";
        $params = ['scope' => $scope, 'u' => $username, 'minutes' => $minutes];
        if ($lastSuccessId !== null && $lastSuccessId !== '') {
            $sql .= ' AND id > :since_id';
            $params['since_id'] = (int) $lastSuccessId;
        }

        return (int) $this->db->fetchColumn($sql, $params) >= $max;
    }

    /**
     * 記錄一次嘗試（success=false 計入限流；解鎖成功時以 true 記錄並由 clear() 清除）。
     */
    public function record(string $scope, string $ip, int $quoteId, bool $success): void
    {
        $this->db->execute(
            "INSERT INTO {prefix}login_attempts (scope, ip_address, username, success, attempted_at)
             VALUES (:scope, :ip, :u, :success, NOW())",
            ['scope' => $scope, 'ip' => $ip, 'u' => 'q' . $quoteId, 'success' => $success ? 1 : 0]
        );
    }

    /**
     * 清除某報價在某 IP 的失敗紀錄（解鎖成功後呼叫）。
     */
    public function clear(string $scope, string $ip, int $quoteId): void
    {
        $this->db->execute(
            "DELETE FROM {prefix}login_attempts
             WHERE scope = :scope AND ip_address = :ip AND username = :u",
            ['scope' => $scope, 'ip' => $ip, 'u' => 'q' . $quoteId]
        );
    }
}
