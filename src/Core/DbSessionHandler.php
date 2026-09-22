<?php

declare(strict_types=1);

namespace YangSheep\CRM\Core;

/**
 * Zero Trust DB-backed Session Handler
 *
 * 將 session 存於 {prefix}sessions 資料表，達成：
 * - 伺服器端撤銷：read() 檢查 revoked 旗標（管理員可 UPDATE revoked=1 立即踢出）
 * - 閒置逾時：last_activity 超過 idleTimeout 即視為失效
 * - 絕對逾時：created_at 超過 absoluteTimeout 即視為失效（登入後重設 created_at）
 *
 * 逾時/撤銷一律在「每次請求」的 read() 強制檢查（過期回傳空字串 = 等同登出），
 * 符合 Zero Trust「永不預設信任、每次都驗證」。
 *
 * 注意：本 handler 僅管理 payload 與時間戳。user/ip/ua 綁定欄位由
 * Session::bindAuth()（登入時）寫入，IP/UA 指紋比對由 Session::enforceBinding() 執行。
 */
final class DbSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    private ?Database $db = null;

    public function __construct(
        private int $idleTimeout = 1800,      // 閒置逾時（秒），預設 30 分鐘
        private int $absoluteTimeout = 28800  // 絕對逾時（秒），預設 8 小時
    ) {
    }

    private function db(): Database
    {
        return $this->db ??= Database::getInstance();
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $row = $this->db()->fetch(
            "SELECT payload, created_at, last_activity, revoked
             FROM {prefix}sessions WHERE id = :id LIMIT 1",
            ['id' => $id]
        );

        if ($row === null || $this->isUnusable($row)) {
            return '';
        }

        return (string) $row['payload'];
    }

    /**
     * 這一列還能不能當成有效的 session 使用？
     *
     * 撤銷、閒置逾時、絕對逾時三種情況都算不能用。read() 與 validateId()
     * **必須用同一份判斷** —— 兩者不一致正是下面那個死鎖的成因。
     *
     * @param array<string, mixed> $row 需含 revoked / last_activity / created_at
     */
    private function isUnusable(array $row): bool
    {
        $now = time();

        if ((int) ($row['revoked'] ?? 0) === 1) {
            return true;
        }
        if ($this->idleTimeout > 0 && ($now - (int) $row['last_activity']) > $this->idleTimeout) {
            return true;
        }
        if ($this->absoluteTimeout > 0 && ($now - (int) $row['created_at']) > $this->absoluteTimeout) {
            return true;
        }

        return false;
    }

    public function write(string $id, string $data): bool
    {
        $now = time();
        $this->db()->execute(
            "INSERT INTO {prefix}sessions (id, payload, created_at, last_activity)
             VALUES (:id, :payload, :ca, :la)
             ON DUPLICATE KEY UPDATE payload = :payload_u, last_activity = :la_u",
            [
                'id'        => $id,
                'payload'   => $data,
                'ca'        => $now,
                'la'        => $now,
                'payload_u' => $data,
                'la_u'      => $now,
            ]
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->db()->execute("DELETE FROM {prefix}sessions WHERE id = :id", ['id' => $id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $now = time();
        $cut = $now - max($this->idleTimeout, $max_lifetime);
        return $this->db()->execute(
            "DELETE FROM {prefix}sessions
             WHERE last_activity < :cut
                OR (revoked = 1 AND revoked_at IS NOT NULL AND revoked_at < :cut2)",
            ['cut' => $cut, 'cut2' => $now - 86400]
        );
    }

    /**
     * use_strict_mode：只接受「現在真的還能用」的 session id。
     *
     * 🔴🔴 【為何不能只檢查「這列存在嗎」】（2026-09-02 實機重現）
     *
     * 原本這裡只問 `SELECT 1 ... WHERE id = :id`。只要那一列還在，PHP 就認定
     * 這個 id 有效、**不會換新的**。但 read() 會因為 revoked／逾時而回傳空字串，
     * 於是同一個請求裡：
     *
     *     validateId() → true   （列還在，沿用舊 id）
     *     read()       → ''     （已撤銷，session 是空的）
     *     登入頁產生新的 CSRF token 存進 session
     *     write()      → 更新 payload 與 last_activity，**但不會清掉 revoked**
     *     使用者送出表單 → read() 又回 '' → token 不見了 → CSRF 驗證失敗 403
     *
     * 重整沒有用，因為 cookie 裡的 id 永遠不變、那一列也永遠是 revoked。
     * 使用者被**永久鎖在登入頁外**，除非自己清 cookie。
     *
     * 這條路徑有兩個入口，都會踩到：
     *   1. 改密碼／輪替憑證／停用帳號 —— 任何呼叫 revokeAllForUser*() 的地方
     *   2. **絕對逾時**（預設 8 小時）—— write() 的 ON DUPLICATE KEY UPDATE
     *      不會更新 created_at，所以逾時的列跟被撤銷的列一樣是永久不可用。
     *      也就是說這個死鎖不需要有人改密碼，每個掛著分頁超過 8 小時的人都會中。
     *
     * 正解是讓「不可用的 id」在 use_strict_mode 下被判為無效，PHP 就會發一個
     * 全新的 id，使用者拿到乾淨的 session。舊列留給 gc() 清理。
     *
     * ⚠️ 千萬不要改成「write() 時把 revoked 清掉」來解 —— 那等於讓被撤銷的
     * session 自己復活，撤銷就形同虛設。
     */
    public function validateId(string $id): bool
    {
        $row = $this->db()->fetch(
            "SELECT created_at, last_activity, revoked
             FROM {prefix}sessions WHERE id = :id LIMIT 1",
            ['id' => $id]
        );

        return $row !== null && !$this->isUnusable($row);
    }

    /**
     * lazy_write：資料未變更時只更新 last_activity（維持閒置計時正確）
     */
    public function updateTimestamp(string $id, string $data): bool
    {
        $this->db()->execute(
            "UPDATE {prefix}sessions SET last_activity = :now WHERE id = :id",
            ['now' => time(), 'id' => $id]
        );
        return true;
    }
}
