<?php

declare(strict_types=1);

namespace YangSheep\CRM\Quote;

use YangSheep\CRM\Core\Database;

/**
 * 報價單瀏覽軌跡資料存取層。
 * 公開頁每次成功檢視記一筆；後台檢視頁列出軌跡。
 * 全程 prepared statement，{prefix} 佔位符 + 參數綁定。
 *
 * 對應架構設計 §5.5 quote_views、§7.8。
 */
class QuoteViewRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 記錄一次瀏覽。
     *
     * @param int      $quoteId
     * @param string   $ip
     * @param string   $userAgent       原始 UA（本層截斷至 500 字防爆量）
     * @param int|null $customerUserId  已登入客戶 id（portal；本階段一律 null）
     */
    public function record(int $quoteId, string $ip, string $userAgent, ?int $customerUserId = null): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}quote_views
                (quote_id, viewed_at, ip, user_agent, customer_user_id)
             VALUES
                (:quote_id, NOW(), :ip, :ua, :cuid)",
            [
                'quote_id' => $quoteId,
                'ip'       => mb_substr($ip, 0, 45),
                'ua'       => mb_substr($userAgent, 0, 500),
                'cuid'     => $customerUserId,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * 取得某報價單的瀏覽軌跡（新到舊，預設上限 100 筆）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByQuote(int $quoteId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->fetchAll(
            "SELECT id, quote_id, viewed_at, ip, user_agent, customer_user_id
             FROM {prefix}quote_views
             WHERE quote_id = :quote_id
             ORDER BY viewed_at DESC, id DESC
             LIMIT {$limit}",
            ['quote_id' => $quoteId]
        );
    }

    /**
     * 某報價單的瀏覽次數。
     */
    public function countByQuote(int $quoteId): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}quote_views WHERE quote_id = :quote_id",
            ['quote_id' => $quoteId]
        );
    }
}
