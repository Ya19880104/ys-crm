<?php

declare(strict_types=1);

namespace YangSheep\CRM\User;

use YangSheep\CRM\Core\Database;

class UserRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 分頁查詢使用者（含角色名稱）
     *
     * @return array{items: array, total: int}
     */
    public function findAll(int $page = 1, int $perPage = 15): array
    {
        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}users"
        );

        $offset = ($page - 1) * $perPage;

        $items = $this->db->fetchAll(
            "SELECT u.id, u.username, u.display_name, u.email, u.status,
                    u.role_id, u.last_login_at, u.created_at,
                    r.name AS role_name
             FROM {prefix}users u
             LEFT JOIN {prefix}roles r ON u.role_id = r.id
             ORDER BY u.created_at DESC
             LIMIT :limit OFFSET :offset",
            [
                'limit'  => $perPage,
                'offset' => $offset,
            ]
        );

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * 根據 ID 取得使用者
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT u.*, r.name AS role_name
             FROM {prefix}users u
             LEFT JOIN {prefix}roles r ON u.role_id = r.id
             WHERE u.id = :id",
            ['id' => $id]
        );
    }

    /**
     * 根據帳號查詢
     */
    public function findByUsername(string $username): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}users WHERE username = :username",
            ['username' => $username]
        );
    }

    /**
     * 根據 Email 查詢
     */
    public function findByEmail(string $email): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}users WHERE email = :email",
            ['email' => $email]
        );
    }

    /**
     * 新增使用者
     *
     * @return int 新建使用者 ID
     */
    public function insert(array $data): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}users
                (username, password, display_name, email, role_id, status, created_at, updated_at)
             VALUES
                (:username, :password, :display_name, :email, :role_id, 'active', NOW(), NOW())",
            [
                'username'     => $data['username'],
                'password'     => $data['password'],
                'display_name' => $data['display_name'],
                'email'        => $data['email'],
                'role_id'      => $data['role_id'],
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * 更新使用者
     */
    public function update(int $id, array $data): void
    {
        if (array_key_exists('password', $data)) {
            throw new \LogicException('既有帳號密碼必須經 CredentialRotationService 更新。');
        }

        $sets   = [];
        $params = ['id' => $id];

        // 新增欄位務必同步加入白名單，否則寫入會靜默失敗（值永遠進不去資料庫）。
        // password 刻意不在這裡：既有帳號的 credential 只能經
        // CredentialRotationService 完成 hash/readback/session revoke 的原子流程。
        $allowedFields = [
            'username', 'display_name', 'email', 'role_id', 'status', 'avatar_path',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $sets[]         = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($sets)) {
            return;
        }

        $sets[]            = 'updated_at = NOW()';
        $setClause         = implode(', ', $sets);

        $this->db->execute(
            "UPDATE {prefix}users SET {$setClause} WHERE id = :id",
            $params
        );
    }

    /**
     * 使用者總數
     */
    public function count(): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}users"
        );
    }
}
