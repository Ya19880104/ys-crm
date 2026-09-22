<?php

declare(strict_types=1);

namespace YangSheep\CRM\Core;

/**
 * DB-backed 短期快取（取代 WordPress transient）。
 *
 * 為何用 DB 而非檔案／APCu：本系統可能多 process、多機執行，出網限流計數與熔斷狀態
 * 必須跨 process 共享，DB 是既有且唯一的共享狀態來源。
 *
 * 讀取一律過濾 expires_at > NOW()，因此「過期資料尚未被清除」不影響正確性；
 * purgeExpired() 只是空間回收。
 */
class Cache
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 讀取未過期的值；不存在或已過期回 null。
     *
     * 注意 null 與空字串的差異：呼叫端若要區分「沒設過」與「設為空」，
     * 請勿用 ?: 之類的寫法把兩者混為一談。
     */
    public function get(string $key): ?string
    {
        $value = $this->db->fetchColumn(
            "SELECT cache_value FROM {prefix}cache WHERE cache_key = :k AND expires_at > NOW()",
            ['k' => $key]
        );

        return $value === false || $value === null ? null : (string) $value;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $this->db->execute(
            "INSERT INTO {prefix}cache (cache_key, cache_value, expires_at)
             VALUES (:k, :v, DATE_ADD(NOW(), INTERVAL :ttl SECOND))
             ON DUPLICATE KEY UPDATE
                cache_value = VALUES(cache_value),
                expires_at  = VALUES(expires_at)",
            ['k' => $key, 'v' => $value, 'ttl' => max(1, $ttlSeconds)]
        );
    }

    public function delete(string $key): void
    {
        $this->db->execute("DELETE FROM {prefix}cache WHERE cache_key = :k", ['k' => $key]);
    }

    /**
     * 計數器遞增，回傳遞增後的值。過期時自動歸零重新計時。
     *
     * 【精確度說明】遞增與讀回是兩道語句，高並發下可能少算一兩次。這對「出網限流」
     * 是可接受的——它是保護對方系統的禮貌措施，不是安全邊界。
     * 若日後拿它做安全用途（例如登入嘗試上限），必須改為單語句原子操作。
     */
    public function increment(string $key, int $ttlSeconds): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}cache (cache_key, cache_value, expires_at)
             VALUES (:k, '1', DATE_ADD(NOW(), INTERVAL :ttl SECOND))
             ON DUPLICATE KEY UPDATE
                cache_value = IF(expires_at <= NOW(), '1',
                                 CAST(CAST(cache_value AS UNSIGNED) + 1 AS CHAR)),
                expires_at  = IF(expires_at <= NOW(), VALUES(expires_at), expires_at)",
            ['k' => $key, 'ttl' => max(1, $ttlSeconds)]
        );

        return (int) $this->get($key);
    }

    /**
     * 清除過期列（cron 呼叫，純空間回收）。
     */
    public function purgeExpired(): int
    {
        return $this->db->execute("DELETE FROM {prefix}cache WHERE expires_at <= NOW()");
    }
}
