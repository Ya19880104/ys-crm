<?php

declare(strict_types=1);

namespace YangSheep\CRM\Portal\Auth;

use YangSheep\CRM\Core\Database;

/**
 * 客戶 Portal 登入節流（Zero Trust：登入限流 per-IP + per-account）。
 *
 * 與管理員 LoginAttemptService「完全隔離」：本服務一律以 scope='customer' 寫入/查詢
 * {prefix}login_attempts，故客戶端的失敗嘗試不會污染管理員 IP 的鎖定計數（反之亦然）。
 *
 * 鎖定策略（雙重）：
 *   - per-IP：同 IP 在窗口內失敗 >= MAX_ATTEMPTS_IP → 鎖定（擋分散式撞庫/同源暴力）。
 *   - per-account：同 email 在窗口內失敗 >= MAX_ATTEMPTS_EMAIL → 鎖定（擋鎖定單一帳號的猜密碼）。
 * 任一條件成立即視為鎖定（deny-by-default）。
 */
class CustomerLoginAttemptService
{
    private Database $db;

    private const SCOPE = 'customer';

    /** 同 IP 窗口內最大失敗次數。 */
    private const MAX_ATTEMPTS_IP = 10;

    /** 同帳號（email）窗口內最大失敗次數。 */
    private const MAX_ATTEMPTS_EMAIL = 5;

    /** 鎖定時間窗口（分鐘）。 */
    private const LOCKOUT_MINUTES = 15;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 記錄一次登入嘗試。
     */
    public function record(string $ip, ?string $email, bool $success): void
    {
        $this->db->execute(
            "INSERT INTO {prefix}login_attempts (scope, ip_address, username, success, attempted_at)
             VALUES (:scope, :ip, :username, :success, NOW())",
            [
                'scope'    => self::SCOPE,
                'ip'       => $ip,
                'username' => self::canonicalIdentifier($email),
                'success'  => $success ? 1 : 0,
            ]
        );
    }

    /**
     * 是否已被鎖定（per-IP 或 per-account 任一達閾值）。
     */
    public function isLocked(?string $ip, ?string $email): bool
    {
        // per-IP 只在 resolver 證明這是可鑑別來源時啟用；帳號 rail 永遠保留。
        if ($ip !== null) {
            $ipCount = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM {prefix}login_attempts
                 WHERE scope = :scope AND ip_address = :ip AND success = 0
                   AND attempted_at >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)",
                ['scope' => self::SCOPE, 'ip' => $ip, 'minutes' => self::LOCKOUT_MINUTES]
            );
            if ($ipCount >= self::MAX_ATTEMPTS_IP) {
                return true;
            }
        }

        // per-account（email）：成功列是 checkpoint，不刪歷史。
        $email = self::canonicalIdentifier($email) ?? '';
        if ($email !== '') {
            $lastSuccessId = $this->db->fetchColumn(
                "SELECT MAX(id) FROM {prefix}login_attempts
                 WHERE scope = :scope AND username = :email AND success = 1
                   AND attempted_at >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)",
                ['scope' => self::SCOPE, 'email' => $email, 'minutes' => self::LOCKOUT_MINUTES]
            );

            $sql = "SELECT COUNT(*) FROM {prefix}login_attempts
                    WHERE scope = :scope AND username = :email AND success = 0
                      AND attempted_at >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)";
            $params = ['scope' => self::SCOPE, 'email' => $email, 'minutes' => self::LOCKOUT_MINUTES];
            if ($lastSuccessId !== null && $lastSuccessId !== '') {
                $sql .= ' AND id > :since_id';
                $params['since_id'] = (int) $lastSuccessId;
            }

            $emailCount = (int) $this->db->fetchColumn($sql, $params);
            if ($emailCount >= self::MAX_ATTEMPTS_EMAIL) {
                return true;
            }
        }

        return false;
    }

    /**
     * Portal email 一律大小寫不敏感；超過資料庫欄寬時使用固定長度雜湊，
     * 讓 record 與 lock 查詢共享同一個 key，且不依賴 MySQL 截斷行為。
     */
    private static function canonicalIdentifier(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($email));
        if (mb_strlen($normalized) <= 100) {
            return $normalized;
        }

        return 'sha256:' . hash('sha256', $normalized);
    }

}
