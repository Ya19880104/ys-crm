<?php

declare(strict_types=1);

namespace YangSheep\CRM\Card;

use YangSheep\CRM\Core\Database;

class CardCommentRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 取得卡片的所有留言（含使用者名稱）
     */
    public function findByCardId(int $cardId): array
    {
        return $this->db->fetchAll(
            "SELECT cc.*, u.display_name AS user_name
             FROM {prefix}card_comments cc
             LEFT JOIN {prefix}users u ON cc.user_id = u.id
             WHERE cc.card_id = ?
             ORDER BY cc.noted_at DESC, cc.id DESC",
            [$cardId]
        );
    }

    /**
     * 依 ID 取得留言
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}card_comments WHERE id = ? LIMIT 1",
            [$id]
        );
    }

    /**
     * 新增留言
     */
    public function create(array $data): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}card_comments
             (card_id, user_id, content, image_path, media_type, noted_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $data['card_id'],
                $data['user_id'],
                $data['content'],
                $data['image_path'] ?? null,
                $data['media_type'] ?? null,
                $data['noted_at'] ?? date('Y-m-d H:i:s'),
            ]
        );
        return (int) $this->db->lastInsertId();
    }

    /**
     * 刪除留言
     */
    public function delete(int $id): void
    {
        $this->db->execute(
            "DELETE FROM {prefix}card_comments WHERE id = ?",
            [$id]
        );
    }
}
