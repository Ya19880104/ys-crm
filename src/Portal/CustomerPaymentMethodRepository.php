<?php

declare(strict_types=1);

namespace YangSheep\CRM\Portal;

use YangSheep\CRM\Core\Database;

/**
 * 客戶儲存卡片（{prefix}customer_payment_methods）資料存取層。
 *
 * 🔴 只存 provider token 密文（token_ref）+ 顯示用 brand/last4；絕不存完整卡號/CVV。
 * Zero Trust：所有查詢/異動皆綁 customer_id；list 預設只回 is_active=1。
 * 全程 prepared statement，{prefix} 佔位符 + 參數綁定。
 */
class CustomerPaymentMethodRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 列出某客戶的有效卡片（預設卡優先）。不回傳 token_ref（避免外洩，列表用不到）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findActiveByCustomer(int $customerId): array
    {
        return $this->db->fetchAll(
            "SELECT id, customer_id, customer_user_id, provider, brand, last4,
                    exp_month, exp_year, label, is_default, created_at
             FROM {prefix}customer_payment_methods
             WHERE customer_id = :customer_id AND is_active = 1
             ORDER BY is_default DESC, id DESC",
            ['customer_id' => $customerId]
        );
    }

    /**
     * 取某客戶「指定 id」的卡片（強制 customer_id scope）。跨客戶一律 null。
     */
    public function findByIdForCustomer(int $id, int $customerId): ?array
    {
        return $this->db->fetch(
            "SELECT id, customer_id, customer_user_id, provider, brand, last4,
                    exp_month, exp_year, token_ref, label, is_default, is_active, created_at
             FROM {prefix}customer_payment_methods
             WHERE id = :id AND customer_id = :customer_id",
            ['id' => $id, 'customer_id' => $customerId]
        );
    }

    /**
     * 取某客戶的預設有效卡片（供 RecurringService auto_charge 引用）；無則 null。
     */
    public function findDefaultByCustomer(int $customerId): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}customer_payment_methods
             WHERE customer_id = :customer_id AND is_active = 1 AND is_default = 1
             ORDER BY id DESC LIMIT 1",
            ['customer_id' => $customerId]
        );
    }

    /**
     * 某客戶目前有效卡片數。
     */
    public function countActiveByCustomer(int $customerId): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}customer_payment_methods
             WHERE customer_id = :customer_id AND is_active = 1",
            ['customer_id' => $customerId]
        );
    }

    /**
     * 新增卡片。
     *
     * @return int 新卡片 id
     */
    public function insert(array $data): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}customer_payment_methods
                (customer_id, customer_user_id, provider, brand, last4, exp_month, exp_year,
                 token_ref, label, is_default, is_active, created_at, updated_at)
             VALUES
                (:customer_id, :customer_user_id, :provider, :brand, :last4, :exp_month, :exp_year,
                 :token_ref, :label, :is_default, 1, NOW(), NOW())",
            [
                'customer_id'      => $data['customer_id'],
                'customer_user_id' => $data['customer_user_id'] ?? null,
                'provider'         => $data['provider'] ?? 'sandbox',
                'brand'            => $data['brand'] ?? '',
                'last4'            => $data['last4'] ?? '',
                'exp_month'        => $data['exp_month'] ?? null,
                'exp_year'         => $data['exp_year'] ?? null,
                'token_ref'        => $data['token_ref'] ?? null,
                'label'            => $data['label'] ?? '',
                'is_default'       => !empty($data['is_default']) ? 1 : 0,
            ]
        );
        return (int) $this->db->lastInsertId();
    }

    /**
     * 將某客戶所有卡片的 is_default 清為 0（設新預設前呼叫；綁 customer_id）。
     */
    public function clearDefaultForCustomer(int $customerId): void
    {
        $this->db->execute(
            "UPDATE {prefix}customer_payment_methods
             SET is_default = 0, updated_at = NOW()
             WHERE customer_id = :customer_id",
            ['customer_id' => $customerId]
        );
    }

    /**
     * 設某卡片為預設（綁 customer_id + 限有效卡）。
     *
     * @return int 影響筆數
     */
    public function setDefaultForCustomer(int $id, int $customerId): int
    {
        return $this->db->execute(
            "UPDATE {prefix}customer_payment_methods
             SET is_default = 1, updated_at = NOW()
             WHERE id = :id AND customer_id = :customer_id AND is_active = 1",
            ['id' => $id, 'customer_id' => $customerId]
        );
    }

    /**
     * 軟刪除卡片（is_active=0、清預設旗標；綁 customer_id）。
     * 不真正 DELETE，保留付款關聯/稽核。
     *
     * @return int 影響筆數
     */
    public function softDeleteForCustomer(int $id, int $customerId): int
    {
        return $this->db->execute(
            "UPDATE {prefix}customer_payment_methods
             SET is_active = 0, is_default = 0, updated_at = NOW()
             WHERE id = :id AND customer_id = :customer_id",
            ['id' => $id, 'customer_id' => $customerId]
        );
    }
}
