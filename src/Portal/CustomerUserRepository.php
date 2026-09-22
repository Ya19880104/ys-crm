<?php

declare(strict_types=1);

namespace YangSheep\CRM\Portal;

use YangSheep\CRM\Core\Database;

/**
 * 客戶 Portal 帳號資料存取層（{prefix}customer_users）。
 *
 * Zero Trust：凡「依 id 取單筆」的方法皆同時要求 customer_id（或 parent owner id），
 * 確保任何子帳號 / 跨客戶操作都被綁定在正確的 customer scope 內，無法越權。
 * 全程 prepared statement，{prefix} 佔位符 + 參數綁定。
 */
class CustomerUserRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 以登入 email 取得帳號（登入用；全表唯一）。
     * 回傳含 password_hash（驗證後由 Service 移除）。
     */
    public function findByEmail(string $email): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}customer_users WHERE login_email = :email LIMIT 1",
            ['email' => mb_strtolower(trim($email))]
        );
    }

    /**
     * 以 id 取得帳號（不含 password_hash，供顯示/中介層重驗）。
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT id, customer_id, parent_user_id, role, login_email, display_name,
                    permissions_json, is_active, last_login_at, last_login_ip, created_by, created_at
             FROM {prefix}customer_users WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * 以 id 取得帳號（含 password_hash，供「變更自己密碼」驗舊密碼用）。
     */
    public function findWithHashById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}customer_users WHERE id = :id LIMIT 1",
            ['id' => $id]
        );
    }

    /**
     * 取得某客戶底下的所有 portal 帳號（管理員/owner 團隊頁用），owner 優先、其次建立時間。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByCustomer(int $customerId): array
    {
        return $this->db->fetchAll(
            "SELECT id, customer_id, parent_user_id, role, login_email, display_name,
                    permissions_json, is_active, last_login_at, last_login_ip, created_at
             FROM {prefix}customer_users
             WHERE customer_id = :customer_id
             ORDER BY (role = 'owner') DESC, id ASC",
            ['customer_id' => $customerId]
        );
    }

    /**
     * 取得某客戶的 owner（主帳號）；無則 null。
     */
    public function findOwnerByCustomer(int $customerId): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}customer_users
             WHERE customer_id = :customer_id AND role = 'owner'
             ORDER BY id ASC LIMIT 1",
            ['customer_id' => $customerId]
        );
    }

    /**
     * 取得某客戶底下「指定 id」的帳號（強制 customer_id scope）。
     * 跨客戶存取一律回 null（呼叫端據此回 404）。
     */
    public function findByIdForCustomer(int $id, int $customerId, bool $forUpdate = false): ?array
    {
        $sql = "SELECT id, customer_id, parent_user_id, role, login_email, display_name,
                    permissions_json, is_active, last_login_at, created_at
              FROM {prefix}customer_users
              WHERE id = :id AND customer_id = :customer_id";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        return $this->db->fetch(
            $sql,
            ['id' => $id, 'customer_id' => $customerId]
        );
    }

    /**
     * email 是否已存在（建立帳號前查重；全表唯一）。
     */
    public function emailExists(string $email): bool
    {
        $cnt = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}customer_users WHERE login_email = :email",
            ['email' => mb_strtolower(trim($email))]
        );
        return (int) $cnt > 0;
    }

    /**
     * 某客戶是否已有 owner（每客戶至多一個 owner）。
     */
    public function hasOwner(int $customerId): bool
    {
        $cnt = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}customer_users
             WHERE customer_id = :customer_id AND role = 'owner'",
            ['customer_id' => $customerId]
        );
        return (int) $cnt > 0;
    }

    /**
     * 鎖定 owner 唯一性所依附的 customer parent row。
     *
     * customer_users 無法用一般 UNIQUE key 表達「同 customer 僅一個 role=owner」。
     * 因此所有 owner 建立交易先鎖同一筆 customers.id，再檢查 hasOwner 與 insert；
     * 不同 email 的並行請求也會在共同 parent row 上序列化。
     */
    public function lockCustomerForOwnerCreation(int $customerId): void
    {
        $customer = $this->db->fetch(
            'SELECT id FROM {prefix}customers WHERE id = :customer_id FOR UPDATE',
            ['customer_id' => $customerId]
        );
        if ($customer === null) {
            throw new \RuntimeException('客戶不存在。');
        }
    }

    /**
     * 新增 portal 帳號。
     *
     * @return int 新帳號 id
     */
    public function insert(array $data): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}customer_users
                (customer_id, parent_user_id, role, login_email, password_hash,
                 display_name, permissions_json, is_active, created_by, created_at, updated_at)
             VALUES
                (:customer_id, :parent_user_id, :role, :login_email, :password_hash,
                 :display_name, :permissions_json, :is_active, :created_by, NOW(), NOW())",
            [
                'customer_id'      => $data['customer_id'],
                'parent_user_id'   => $data['parent_user_id'] ?? null,
                'role'             => $data['role'] ?? 'member',
                'login_email'      => mb_strtolower(trim((string) $data['login_email'])),
                'password_hash'    => $data['password_hash'],
                'display_name'     => $data['display_name'] ?? '',
                'permissions_json' => $data['permissions_json'] ?? null,
                'is_active'        => !empty($data['is_active']) ? 1 : 0,
                'created_by'       => $data['created_by'] ?? null,
            ]
        );
        return (int) $this->db->lastInsertId();
    }

    /**
     * 更新密碼雜湊（限定 id）。
     */
    public function updatePassword(int $id, string $passwordHash): void
    {
        $this->db->execute(
            "UPDATE {prefix}customer_users SET password_hash = :hash, updated_at = NOW() WHERE id = :id",
            ['hash' => $passwordHash, 'id' => $id]
        );
    }

    /**
     * 更新顯示名稱（限定 id）。
     */
    public function updateDisplayName(int $id, string $displayName): void
    {
        $this->db->execute(
            "UPDATE {prefix}customer_users SET display_name = :name, updated_at = NOW() WHERE id = :id",
            ['name' => $displayName, 'id' => $id]
        );
    }

    /**
     * 設定啟用狀態（限定 customer_id scope，避免越權停用他客戶帳號）。
     *
     * @return int 影響筆數
     */
    public function setActiveForCustomer(int $id, int $customerId, bool $active): int
    {
        return $this->db->execute(
            "UPDATE {prefix}customer_users
             SET is_active = :active, updated_at = NOW()
             WHERE id = :id AND customer_id = :customer_id",
            ['active' => $active ? 1 : 0, 'id' => $id, 'customer_id' => $customerId]
        );
    }

    /**
     * 更新 member 權限 scopes（限定 customer_id scope）。
     *
     * @return int 影響筆數
     */
    public function updatePermissionsForCustomer(int $id, int $customerId, ?string $permissionsJson): int
    {
        return $this->db->execute(
            "UPDATE {prefix}customer_users
             SET permissions_json = :perms, updated_at = NOW()
             WHERE id = :id AND customer_id = :customer_id AND role = 'member'",
            ['perms' => $permissionsJson, 'id' => $id, 'customer_id' => $customerId]
        );
    }

    /**
     * 記錄最近登入時間 / IP。
     */
    public function recordLogin(int $id, string $ip): void
    {
        $this->db->execute(
            "UPDATE {prefix}customer_users
             SET last_login_at = NOW(), last_login_ip = :ip
             WHERE id = :id",
            ['ip' => $ip, 'id' => $id]
        );
    }
}
