<?php

declare(strict_types=1);

namespace YangSheep\CRM\Customer;

use YangSheep\CRM\Core\Database;

/**
 * 客戶主檔資料存取層。
 * 提供 CRUD、含篩選（type / status / keyword）的分頁列表，
 * 並可附帶每位客戶的聯絡人（列表頁批次載入避免 N+1）。
 * 全程 prepared statement，{prefix} 佔位符 + 參數綁定。
 */
class CustomerRepository
{
    private Database $db;
    private CustomerContactRepository $contacts;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->contacts = new CustomerContactRepository();
    }

    /**
     * 分頁查詢客戶列表（含負責人名稱、主要聯絡人、聯絡人數）。
     *
     * @param array{type?: string, status?: string, keyword?: string} $filters
     * @return array{items: array, total: int}
     */
    public function findAll(int $page = 1, int $perPage = 15, array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}customers c {$where}",
            $params
        );

        $offset = ($page - 1) * $perPage;

        $listParams = $params;
        $listParams['limit']  = $perPage;
        $listParams['offset'] = $offset;

        $items = $this->db->fetchAll(
            "SELECT c.id, c.type, c.display_name, c.tax_id, c.phone, c.email,
                    c.status, c.source, c.assigned_to, c.created_at,
                    u.display_name AS assigned_name
             FROM {prefix}customers c
             LEFT JOIN {prefix}users u ON c.assigned_to = u.id
             {$where}
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT :limit OFFSET :offset",
            $listParams
        );

        // 批次載入聯絡人，附掛主要聯絡人與聯絡人數
        $ids = array_map(static fn ($row) => (int) $row['id'], $items);
        $contactsByCustomer = $this->contacts->findByCustomerIds($ids);

        foreach ($items as &$item) {
            $cid = (int) $item['id'];
            $list = $contactsByCustomer[$cid] ?? [];
            $item['contacts_count'] = count($list);
            $item['primary_contact'] = $this->pickPrimary($list);
        }
        unset($item);

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * 取得單一客戶（含負責人名稱、建立者名稱）。
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT c.*,
                    u.display_name AS assigned_name,
                    cb.display_name AS created_by_name
             FROM {prefix}customers c
             LEFT JOIN {prefix}users u  ON c.assigned_to = u.id
             LEFT JOIN {prefix}users cb ON c.created_by  = cb.id
             WHERE c.id = :id",
            ['id' => $id]
        );
    }

    /**
     * 取得單一客戶並附帶其聯絡人陣列。
     */
    public function findWithContacts(int $id): ?array
    {
        $customer = $this->findById($id);
        if ($customer === null) {
            return null;
        }

        $customer['contacts'] = $this->contacts->findByCustomer($id);
        return $customer;
    }

    /**
     * 新增客戶。
     *
     * @return int 新客戶 ID
     */
    public function insert(array $data): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}customers
                (type, display_name, tax_id, address, phone, email, source,
                 status, assigned_to, notes, created_by, created_at, updated_at)
             VALUES
                (:type, :display_name, :tax_id, :address, :phone, :email, :source,
                 :status, :assigned_to, :notes, :created_by, NOW(), NOW())",
            [
                'type'         => $data['type'],
                'display_name' => $data['display_name'],
                'tax_id'       => $data['tax_id'] ?? '',
                'address'      => $data['address'] ?? '',
                'phone'        => $data['phone'] ?? '',
                'email'        => $data['email'] ?? '',
                'source'       => $data['source'] ?? '',
                'status'       => $data['status'] ?? 'active',
                'assigned_to'  => $data['assigned_to'] ?? null,
                'notes'        => ($data['notes'] ?? '') !== '' ? $data['notes'] : null,
                'created_by'   => $data['created_by'] ?? null,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * 更新客戶（白名單欄位）。
     */
    public function update(int $id, array $data): void
    {
        $sets   = [];
        $params = ['id' => $id];

        $allowedFields = [
            'type', 'display_name', 'tax_id', 'address', 'phone', 'email',
            'source', 'status', 'assigned_to', 'notes',
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
            "UPDATE {prefix}customers SET {$setClause} WHERE id = :id",
            $params
        );
    }

    /**
     * 刪除客戶（聯絡人由 FK CASCADE 連帶刪除）。
     *
     * @return int 影響筆數
     */
    public function delete(int $id): int
    {
        return $this->db->execute(
            "DELETE FROM {prefix}customers WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * 客戶總數。
     */
    public function count(): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}customers"
        );
    }

    /**
     * 依篩選條件組出 WHERE 子句與綁定參數。
     *
     * @param array{type?: string, status?: string, keyword?: string} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        // 類型：僅接受白名單值
        $type = $filters['type'] ?? '';
        if ($type === 'individual' || $type === 'company') {
            $conditions[] = 'c.type = :type';
            $params['type'] = $type;
        }

        // 狀態：僅接受白名單值
        $status = $filters['status'] ?? '';
        if (in_array($status, ['active', 'potential', 'inactive'], true)) {
            $conditions[] = 'c.status = :status';
            $params['status'] = $status;
        }

        // 關鍵字：比對名稱 / 統編 / 電話 / Email（LIKE，特殊字元跳脫）
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . $this->escapeLike($keyword) . '%';
            $conditions[] = "(c.display_name LIKE :kw_name ESCAPE '\\\\'
                              OR c.tax_id LIKE :kw_tax ESCAPE '\\\\'
                              OR c.phone LIKE :kw_phone ESCAPE '\\\\'
                              OR c.email LIKE :kw_email ESCAPE '\\\\')";
            $params['kw_name']  = $like;
            $params['kw_tax']   = $like;
            $params['kw_phone'] = $like;
            $params['kw_email'] = $like;
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        return [$where, $params];
    }

    /**
     * 跳脫 LIKE 中的萬用字元，避免使用者輸入的 % 或 _ 被當作萬用字元。
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * 從聯絡人陣列挑出主要聯絡人（無 primary 則取第一筆）。
     */
    private function pickPrimary(array $contacts): ?array
    {
        if ($contacts === []) {
            return null;
        }
        foreach ($contacts as $contact) {
            if (!empty($contact['is_primary'])) {
                return $contact;
            }
        }
        return $contacts[0];
    }
}
