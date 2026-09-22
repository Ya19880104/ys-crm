<?php

declare(strict_types=1);

namespace YangSheep\CRM\Card;

use YangSheep\CRM\Core\Database;

class CardLogService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 記錄卡片歷程
     */
    public function log(
        int $cardId,
        int $userId,
        string $actionType,
        ?string $fieldName = null,
        ?string $oldValue = null,
        ?string $newValue = null
    ): void {
        $this->db->execute(
            "INSERT INTO {prefix}card_logs
             (card_id, user_id, action_type, field_name, old_value, new_value, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [$cardId, $userId, $actionType, $fieldName, $oldValue, $newValue]
        );
    }

    /**
     * 取得卡片歷程
     */
    public function getCardLogs(int $cardId): array
    {
        return $this->db->fetchAll(
            "SELECT cl.*, u.display_name AS user_name
             FROM {prefix}card_logs cl
             LEFT JOIN {prefix}users u ON cl.user_id = u.id
             WHERE cl.card_id = ?
             ORDER BY cl.created_at DESC",
            [$cardId]
        );
    }
}
