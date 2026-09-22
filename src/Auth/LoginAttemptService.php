<?php

declare(strict_types=1);

namespace YangSheep\CRM\Auth;

use YangSheep\CRM\Core\Database;

/**
 * 登入失敗計數與鎖定。
 *
 * 🔴🔴 【為何不能只以 IP 為 key】（2026-09-02 實測）
 *
 * 部署在反向代理後面時，所有請求的 REMOTE_ADDR 可能都是同一個代理位址
 *（參考部署實測：三個月、26 筆登入紀錄的 ip_address 完全相同）。以 IP 為唯一 key 有兩個後果：
 *
 *   1. **鎖定是全域的**：任何人失敗 5 次，所有使用者一起被鎖 15 分鐘。
 *      一個人（或一支壞掉的腳本）就能讓全公司登不進來。
 *   2. **成功登入會清掉所有人的失敗計數**（原本的 clearAttempts 是
 *      `DELETE WHERE ip_address = :ip`）。攻擊者只要有一個能用的低權限帳號：
 *      試 4 次密碼 → 用自己的帳號登入一次清空計數 → 無限重複。
 *      **暴力破解防護實質失效。**
 *
 * 修正後改為雙軌：
 *   - **識別字軌**（主）：同一個帳號／Email 在時間窗內失敗 N 次即鎖定該識別字。
 *     這是真正對應「有人在猜這個帳號的密碼」的訊號，且不受 IP 是否可辨識影響。
 *   - **來源軌**（次）：同一個來源 IP 在時間窗內失敗次數超過較寬鬆的上限即鎖定。
 *     在拿得到真實 IP 之後才有鑑別力；拿不到時上限放寬，避免變成全域鎖。
 *
 * 清除失敗計數時只清「該識別字」的，不再一次清光整個 IP。
 *
 * ⚠️ 以識別字為 key 的代價：有人可以刻意連續輸錯某個帳號來鎖住那個人（針對性 DoS）。
 * 這是所有帳號鎖定機制共有的取捨；相較於「防護整個失效」，這個代價可以接受。
 * 受影響者可用另一個識別字（帳號 ↔ Email）或等時間窗過去。
 */
class LoginAttemptService
{
    /**
     * 認證平面。
     *
     * 🔴🔴 【為何每一個查詢都必須帶】`login_attempts` 是**三個認證平面共用**的表：
     * 本服務（管理後台）、CustomerLoginAttemptService（客戶 Portal，scope='customer'）、
     * QuoteAccessThrottle（報價單密碼／簽署）。migration 044 新增這個欄位的唯一目的
     * 就是把它們隔開，該檔註解寫得很清楚：「管理員以 scope='admin' 計數」。
     *
     * 少了這個條件的實際後果（2026-09-02 review 實測）：未認證的攻擊者對公開的
     * /portal/login 連送 5 次某位管理員 Email 的錯誤密碼，就能讓那位管理員在
     * /login 被鎖 15 分鐘 —— 而管理端一次失敗都沒發生過，後台完全看不出原因。
     * 反向也成立：管理員成功登入會清掉客戶 Portal 對同一 Email 的鎖定計數。
     */
    private const SCOPE = 'admin';

    /** 同一識別字在時間窗內允許的最大失敗次數 */
    private const MAX_ATTEMPTS_PER_IDENTIFIER = 5;

    /**
     * 同一來源 IP 在時間窗內允許的最大失敗次數。
     *
     * 刻意比識別字軌寬鬆很多：在還拿不到真實 client IP 的部署下（反向代理後方、尚未設定訪客 IP 偵測時），
     * 這個值等於「全站共用」，設太緊就會變成任何人都能把大家鎖住的 DoS 開關。
     * 等前端設備開始寫入真實 IP、並在系統設定選好偵測方式之後，可以再調嚴。
     */
    private const MAX_ATTEMPTS_PER_IP = 50;

