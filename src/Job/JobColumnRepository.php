<?php

declare(strict_types=1);

namespace YangSheep\CRM\Job;

use YangSheep\CRM\Core\Database;

/**
 * 工作看板欄位資料存取層。
 * 提供欄位 CRUD 與排序。全程 prepared statement，{prefix} 佔位符 + 參數綁定。
 *
 * 對應架構設計 §5.3 job_columns、§7.6 工作看板（欄位總控可增刪）。
 */
class JobColumnRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 取得所有欄位（含每欄卡片數），預設僅啟用中、依 sort 排序。
     *
     * @param bool $activeOnly 僅取 is_active=1（看板顯示用 true；管理頁用 false 取全部）
     * @return array<int, array<string, mixed>>
     */
    public function findAll(bool $activeOnly = true): array
    {
        $where = $activeOnly ? 'WHERE col.is_active = 1' : '';

        return $this->db->fetchAll(
            "SELECT col.id, col.name, col.slug, col.color, col.sort, col.is_active,
                    col.created_at, col.updated_at,
                    (SELECT COUNT(*) FROM {prefix}jobs j WHERE j.column_id = col.id) AS job_count
             FROM {prefix}job_columns col
             {$where}
             ORDER BY col.sort ASC, col.id ASC"
        );
    }

    /**
     * 取得單一欄位。
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}job_columns WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * 依 slug 取得欄位（供判斷「完成」類欄位等）。
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}job_columns WHERE slug = :slug",
            ['slug' => $slug]
        );
    }

    /**
     * slug 是否已存在（建立 / 更新唯一性檢查）。
     *
     * @param int $exceptId 排除此 id（更新時排除自己）
     */
    public function slugExists(string $slug, int $exceptId = 0): bool
    {
        $count = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}job_columns WHERE slug = :slug AND id <> :id",
            ['slug' => $slug, 'id' => $exceptId]
        );
        return $count > 0;
    }

    /**
     * 取得目前最大 sort（新增欄位排到最後）。
     */
    public function maxSort(): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COALESCE(MAX(sort), 0) FROM {prefix}job_columns"
        );
    }

    /**
     * 新增欄位。
     *
     * @return int 新欄位 ID
     */
    public function insert(array $data): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}job_columns
                (name, slug, color, sort, is_active, created_at, updated_at)
             VALUES
                (:name, :slug, :color, :sort, :is_active, NOW(), NOW())",
            [
                'name'      => $data['name'],
                'slug'      => $data['slug'],
                'color'     => $data['color'] ?? '#1E40AF',
                'sort'      => (int) ($data['sort'] ?? 0),
                'is_active' => (int) ($data['is_active'] ?? 1),
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * 更新欄位（白名單欄位）。slug 不開放更新（穩定識別碼）。
     */
    public function update(int $id, array $data): void
    {
        $sets   = [];
        $params = ['id' => $id];

        $allowedFields = ['name', 'color', 'sort', 'is_active'];

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
            "UPDATE {prefix}job_columns SET {$setClause} WHERE id = :id",
            $params
        );
    }

    /**
     * 刪除欄位。呼叫端須先確認欄位內無卡片（DB FK 為 RESTRICT，含卡片時會擲例外）。
     *
     * @return int 影響筆數
     */
    public function delete(int $id): int
    {
        return $this->db->execute(
            "DELETE FROM {prefix}job_columns WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * 該欄位內的卡片數（刪除前檢查用）。
     */
    public function jobCount(int $id): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}jobs WHERE column_id = :id",
            ['id' => $id]
        );
    }

    /**
     * 欄位總數。
     */
    public function count(): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}job_columns"
        );
    }
}
