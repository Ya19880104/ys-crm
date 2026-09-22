<?php

declare(strict_types=1);

namespace YangSheep\CRM\Quote;

use YangSheep\CRM\Core\Database;

/**
 * 報價單明細資料存取層。
 * 提供依報價單查詢、批次替換（重建明細）、刪除。
 * 金額（amount）由 Service 伺服器端算妥後傳入；本層僅負責持久化。
 * 全程 prepared statement，{prefix} 佔位符 + 參數綁定。
 *
 * 對應架構設計 §5.5 quote_items。
 */
class QuoteItemRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 取得某報價單的所有明細（依 sort 排序）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByQuote(int $quoteId): array
    {
        return $this->db->fetchAll(
            "SELECT id, quote_id, name, description, qty, unit, unit_price, amount, sort
             FROM {prefix}quote_items
             WHERE quote_id = :quote_id
             ORDER BY sort ASC, id ASC",
            ['quote_id' => $quoteId]
        );
    }

    /**
     * 新增單筆明細。
     *
     * @return int 新明細 ID
     */
    public function insert(int $quoteId, array $item): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}quote_items
                (quote_id, name, description, qty, unit, unit_price, amount, sort)
             VALUES
                (:quote_id, :name, :description, :qty, :unit, :unit_price, :amount, :sort)",
            [
                'quote_id'    => $quoteId,
                'name'        => $item['name'],
                'description' => ($item['description'] ?? '') !== '' ? $item['description'] : null,
                'qty'         => $item['qty'] ?? 0,
                'unit'        => (string) ($item['unit'] ?? ''),
                'unit_price'  => $item['unit_price'] ?? 0,
                'amount'      => $item['amount'] ?? 0,
                'sort'        => (int) ($item['sort'] ?? 0),
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * 刪除某報價單的全部明細（重建明細前呼叫）。
     *
     * @return int 影響筆數
     */
    public function deleteByQuote(int $quoteId): int
    {
        return $this->db->execute(
            "DELETE FROM {prefix}quote_items WHERE quote_id = :quote_id",
            ['quote_id' => $quoteId]
        );
    }

    /**
     * 以新明細整批替換某報價單的明細（先刪後插）。
     * 須由呼叫端包在交易內（QuoteService::saveQuote 已處理）。
     *
     * @param array<int, array<string, mixed>> $items 已算妥 amount 與 sort 的明細
     */
    public function replaceForQuote(int $quoteId, array $items): void
    {
        $this->deleteByQuote($quoteId);
        foreach ($items as $item) {
            $this->insert($quoteId, $item);
        }
    }
}