    /** 時間窗（分鐘） */
    private const LOCKOUT_MINUTES = 15;

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 在 MySQL advisory lock 保護下執行節流敏感的動作。
     *
     * 🔴🔴 【為何需要序列化】isLocked() 與 record() 是分離的 SELECT+INSERT，
     * 並行請求能在首筆 failure 可見前全部通過 isLocked(false)，繞過失敗次數上限。
     * 例：2FA challenge 允許 5 次失敗，但 6 個同時送達的請求各自看到 count=0、
     * 全部進入 TOTP verifier —— 攻擊者一波就得到 6 次猜測而非 5 次。
     *
     * 此方法以 GET_LOCK 序列化同一個 $identifier 的存取：在鎖內執行 $callback，
     * callback 應包含 isLocked() + 業務邏輯 + record()。鎖保證同一時間只有
     * 一個請求在跑這段流程，消除 TOCTOU 窗口。
     *
     * @template T
     * @param string   $identifier 節流識別字（如 '2fa:uid:42'）
     * @param callable(): T $callback  在鎖內執行的閉包
     * @param int      $timeout    取鎖逾時秒數（0=不等待）
     * @return T callback 的回傳值
     * @throws \RuntimeException 取鎖失敗（逾時或被殺）
     */
    public function withThrottleLock(string $identifier, callable $callback, int $timeout = 5): mixed
    {
        $lockName = $this->db->namedLockName('throttle:' . self::canonicalIdentifier($identifier));

        $acquired = (int) $this->db->fetchColumn(
            "SELECT GET_LOCK(:name, :timeout)",
            ['name' => $lockName, 'timeout' => $timeout]
        );

        if ($acquired !== 1) {
            throw new \RuntimeException('無法取得節流鎖，請稍後重試。');
        }

        try {
            return $callback();
        } finally {
            $this->db->fetchColumn(
                "SELECT RELEASE_LOCK(:name)",
                ['name' => $lockName]
            );
        }
    }

    /**
     * 記錄登入嘗試。
     *
     * @param string      $ip         來源位址（拿不到真實 client IP 時會是 proxy 位址）
     * @param string|null $identifier 使用者輸入的帳號或 Email
     */
    public function record(string $ip, ?string $identifier, bool $success): void
    {
        $this->db->execute(
            "INSERT INTO {prefix}login_attempts (scope, ip_address, username, success, attempted_at)
             VALUES (:scope, :ip, :username, :success, NOW())",
            [
                'scope'    => self::SCOPE,
                'ip'       => $ip,
                'username' => self::canonicalIdentifier($identifier),
                'success'  => $success ? 1 : 0,
            ]
        );
    }

    /**
     * 是否應該擋下這次登入嘗試。
     *
     * @param string|null $identifier 本次輸入的帳號／Email；null 時只檢查來源軌
     */
    public function isLocked(?string $ip, ?string $identifier = null): bool
    {
        $key = self::canonicalIdentifier($identifier);
        if ($key !== null && $key !== '') {

            // 🔴 【為何是「自上次成功以來」而不是「刪掉舊紀錄」】
            // 原本的解法是登入成功時 DELETE 掉失敗列。那有三個各自獨立的問題：
            //   1. DELETE 沒有 scope 條件，會跨平面刪掉客戶 Portal 的鎖定計數
            //      —— 內部使用者只要把自己的 Email 改成客戶的 Portal 信箱，
            //      就能無限次重設該客戶帳號的暴力破解防護。
            //   2. 它同時刪掉了本輪新增的「我的登入紀錄」與稽核登入頁簽要顯示的資料。
            //      那個面板寫著「看到不是自己的登入嘗試，請立即更改密碼」，
            //      但唯一進得去該頁的方法是登入，而登入就是抹除證據的動作 ——
            //      結構上它永遠不可能顯示出失敗紀錄。
            //   3. 刪除是不可逆的；一旦判斷錯了，資料回不來。
            //
            // 改成以「最後一次成功」當分界，三個問題一起消失：不刪任何東西、
            // 天然只影響自己這個 scope、而且歷史完整保留。
            // 🔴 【為何以 id 為分界，不是 attempted_at】
            // attempted_at 只有秒解析度。成功登入與緊接著的失敗常常落在同一秒，
            // 用 `attempted_at > :since` 會把那些失敗全部排除掉（實測：連續 5 次
            // 失敗在同一秒內完成時完全不會鎖）。id 是單調遞增的主鍵，
            // 天然表達「這件事發生在那件事之後」，而且沒有時區與精度問題。
            $lastSuccessId = $this->db->fetchColumn(
                "SELECT MAX(id) FROM {prefix}login_attempts
                 WHERE scope = :scope
                   AND username = :username
                   AND success = 1
                   AND attempted_at >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)",
                ['scope' => self::SCOPE, 'username' => $key, 'minutes' => self::LOCKOUT_MINUTES]
            );

            $sql = "SELECT COUNT(*) FROM {prefix}login_attempts
                    WHERE scope = :scope
                      AND username = :username
                      AND success = 0
                      AND attempted_at >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)";
            $params = ['scope' => self::SCOPE, 'username' => $key, 'minutes' => self::LOCKOUT_MINUTES];

