<?php

declare(strict_types=1);

namespace YangSheep\CRM\Hosting;

use YangSheep\CRM\Core\Database;

/**
 * 客戶主機資產資料存取層。
 * 提供 CRUD、含篩選（customer_id / status / 到期月份 due_month）的分頁列表，
 * 並 JOIN 客戶名（display_name）與關聯網站（url）供 Excel-like 總表顯示。
 * 全程 prepared statement，{prefix} 佔位符 + 參數綁定。
 *
 * 對應架構設計 §5.4 customer_hosting、§7.7 Excel-like 總表（篩選 到期月份 / 狀態 / 客戶）。
 */
class HostingRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 分頁查詢主機資產列表（含客戶名、關聯網站 URL）。
     *
     * @param array{customer_id?: int|string, status?: string, due_month?: string} $filters
     * @return array{items: array, total: int}
     */
    public function findAll(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}customer_hosting h {$where}",
            $params
        );

        $offset = ($page - 1) * $perPage;

        $listParams = $params;
        $listParams['limit']  = $perPage;
        $listParams['offset'] = $offset;

        $items = $this->db->fetchAll(
            "SELECT h.id, h.customer_id, h.type, h.ip_address, h.account_email,
                    h.spec, h.start_date, h.end_date, h.status, h.related_website_id,
                    h.notes, h.created_at, h.updated_at,
                    c.display_name AS customer_name,
                    w.url AS related_website_url
             FROM {prefix}customer_hosting h
             LEFT JOIN {prefix}customers c        ON h.customer_id = c.id
             LEFT JOIN {prefix}customer_websites w ON h.related_website_id = w.id
             {$where}
             ORDER BY h.end_date IS NULL, h.end_date ASC, h.id DESC
             LIMIT :limit OFFSET :offset",
            $listParams
        );

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * 取得單一主機資產（含客戶名、關聯網站 URL、建立者名）。
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT h.*,
                    c.display_name AS customer_name,
                    w.url AS related_website_url,
                    cb.display_name AS created_by_name
             FROM {prefix}customer_hosting h
             LEFT JOIN {prefix}customers c        ON h.customer_id = c.id
             LEFT JOIN {prefix}customer_websites w ON h.related_website_id = w.id
             LEFT JOIN {prefix}users cb           ON h.created_by  = cb.id
             WHERE h.id = :id",
            ['id' => $id]
        );
    }

    /**
     * 取得指定客戶的所有主機資產（供客戶內頁 tab）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByCustomer(int $customerId): array
    {
        return $this->db->fetchAll(
            "SELECT h.id, h.type, h.ip_address, h.account_email, h.spec,
                    h.start_date, h.end_date, h.status, h.related_website_id,
                    w.url AS related_website_url
             FROM {prefix}customer_hosting h
             LEFT JOIN {prefix}customer_websites w ON h.related_website_id = w.id
             WHERE h.customer_id = :customer_id
             ORDER BY h.end_date IS NULL, h.end_date ASC, h.id DESC",
            ['customer_id' => $customerId]
        );
    }

    /**
     * 新增主機資產。
     *
     * @return int 新資產 ID
     */
    public function insert(array $data): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}customer_hosting
                (customer_id, type, ip_address, account_email, spec,
                 start_date, end_date, status, related_website_id, notes,
                 created_by, created_at, updated_at)
             VALUES
                (:customer_id, :type, :ip_address, :account_email, :spec,
                 :start_date, :end_date, :status, :related_website_id, :notes,
                 :created_by, NOW(), NOW())",
            [
                'customer_id'        => $data['customer_id'],
                'type'               => $data['type'] ?? 'shared',
                'ip_address'         => $data['ip_address'] ?? '',
                'account_email'      => $data['account_email'] ?? '',
                'spec'               => ($data['spec'] ?? '') !== '' ? $data['spec'] : null,
                'start_date'         => $data['start_date'] ?? null,
                'end_date'           => $data['end_date'] ?? null,
                'status'             => $data['status'] ?? 'active',
                'related_website_id' => $data['related_website_id'] ?? null,
                'notes'              => ($data['notes'] ?? '') !== '' ? $data['notes'] : null,
                'created_by'         => $data['created_by'] ?? null,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * 更新主機資產（白名單欄位）。
     */
    public function update(int $id, array $data): void
    {
        $sets   = [];
        $params = ['id' => $id];

        $allowedFields = [
            'customer_id', 'type', 'ip_address', 'account_email', 'spec',
            'start_date', 'end_date', 'status', 'related_website_id', 'notes',
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
            "UPDATE {prefix}customer_hosting SET {$setClause} WHERE id = :id",
            $params
        );
    }

    /**
     * 刪除主機資產。
     *
     * @return int 影響筆數
     */
    public function delete(int $id): int
    {
        return $this->db->execute(
            "DELETE FROM {prefix}customer_hosting WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * 主機資產總數。
     */
    public function count(): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}customer_hosting"
        );
    }

    /**
     * 將「租用已到期但仍標記 active」的主機資產批次翻為 expired。
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
            "UPDATE {prefix}customer_hosting
             SET status = 'expired', updated_at = NOW()
             WHERE status = 'active'
               AND end_date IS NOT NULL
               AND end_date < CURDATE()"
        );
    }

    /**
     * 取得「即將到期」的主機資產（到期提醒 cron 用）。
     *
     * 條件：status='active' 且 end_date 落在 [today, today+days] 區間（含端點）。
     * 已到期者（end_date < today）由 markExpired 處理翻 status，不在此提醒範圍。
     * JOIN 客戶名供通知文案。以伺服器端 CURDATE() 比對，避免時區/前端污染。
     *
     * @param int $days 提前提醒天數
     * @return array<int, array<string, mixed>>
     */
    public function findExpiringSoon(int $days): array
    {
        return $this->db->fetchAll(
            "SELECT h.id, h.customer_id, h.end_date, h.status,
                    c.display_name AS customer_name
             FROM {prefix}customer_hosting h
             LEFT JOIN {prefix}customers c ON h.customer_id = c.id
             WHERE h.status = 'active'
               AND h.end_date IS NOT NULL
               AND h.end_date >= CURDATE()
               AND h.end_date <= DATE_ADD(CURDATE(), INTERVAL :days DAY)
             ORDER BY h.end_date ASC, h.id ASC",
            ['days' => max(0, $days)]
        );
    }

    /**
     * 依篩選條件組出 WHERE 子句與綁定參數。
     *
     * - customer_id：限定客戶（正整數）。
     * - status：僅接受白名單值（active / expired / terminated）。
     * - due_month：到期月份，格式 YYYY-MM；比對 end_date 落在該月。
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
            $conditions[] = 'h.customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }

        // 狀態
        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['active', 'expired', 'terminated'], true)) {
            $conditions[] = 'h.status = :status';
            $params['status'] = $status;
        }

        // 到期月份（YYYY-MM）：比對 end_date 落在該月
        $dueMonth = (string) ($filters['due_month'] ?? '');
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $dueMonth) === 1) {
            $conditions[] = "h.end_date IS NOT NULL
                             AND DATE_FORMAT(h.end_date, '%Y-%m') = :due_month";
            $params['due_month'] = $dueMonth;
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        return [$where, $params];
    }
}
