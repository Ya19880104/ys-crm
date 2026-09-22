<?php

declare(strict_types=1);

namespace YangSheep\CRM\Notification;

use YangSheep\CRM\Core\Database;

/**
 * 通知資料存取層（對應架構設計 §5.7 notifications、§7.10）。
 *
 * 提供：insert、冪等去重查詢（同 type + related + 期間內是否已有）、分頁列表、標記已讀。
 * 全程 prepared statement，{prefix} 佔位符 + 參數綁定。
 */
class NotificationRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 新增一筆通知。
     *
     * @param array<string, mixed> $data
     * @return int 新通知 ID
     */
    public function insert(array $data): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}notifications
                (recipient_type, recipient_id, type, title, body, channel,
                 related_type, related_id, is_read, scheduled_at, sent_at, created_at)
             VALUES
                (:recipient_type, :recipient_id, :type, :title, :body, :channel,
                 :related_type, :related_id, :is_read, :scheduled_at, :sent_at, NOW())",
            [
                'recipient_type' => $data['recipient_type'] ?? 'admin',
                'recipient_id'   => (int) ($data['recipient_id'] ?? 0),
                'type'           => $data['type'],
                'title'          => $data['title'],
                'body'           => ($data['body'] ?? '') !== '' ? $data['body'] : null,
                'channel'        => $data['channel'] ?? 'in_app',
                'related_type'   => $data['related_type'] ?? null,
                'related_id'     => $data['related_id'] ?? null,
                'is_read'        => (int) ($data['is_read'] ?? 0),
                'scheduled_at'   => $data['scheduled_at'] ?? null,
                'sent_at'        => $data['sent_at'] ?? null,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * 冪等去重：判斷指定 (type, related_type, related_id) 在 $since 之後是否已存在通知。
     *
     * 用於 cron 每日掃描時避免重複塞同一筆提醒（例如同一張主機「即將到期」每天都掃到，
     * 但同一週期內只通知一次）。$since 由呼叫端依語意決定（如 7 天前、本期起始日）。
     *
     * @param string      $type
     * @param string|null $relatedType
     * @param int|null    $relatedId
     * @param string      $since  DATETIME 字串（created_at >= $since 視為「本期已通知」）
     */
    public function existsSince(string $type, ?string $relatedType, ?int $relatedId, string $since): bool
    {
        $sql = "SELECT COUNT(*) FROM {prefix}notifications
                WHERE type = :type
                  AND created_at >= :since";
        $params = ['type' => $type, 'since' => $since];

        // related_type / related_id 可能為 NULL，需用 IS NULL 比對（=:x 對 NULL 永遠 false）。
        if ($relatedType === null) {
            $sql .= ' AND related_type IS NULL';
        } else {
            $sql .= ' AND related_type = :rtype';
            $params['rtype'] = $relatedType;
        }
        if ($relatedId === null) {
            $sql .= ' AND related_id IS NULL';
        } else {
            $sql .= ' AND related_id = :rid';
            $params['rid'] = $relatedId;
        }

        return (int) $this->db->fetchColumn($sql, $params) > 0;
    }

    /**
     * 分頁查詢通知（後台「通知記錄」用）。
     *
     * @param array{recipient_type?: string, type?: string, is_read?: string} $filters
     * @return array{items: array, total: int}
     */
    public function findAll(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}notifications n {$where}",
            $params
        );

        $offset = ($page - 1) * $perPage;
        $listParams = $params;
        $listParams['limit']  = $perPage;
        $listParams['offset'] = $offset;

        $items = $this->db->fetchAll(
            "SELECT n.id, n.recipient_type, n.recipient_id, n.type, n.title, n.body,
                    n.channel, n.related_type, n.related_id, n.is_read,
                    n.scheduled_at, n.sent_at, n.created_at
             FROM {prefix}notifications n
             {$where}
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT :limit OFFSET :offset",
            $listParams
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * 標記單筆為已讀。
     */
    public function markRead(int $id): void
    {
        $this->db->execute(
            "UPDATE {prefix}notifications SET is_read = 1 WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * 通知總數。
     */
    public function count(): int
    {
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM {prefix}notifications");
    }

    /**
     * 未讀通知數（用於 header 鈴鐺 badge）。
     *
     * 計入廣播（recipient_id=0）與指定給該管理員的通知。
     */
    public function countUnread(int $adminUserId): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}notifications
             WHERE is_read = 0
               AND recipient_type = 'admin'
               AND (recipient_id = 0 OR recipient_id = :uid)",
            ['uid' => $adminUserId]
        );
    }

    /**
     * 依篩選組 WHERE 子句與綁定參數。
     *
     * @param array{recipient_type?: string, type?: string, is_read?: string} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        $recipientType = (string) ($filters['recipient_type'] ?? '');
        if (in_array($recipientType, ['admin', 'customer', 'system'], true)) {
            $conditions[] = 'n.recipient_type = :recipient_type';
            $params['recipient_type'] = $recipientType;
        }

        $type = trim((string) ($filters['type'] ?? ''));
        if ($type !== '') {
            $conditions[] = 'n.type = :type';
            $params['type'] = $type;
        }

        $isRead = (string) ($filters['is_read'] ?? '');
        if ($isRead === '0' || $isRead === '1') {
            $conditions[] = 'n.is_read = :is_read';
            $params['is_read'] = (int) $isRead;
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        return [$where, $params];
    }
}