            if ($lastSuccessId !== null && $lastSuccessId !== '') {
                $sql .= ' AND id > :since_id';
                $params['since_id'] = (int) $lastSuccessId;
            }

            if ((int) $this->db->fetchColumn($sql, $params) >= self::MAX_ATTEMPTS_PER_IDENTIFIER) {
                return true;
            }
        }

        // ── 來源軌 ──
        //
        // 🔴 【為何要先問「這個 IP 分得出人嗎」】部署在反向代理後面時，
        // REMOTE_ADDR 恆為同一個代理位址。在那種情況下 per-IP 鎖定不是防護，
        // 是一個任何人都能按下去的全域開關：未認證的攻擊者送滿上限次失敗，
        // 全公司就登不進來，而且沒有任何路徑能把它清掉（成功登入只重設識別字軌）。
        //
        // per-IP 限流的前提是「IP 能代表一個來源」。當它是內網／保留位址時，
        // 那個前提不成立，此時硬鎖只會傷到自己人。識別字軌仍然逐帳號保護。
        // 等前端設備開始插入真實 client IP（設定頁的診斷表可確認），這一軌自動恢復作用。
        if ($ip === null || !self::ipIsDiscriminating($ip)) {
            return false;
        }

        $byIp = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}login_attempts
             WHERE scope = :scope
               AND ip_address = :ip
               AND success = 0
               AND attempted_at >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)",
            ['scope' => self::SCOPE, 'ip' => $ip, 'minutes' => self::LOCKOUT_MINUTES]
        );

        return $byIp >= self::MAX_ATTEMPTS_PER_IP;
    }

    /**
     * 這個位址是否足以代表「一個來源」。
     *
     * 內網／保留位址在這個位置代表的是代理本身，不是訪客 —— 以它為 key 的限流
     * 分不出攻擊者與正常使用者，只會變成全域鎖。
     */
    public static function ipIsDiscriminating(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * 登入紀錄（供後台檢視）。
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function history(int $page = 1, int $perPage = 30, array $filters = []): array
    {
        // scope 不是可選篩選條件，是這張共用表的分割鍵：不帶它，
        // 後台的登入紀錄會混進客戶 Portal 與報價單密碼頁的嘗試。
        $where  = ['scope = :scope'];
        $params = ['scope' => self::SCOPE];

        $result = trim((string) ($filters['result'] ?? ''));
        if ($result === 'success') {
            $where[] = 'success = 1';
        } elseif ($result === 'failed') {
            $where[] = 'success = 0';
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $where[]           = '(username LIKE :kw OR ip_address LIKE :kw2)';
            $canonicalKeyword  = self::canonicalIdentifier($keyword) ?? '';
            $params['kw']      = $canonicalKeyword !== $keyword ? $canonicalKeyword : '%' . $keyword . '%';
            $params['kw2']     = '%' . $keyword . '%';
        }

        $clause = 'WHERE ' . implode(' AND ', $where);   // $where 至少含 scope，必定非空

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}login_attempts {$clause}",
            $params
        );

        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);

        $items = $this->db->fetchAll(
            "SELECT id, ip_address, username, success, attempted_at
             FROM {prefix}login_attempts
             {$clause}
             ORDER BY attempted_at DESC, id DESC
             LIMIT :limit OFFSET :offset",
            $params + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage]
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * 某個人自己的登入紀錄。
     *
     * 【為何要傳多個識別字】login_attempts.username 記的是**當時輸入了什麼**。
     * 同一個人可能用帳號登入、也可能用 Email 登入（兩者都支援），
     * 只比對其中一個會漏掉一半的紀錄 —— 而「漏掉一半」在安全檢視上等於沒有用。
     *
     * @param list<string> $identifiers 這個人所有可能的登入識別字（帳號、Email）
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function historyForIdentifiers(array $identifiers, int $page = 1, int $perPage = 20): array
    {
        $identifiers = array_values(array_unique(array_filter(
            array_map(static fn(string $v): string => self::canonicalIdentifier($v) ?? '', $identifiers),
            static fn(string $v): bool => $v !== ''
        )));
        if ($identifiers === []) {
            return ['items' => [], 'total' => 0];
        }

        $placeholders = [];
        $params       = ['scope' => self::SCOPE];
        foreach ($identifiers as $i => $v) {
            $key                = 'id' . $i;
            $placeholders[]     = ':' . $key;
            $params[$key]       = $v;
        }
        $in = implode(', ', $placeholders);

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}login_attempts WHERE scope = :scope AND username IN ({$in})",
            $params
        );

        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);

        $items = $this->db->fetchAll(
            "SELECT id, ip_address, username, success, attempted_at
             FROM {prefix}login_attempts
             WHERE scope = :scope AND username IN ({$in})
             ORDER BY attempted_at DESC, id DESC
             LIMIT :limit OFFSET :offset",
            $params + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage]
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * 保留天數之外的紀錄清除（供 cron）。登入紀錄同時是安全稽核來源，
     * 預設保留 90 天，與 invoice_api_logs 一致。
     */
    public function purgeOlderThan(int $days = 90): int
    {
        // 只清自己這個 scope：這張表由三個認證平面共用，
        // 各自的保留策略應該各自決定，不該由先跑到的那個一起清掉。
        return $this->db->execute(
            "DELETE FROM {prefix}login_attempts
             WHERE scope = :scope
               AND attempted_at < DATE_SUB(NOW(), INTERVAL :days DAY)",
            ['scope' => self::SCOPE, 'days' => max(1, $days)]
        );
    }

    /**
     * Shared-table retention for the cron owner. Unlike purgeOlderThan(), this
     * deliberately covers admin, customer, and quote scopes in one age-bound
     * statement so no authentication plane grows without limit.
     */
    public function purgeAllOlderThan(int $days = 90): int
    {
        return $this->db->execute(
            "DELETE FROM {prefix}login_attempts
             WHERE attempted_at < DATE_SUB(NOW(), INTERVAL :days DAY)",
            ['days' => max(1, $days)]
        );
    }

    /**
     * login_attempts.username 是 VARCHAR(100)。短識別字保留原本的可讀值；
     * 超長值改用固定長度、大小寫不敏感的雜湊，避免截斷造成共同前綴碰撞。
     */
    private static function canonicalIdentifier(?string $identifier): ?string
    {
        if ($identifier === null) {
            return null;
        }

        // Authentication is case-insensitive in production (username uses a
        // *_unicode_ci collation; email lookup calls LOWER()). The throttle key
        // must use the same equivalence relation or casing rotation bypasses the
        // account rail whenever no trustworthy IP rail is available.
        $normalized = mb_strtolower(trim($identifier));
        if (mb_strlen($normalized) <= 100) {
            return $normalized;
        }

        return 'sha256:' . hash('sha256', mb_strtolower($normalized));
    }
}
