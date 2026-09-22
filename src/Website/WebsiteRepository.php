<?php

declare(strict_types=1);

namespace YangSheep\CRM\Website;

use YangSheep\CRM\Core\Database;

/**
 * 客戶網站資產資料存取層。
 * 提供 CRUD、含篩選（customer_id / status / 到期月份 due_month）的分頁列表，
 * 並 JOIN 客戶名（display_name）供 Excel-like 總表顯示。
 * 全程 prepared statement，{prefix} 佔位符 + 參數綁定。
 *
 * 對應架構設計 §5.4 customer_websites、§7.7 Excel-like 總表（篩選 到期月份 / 狀態 / 客戶）。
 */
class WebsiteRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 分頁查詢網站資產列表（含客戶名）。
     *
     * @param array{customer_id?: int|string, status?: string, due_month?: string} $filters
     * @return array{items: array, total: int}
     */
    public function findAll(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}customer_websites w {$where}",
            $params
        );

        $offset = ($page - 1) * $perPage;

        $listParams = $params;
        $listParams['limit']  = $perPage;
        $listParams['offset'] = $offset;

        $items = $this->db->fetchAll(
            "SELECT w.id, w.customer_id, w.url, w.case_type,
                    w.contract_start, w.contract_end,
                    w.maintenance_start, w.maintenance_end,
                    w.status, w.notes, w.created_at, w.updated_at,
                    c.display_name AS customer_name
             FROM {prefix}customer_websites w
             LEFT JOIN {prefix}customers c ON w.customer_id = c.id
             {$where}
             ORDER BY w.contract_end IS NULL, w.contract_end ASC, w.id DESC
             LIMIT :limit OFFSET :offset",
            $listParams
        );

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * 取得單一網站資產（含客戶名、建立者名）。
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT w.*,
                    c.display_name AS customer_name,
                    cb.display_name AS created_by_name
             FROM {prefix}customer_websites w
             LEFT JOIN {prefix}customers c  ON w.customer_id = c.id
             LEFT JOIN {prefix}users cb     ON w.created_by  = cb.id
             WHERE w.id = :id",
            ['id' => $id]
        );
    }

    /**
     * 取得指定客戶的所有網站資產（供客戶內頁 tab、主機關聯下拉）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByCustomer(int $customerId): array
    {
        return $this->db->fetchAll(
            "SELECT w.id, w.url, w.case_type, w.contract_start, w.contract_end,
                    w.maintenance_start, w.maintenance_end, w.status
             FROM {prefix}customer_websites w
             WHERE w.customer_id = :customer_id
             ORDER BY w.contract_end IS NULL, w.contract_end ASC, w.id DESC",
            ['customer_id' => $customerId]
        );
    }

    /**
     * 新增網站資產。
     *
     * @return int 新資產 ID
     */
    public function insert(array $data): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}customer_websites
                (customer_id, url, case_type, contract_start, contract_end,
                 maintenance_start, maintenance_end, status, notes, created_by,
                 created_at, updated_at)
             VALUES
                (:customer_id, :url, :case_type, :contract_start, :contract_end,
                 :maintenance_start, :maintenance_end, :status, :notes, :created_by,
                 NOW(), NOW())",
            [
                'customer_id'       => $data['customer_id'],
                'url'               => $data['url'] ?? '',
                'case_type'         => $data['case_type'] ?? 'build',
                'contract_start'    => $data['contract_start'] ?? null,
                'contract_end'      => $data['contract_end'] ?? null,
                'maintenance_start' => $data['maintenance_start'] ?? null,
                'maintenance_end'   => $data['maintenance_end'] ?? null,
                'status'            => $data['status'] ?? 'active',
                'notes'             => ($data['notes'] ?? '') !== '' ? $data['notes'] : null,
                'created_by'        => $data['created_by'] ?? null,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * 更新網站資產（白名單欄位）。
     */
    public function update(int $id, array $data): void
    {
        $sets   = [];
        $params = ['id' => $id];

        $allowedFields = [
            'customer_id', 'url', 'case_type', 'contract_start', 'contract_end',
            'maintenance_start', 'maintenance_end', 'status', 'notes',
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
            "UPDATE {prefix}customer_websites SET {$setClause} WHERE id = :id",
            $params
        );
    }

    /**
     * 刪除網站資產。關聯主機的 related_website_id 由 FK ON DELETE SET NULL 自動清空。
     *
     * @return int 影響筆數
     */
    public function delete(int $id): int
    {
        return $this->db->execute(
            "DELETE FROM {prefix}customer_websites WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * 網站資產總數。
     */
    public function count(): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}customer_websites"
        );
    }

    /**
     * 將「合約已到期但仍標記 active」的網站資產批次翻為 expired。
     *
     * 本方法供未來到期 cron（P4-6）呼叫；本模組階段不自動執行，
     * 列表僅以視覺 badge 標示逾期，不變更實際 status。
     * 以伺服器端 CURDATE() 比對，避免時區/前端傳入污染。
     *
     * @return int 受影響（翻轉）筆數
     */
    public function markExpired(): int
    {
        return $this->db->execute(
            "UPDATE {prefix}customer_websites
             SET status = 'expired', updated_at = NOW()
             WHERE status = 'active'
               AND contract_end IS NOT NULL
               AND contract_end < CURDATE()"
        );
    }

    /**
     * 取得「即將到期」的網站資產（到期提醒 cron 用）。
     *
     * 條件：status='active' 且 contract_end 落在 [today, today+days] 區間（含端點）。
     * 已到期者（contract_end < today）由 markExpired 處理翻 status，不在此提醒範圍。
     * JOIN 客戶名供通知文案。以伺服器端 CURDATE() 比對，避免時區/前端污染。
     *
     * @param int $days 提前提醒天數
     * @return array<int, array<string, mixed>>
     */
    public function findExpiringSoon(int $days): array
    {
        return $this->db->fetchAll(
            "SELECT w.id, w.customer_id, w.url, w.contract_end, w.status,
                    c.display_name AS customer_name
             FROM {prefix}customer_websites w
             LEFT JOIN {prefix}customers c ON w.customer_id = c.id
             WHERE w.status = 'active'
               AND w.contract_end IS NOT NULL
               AND w.contract_end >= CURDATE()
               AND w.contract_end <= DATE_ADD(CURDATE(), INTERVAL :days DAY)
             ORDER BY w.contract_end ASC, w.id ASC",
            ['days' => max(0, $days)]
        );
    }

    /**
     * 依篩選條件組出 WHERE 子句與綁定參數。
     *
     * - customer_id：限定客戶（正整數）。
     * - status：僅接受白名單值（active / expired / terminated）。
     * - due_month：到期月份，格式 YYYY-MM；比對 contract_end 落在該月。
     *
     * @param array{customer_id?: int|string, status?: string, due_month?: string} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        // 客戶
        $customerId = (int) ($filters['customer_id'] ?? 0);
        if ($customerId > 0) {
            $conditions[] = 'w.customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }

        // 狀態
        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['active', 'expired', 'terminated'], true)) {
            $conditions[] = 'w.status = :status';
            $params['status'] = $status;
        }

        // 到期月份（YYYY-MM）：比對 contract_end 介於該月第一天至月底
        $dueMonth = (string) ($filters['due_month'] ?? '');
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $dueMonth) === 1) {
            $conditions[] = "w.contract_end IS NOT NULL
                             AND DATE_FORMAT(w.contract_end, '%Y-%m') = :due_month";
            $params['due_month'] = $dueMonth;
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        return [$where, $params];
    }
}
