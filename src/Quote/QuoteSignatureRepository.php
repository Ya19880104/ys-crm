<?php

declare(strict_types=1);

namespace YangSheep\CRM\Quote;

use YangSheep\CRM\Core\Database;

/**
 * 報價單簽署存證資料存取層。
 * 簽署當下寫入簽署人、簽名圖、印章快照、內容雜湊與來源。
 * 全程 prepared statement，{prefix} 佔位符 + 參數綁定。
 *
 * 對應架構設計 §5.5 quote_signatures、§7.8。
 */
class QuoteSignatureRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 新增一筆簽署存證。
     *
     * @return int 新簽署 ID
     */
    public function insert(array $data): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}quote_signatures
                (quote_id, signer_name, signer_type, signature_image_path,
                 our_seal_path, document_hash, signed_at, signed_ip, user_agent)
             VALUES
                (:quote_id, :signer_name, :signer_type, :signature_image_path,
                 :our_seal_path, :document_hash, NOW(), :signed_ip, :user_agent)",
            [
                'quote_id'             => $data['quote_id'],
                'signer_name'          => $data['signer_name'],
                'signer_type'          => $data['signer_type'] ?? 'guest',
                'signature_image_path' => $data['signature_image_path'] ?? '',
                'our_seal_path'        => $data['our_seal_path'] ?? null,
                'document_hash'        => $data['document_hash'] ?? '',
                'signed_ip'            => mb_substr((string) ($data['signed_ip'] ?? ''), 0, 45),
                'user_agent'           => mb_substr((string) ($data['user_agent'] ?? ''), 0, 500),
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * 取得某報價單的全部簽署紀錄（新到舊）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByQuote(int $quoteId): array
    {
        return $this->db->fetchAll(
            "SELECT id, quote_id, signer_name, signer_type, signature_image_path,
                    our_seal_path, document_hash, signed_at, signed_ip, user_agent
             FROM {prefix}quote_signatures
             WHERE quote_id = :quote_id
             ORDER BY signed_at DESC, id DESC",
            ['quote_id' => $quoteId]
        );
    }

    /**
     * 某報價單的簽署筆數。
     */
    public function countByQuote(int $quoteId): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}quote_signatures WHERE quote_id = :quote_id",
            ['quote_id' => $quoteId]
        );
    }
}
