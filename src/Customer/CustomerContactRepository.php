<?php

declare(strict_types=1);

namespace YangSheep\CRM\Customer;

use YangSheep\CRM\Core\Database;

/**
 * 客戶聯絡人資料存取層（依 customer_id CRUD）。
 * 全程 prepared statement，{prefix} 佔位符 + 參數綁定。
 */
class CustomerContactRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 取得指定客戶的所有聯絡人（primary 優先、其次依建立時間）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByCustomer(int $customerId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {prefix}customer_contacts
             WHERE customer_id = :customer_id
             ORDER BY is_primary DESC, id ASC",
            ['customer_id' => $customerId]
        );
    }

    /**
     * 一次取得多個客戶的聯絡人（供列表頁批次載入，避免 N+1）。
     *
     * @param int[] $customerIds
     * @return array<int, array<int, array<string, mixed>>> 以 customer_id 為鍵分組
     */
    public function findByCustomerIds(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        // 僅接受整數，動態組出對應數量的具名佔位符（值仍以綁定傳入）
        $ids = array_values(array_unique(array_map('intval', $customerIds)));
        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $key = 'id' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $rows = $this->db->fetchAll(
            "SELECT * FROM {prefix}customer_contacts
             WHERE customer_id IN (" . implode(', ', $placeholders) . ")
             ORDER BY is_primary DESC, id ASC",
            $params
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['customer_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * 取得單一聯絡人。
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}customer_contacts WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * 取得指定客戶的主要聯絡人（無則 null）。
     */
    public function findPrimary(int $customerId): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}customer_contacts
             WHERE customer_id = :customer_id AND is_primary = 1
             ORDER BY id ASC
             LIMIT 1",
            ['customer_id' => $customerId]
        );
    }

    /**
     * 計算客戶聯絡人數量。
     */
    public function countByCustomer(int $customerId): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}customer_contacts WHERE customer_id = :customer_id",
            ['customer_id' => $customerId]
        );
    }

    /**
     * 新增聯絡人。
     *
     * @return int 新聯絡人 ID
     */
    public function insert(int $customerId, array $data): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}customer_contacts
                (customer_id, name, role, is_primary, phone, mobile, email,
                 line_id, fb_url, threads_url, note, created_at)
             VALUES
                (:customer_id, :name, :role, :is_primary, :phone, :mobile, :email,
                 :line_id, :fb_url, :threads_url, :note, NOW())",
            [
                'customer_id' => $customerId,
                'name'        => $data['name'],
                'role'        => $data['role'] ?? '',
                'is_primary'  => !empty($data['is_primary']) ? 1 : 0,
                'phone'       => $data['phone'] ?? '',
                'mobile'      => $data['mobile'] ?? '',
                'email'       => $data['email'] ?? '',
                'line_id'     => $data['line_id'] ?? '',
                'fb_url'      => $data['fb_url'] ?? '',
                'threads_url' => $data['threads_url'] ?? '',
                'note'        => ($data['note'] ?? '') !== '' ? $data['note'] : null,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * 刪除單一聯絡人（限定所屬客戶，避免越權刪到別人的聯絡人）。
     *
     * @return int 影響筆數
     */
    public function deleteForCustomer(int $id, int $customerId): int
    {
        return $this->db->execute(
            "DELETE FROM {prefix}customer_contacts WHERE id = :id AND customer_id = :customer_id",
            ['id' => $id, 'customer_id' => $customerId]
        );
    }

    /**
     * 刪除指定客戶的全部聯絡人（供刪除客戶前清理；FK CASCADE 亦會處理）。
     */
    public function deleteByCustomer(int $customerId): int
    {
        return $this->db->execute(
            "DELETE FROM {prefix}customer_contacts WHERE customer_id = :customer_id",
            ['customer_id' => $customerId]
        );
    }
}
