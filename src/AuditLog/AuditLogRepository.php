<?php

declare(strict_types=1);

namespace YangSheep\CRM\AuditLog;

use YangSheep\CRM\Core\Database;

class AuditLogRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 新增審計日誌
     */
    public function insert(array $data): void
    {
        $this->db->execute(
            "INSERT INTO {prefix}audit_logs
                (user_id, action, target_type, target_id, detail, ip_address, created_at)
             VALUES
                (:user_id, :action, :target_type, :target_id, :detail, :ip_address, NOW())",
            [
                'user_id'     => $data['user_id'],
                'action'      => $data['action'],
                'target_type' => $data['target_type'] ?? null,
                'target_id'   => $data['target_id'] ?? null,
                'detail'      => $data['detail'] ?? null,
                'ip_address'  => $data['ip_address'] ?? null,
            ]
        );
    }

    /**
     * 分頁查詢審計日誌
     *
     * @return array{items: array, total: int}
     */
    public function findAll(int $page = 1, int $perPage = 20, ?array $filters = null): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[]            = 'a.user_id = :user_id';
            $params['user_id'] = $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $where[]          = 'a.action = :action';
            $params['action'] = $filters['action'];
        }

        if (!empty($filters['target_type'])) {
            $where[]               = 'a.target_type = :target_type';
            $params['target_type'] = $filters['target_type'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // 計算總數
        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}audit_logs a {$whereClause}",
            $params
        );

        // 取得分頁資料
        $offset           = ($page - 1) * $perPage;
        $params['limit']  = $perPage;
        $params['offset'] = $offset;

        $items = $this->db->fetchAll(
            "SELECT a.*, u.username, u.display_name
             FROM {prefix}audit_logs a
             LEFT JOIN {prefix}users u ON a.user_id = u.id
             {$whereClause}
             ORDER BY a.created_at DESC
             LIMIT :limit OFFSET :offset",
            $params
        );

        return [
            'items' => $items,
            'total' => $total,
        ];
    }
}
