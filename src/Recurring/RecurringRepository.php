<?php

declare(strict_types=1);

namespace YangSheep\CRM\Recurring;

use YangSheep\CRM\Core\Database;

/**
 * 週期排程資料存取層（對應架構設計 §5.6 recurring_schedules、§7.8、§7.9）。
 *
 * 提供：CRUD、due 排程查詢（generateDue 用，含 FOR UPDATE 鎖列）、分頁列表、推進 next_run_at。
 * 全程 prepared statement，{prefix} 佔位符 + 參數綁定。
 */
class RecurringRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 新增排程。
     *
     * @return int 新排程 ID
     */
    public function insert(array $data): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}recurring_schedules
                (quote_id, customer_id, interval_unit, interval_value, next_run_at,
                 billing_anchor_day, advance_generate_days, auto_charge_after_days, payment_mode,
                 payment_method_id, is_active, last_generated_at, created_at, updated_at)
             VALUES
                (:quote_id, :customer_id, :interval_unit, :interval_value, :next_run_at,
                 :billing_anchor_day, :advance_generate_days, :auto_charge_after_days, :payment_mode,
                 :payment_method_id, :is_active, :last_generated_at, NOW(), NOW())",
            [
                'quote_id'               => $data['quote_id'] ?? null,
                'customer_id'            => $data['customer_id'] ?? null,
                'interval_unit'          => $data['interval_unit'] ?? 'month',
                'interval_value'         => (int) ($data['interval_value'] ?? 1),
                'next_run_at'            => $data['next_run_at'],
                'billing_anchor_day'     => $data['billing_anchor_day'] ?? null,
                'advance_generate_days'  => $data['advance_generate_days'] ?? null,
                'auto_charge_after_days' => $data['auto_charge_after_days'] ?? null,
                'payment_mode'           => $data['payment_mode'] ?? 'manual_atm',
                'payment_method_id'      => $data['payment_method_id'] ?? null,
                'is_active'              => (int) ($data['is_active'] ?? 1),
                'last_generated_at'      => $data['last_generated_at'] ?? null,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * 更新排程（白名單欄位）。
     */
    public function update(int $id, array $data): void
    {
        $sets   = [];
        $params = ['id' => $id];

        // 新增欄位務必同步加入白名單，否則寫入會靜默失敗（無錯誤、值進不去）。
        $allowedFields = [
            'quote_id', 'customer_id', 'interval_unit', 'interval_value', 'next_run_at',
            'billing_anchor_day', 'advance_generate_days', 'auto_charge_after_days', 'payment_mode',
            'payment_method_id', 'is_active', 'last_generated_at',
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
            "UPDATE {prefix}recurring_schedules SET {$setClause} WHERE id = :id",
            $params
        );
    }

    /**
     * 依 ID 取得排程（含來源報價標題/編號、客戶名）。
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT s.*,
                    q.quote_number AS quote_number,
                    q.title        AS quote_title,
                    q.total        AS quote_total,
                    q.currency     AS quote_currency,
                    c.display_name AS customer_name
             FROM {prefix}recurring_schedules s
             LEFT JOIN {prefix}quotes q    ON s.quote_id = q.id
             LEFT JOIN {prefix}customers c ON s.customer_id = c.id
             WHERE s.id = :id",
            ['id' => $id]
        );
    }

    /**
     * 在交易內以 FOR UPDATE 鎖定並取得排程列（generateDue / autoCharge 用，杜絕併發重複產生）。
     * 須於交易內呼叫。
     */
    public function findByIdForUpdate(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}recurring_schedules WHERE id = :id FOR UPDATE",
            ['id' => $id]
        );
    }

    /**
     * 取得「到了該產生」的啟用排程 id（generateDue 用）。
     *
     * 條件：is_active=1 且 quote_id 非 NULL（來源報價仍在）且
     *       next_run_at <= $today + advance（提前產生）。
     * advance 取排程 advance_generate_days（NULL 則用傳入的全域預設 $defaultAdvanceDays）。
     *
     * 回傳 id 陣列；逐筆於交易內 FOR UPDATE 鎖列再處理，避免長交易鎖整表。
     *
     * @param string $today              今日（YYYY-MM-DD）
     * @param int    $defaultAdvanceDays 全域提前產生天數
     * @return array<int, int> 排程 id 陣列
     */
    public function findDueIds(string $today, int $defaultAdvanceDays): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id
             FROM {prefix}recurring_schedules
             WHERE is_active = 1
               AND quote_id IS NOT NULL
               AND next_run_at <= DATE_ADD(:today, INTERVAL COALESCE(advance_generate_days, :adv) DAY)
             ORDER BY next_run_at ASC, id ASC",
            ['today' => $today, 'adv' => $defaultAdvanceDays]
        );

        return array_map(static fn ($r) => (int) $r['id'], $rows);
    }

    /**
     * 取得「啟用中的 auto_card 排程」id（autoChargeDue 用）。
     *
     * @return array<int, int>
     */
    public function findAutoChargeScheduleIds(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id
             FROM {prefix}recurring_schedules
             WHERE is_active = 1
               AND payment_mode = 'auto_card'
             ORDER BY id ASC"
        );
        return array_map(static fn ($r) => (int) $r['id'], $rows);
    }

    /**
     * 分頁查詢排程列表（後台「週期帳務」用）。
     *
     * @param array{is_active?: string, customer_id?: int} $filters
     * @return array{items: array, total: int}
     */
    public function findAll(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}recurring_schedules s {$where}",
            $params
        );

        $offset = ($page - 1) * $perPage;
        $listParams = $params;
        $listParams['limit']  = $perPage;
        $listParams['offset'] = $offset;

        $items = $this->db->fetchAll(
            "SELECT s.id, s.quote_id, s.customer_id, s.interval_unit, s.interval_value,
                    s.next_run_at, s.advance_generate_days, s.auto_charge_after_days,
                    s.payment_mode, s.payment_method_id, s.is_active, s.last_generated_at,
                    s.created_at, s.updated_at,
                    q.quote_number AS quote_number,
                    q.title        AS quote_title,
                    q.total        AS quote_total,
                    q.currency     AS quote_currency,
                    c.display_name AS customer_name
             FROM {prefix}recurring_schedules s
             LEFT JOIN {prefix}quotes q    ON s.quote_id = q.id
             LEFT JOIN {prefix}customers c ON s.customer_id = c.id
             {$where}
             ORDER BY s.is_active DESC, s.next_run_at ASC, s.id DESC
             LIMIT :limit OFFSET :offset",
            $listParams
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * 排程總數。
     */
    public function count(): int
    {
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM {prefix}recurring_schedules");
    }

    /**
     * 刪除排程（已產生報價的 recurring_schedule_id 由 FK ON DELETE SET NULL 自動清空，報價保留）。
     *
     * @return int 影響筆數
     */
    public function delete(int $id): int
    {
        return $this->db->execute(
            "DELETE FROM {prefix}recurring_schedules WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * 依篩選組 WHERE 子句與綁定參數。
     *
     * @param array{is_active?: string, customer_id?: int} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        $isActive = (string) ($filters['is_active'] ?? '');
        if ($isActive === '0' || $isActive === '1') {
            $conditions[] = 's.is_active = :is_active';
            $params['is_active'] = (int) $isActive;
        }

        $customerId = (int) ($filters['customer_id'] ?? 0);
        if ($customerId > 0) {
            $conditions[] = 's.customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        return [$where, $params];
    }
}
