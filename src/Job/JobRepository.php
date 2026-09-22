<?php

declare(strict_types=1);

namespace YangSheep\CRM\Job;

use YangSheep\CRM\Core\Database;

/**
 * 工作卡片資料存取層。
 * 提供 CRUD、看板（依 column 分組）查詢、客戶內頁查詢、排序/移動。
 * JOIN 客戶名與負責人名供看板顯示；附帶每張卡的累計工時與計時狀態。
 * 全程 prepared statement，{prefix} 佔位符 + 參數綁定。
 *
 * 對應架構設計 §5.3 jobs、§7.6 工作看板。
 */
class JobRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 看板用：取得所有卡片（可選依負責人 / 客戶篩選），含客戶名、負責人名、
     * 累計工時（秒）與是否有 running 計時段。依 column_id、sort 排序。
     *
     * @param array{customer_id?: int, assigned_to?: int} $filters
     * @return array<int, array<string, mixed>>
     */
    public function findForBoard(array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);

        return $this->db->fetchAll(
            "SELECT j.id, j.column_id, j.customer_id, j.title, j.priority, j.sort,
                    j.assigned_to, j.due_date, j.cover_image_path, j.completed_at,
                    j.created_at, j.updated_at,
                    c.display_name AS customer_name,
                    u.display_name AS assignee_name,
                    COALESCE(t.total_seconds, 0) AS total_seconds,
                    COALESCE(t.running_count, 0) AS running_count
             FROM {prefix}jobs j
             LEFT JOIN {prefix}customers c ON j.customer_id = c.id
             LEFT JOIN {prefix}users u     ON j.assigned_to = u.id
             LEFT JOIN (
                SELECT job_id,
                       SUM(duration_seconds) AS total_seconds,
                       SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) AS running_count
                FROM {prefix}job_timers
                GROUP BY job_id
             ) t ON t.job_id = j.id
             {$where}
             ORDER BY j.column_id ASC, j.sort ASC, j.id ASC",
            $params
        );
    }

    /**
     * 取得指定客戶的工作卡片（供客戶內頁「工作」tab）。含欄位名與計時狀態。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByCustomer(int $customerId): array
    {
        return $this->db->fetchAll(
            "SELECT j.id, j.column_id, j.title, j.priority, j.due_date,
                    j.completed_at, j.created_at,
                    col.name AS column_name, col.color AS column_color,
                    u.display_name AS assignee_name,
                    COALESCE(t.total_seconds, 0) AS total_seconds,
                    COALESCE(t.running_count, 0) AS running_count
             FROM {prefix}jobs j
             LEFT JOIN {prefix}job_columns col ON j.column_id = col.id
             LEFT JOIN {prefix}users u         ON j.assigned_to = u.id
             LEFT JOIN (
                SELECT job_id, SUM(duration_seconds) AS total_seconds,
                       SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) AS running_count
                FROM {prefix}job_timers GROUP BY job_id
             ) t ON t.job_id = j.id
             WHERE j.customer_id = :customer_id
             ORDER BY j.completed_at IS NOT NULL, j.created_at DESC, j.id DESC",
            ['customer_id' => $customerId]
        );
    }

    /**
     * 取得單一卡片完整資料（含客戶名、負責人名、欄位名/slug、建立者名）。
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT j.*,
                    c.display_name  AS customer_name,
                    u.display_name  AS assignee_name,
                    cb.display_name AS created_by_name,
                    col.name AS column_name, col.slug AS column_slug, col.color AS column_color
             FROM {prefix}jobs j
             LEFT JOIN {prefix}customers c    ON j.customer_id = c.id
             LEFT JOIN {prefix}users u        ON j.assigned_to = u.id
             LEFT JOIN {prefix}users cb       ON j.created_by  = cb.id
             LEFT JOIN {prefix}job_columns col ON j.column_id  = col.id
             WHERE j.id = :id",
            ['id' => $id]
        );
    }

    /**
     * 取得指定欄位內目前最大 sort（新卡片排到該欄最後）。
     */
    public function maxSortInColumn(int $columnId): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COALESCE(MAX(sort), 0) FROM {prefix}jobs WHERE column_id = :column_id",
            ['column_id' => $columnId]
        );
    }

    /**
     * 新增卡片。
     *
     * @return int 新卡片 ID
     */
    public function insert(array $data): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}jobs
                (column_id, customer_id, title, description, cover_file_id, cover_image_path,
                 priority, sort, assigned_to, due_date, related_quote_id, created_by,
                 completed_at, created_at, updated_at)
             VALUES
                (:column_id, :customer_id, :title, :description, :cover_file_id, :cover_image_path,
                 :priority, :sort, :assigned_to, :due_date, :related_quote_id, :created_by,
                 :completed_at, NOW(), NOW())",
            [
                'column_id'        => $data['column_id'],
                'customer_id'      => $data['customer_id'] ?? null,
                'title'            => $data['title'],
                'description'      => ($data['description'] ?? '') !== '' ? $data['description'] : null,
                'cover_file_id'    => $data['cover_file_id'] ?? null,
                'cover_image_path' => $data['cover_image_path'] ?? null,
                'priority'         => $data['priority'] ?? 'medium',
                'sort'             => (int) ($data['sort'] ?? 0),
                'assigned_to'      => $data['assigned_to'] ?? null,
                'due_date'         => $data['due_date'] ?? null,
                'related_quote_id' => $data['related_quote_id'] ?? null,
                'created_by'       => $data['created_by'] ?? null,
                'completed_at'     => $data['completed_at'] ?? null,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * 更新卡片（白名單欄位）。
     */
    public function update(int $id, array $data): void
    {
        $sets   = [];
        $params = ['id' => $id];

        $allowedFields = [
            'column_id', 'customer_id', 'title', 'description',
            'cover_file_id', 'cover_image_path', 'priority', 'sort',
            'assigned_to', 'due_date', 'related_quote_id', 'completed_at',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $sets[]         = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if ($sets === []) {
            return;
        }

        $sets[]    = 'updated_at = NOW()';
        $setClause = implode(', ', $sets);

        $this->db->execute(
            "UPDATE {prefix}jobs SET {$setClause} WHERE id = :id",
            $params
        );
    }

    /**
     * 移動卡片到指定欄位與排序位置（拖拉 / 變更狀態用）。
     * 僅更新 column_id 與 sort；completed_at 由 Service 依目標欄位另行處理。
     */
    public function move(int $id, int $columnId, int $sort): void
    {
        $this->db->execute(
            "UPDATE {prefix}jobs SET column_id = :column_id, sort = :sort, updated_at = NOW()
             WHERE id = :id",
            ['column_id' => $columnId, 'sort' => $sort, 'id' => $id]
        );
    }

    /**
     * 刪除卡片（entries / timers 由 FK CASCADE 連帶刪除）。
     *
     * @return int 影響筆數
     */
    public function delete(int $id): int
    {
        return $this->db->execute(
            "DELETE FROM {prefix}jobs WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * 卡片總數（可選依欄位）。
     */
    public function count(?int $columnId = null): int
    {
        if ($columnId !== null) {
            return (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM {prefix}jobs WHERE column_id = :id",
                ['id' => $columnId]
            );
        }
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM {prefix}jobs");
    }

    /**
     * 依篩選條件組 WHERE。僅接受 customer_id / assigned_to（正整數）。
     *
     * @param array{customer_id?: int, assigned_to?: int} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        $customerId = (int) ($filters['customer_id'] ?? 0);
        if ($customerId > 0) {
            $conditions[] = 'j.customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }

        $assignedTo = (int) ($filters['assigned_to'] ?? 0);
        if ($assignedTo > 0) {
            $conditions[] = 'j.assigned_to = :assigned_to';
            $params['assigned_to'] = $assignedTo;
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        return [$where, $params];
    }
}
