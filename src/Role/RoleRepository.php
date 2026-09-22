<?php

declare(strict_types=1);

namespace YangSheep\CRM\Role;

use YangSheep\CRM\Core\Database;

class RoleRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 取得所有角色
     */
    public function findAll(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {prefix}roles ORDER BY id ASC"
        );
    }

    /**
     * 根據 ID 取得角色
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}roles WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * 取得所有權限定義
     */
    public function findAllPermissions(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {prefix}permissions ORDER BY group_name ASC, code ASC"
        );
    }

    /**
     * 取得指定角色的權限關聯
     */
    public function findRolePermissions(int $roleId): array
    {
        return $this->db->fetchAll(
            "SELECT rp.permission_id, p.code, p.name, p.group_name
             FROM {prefix}role_permissions rp
             JOIN {prefix}permissions p ON rp.permission_id = p.id
             WHERE rp.role_id = :role_id
             ORDER BY p.group_name ASC, p.code ASC",
            ['role_id' => $roleId]
        );
    }

    /**
     * 檢查使用者是否擁有指定的權限碼
     */
    public function userHasPermission(int $userId, string $permissionCode): bool
    {
        $result = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}role_permissions rp
             JOIN {prefix}permissions p ON rp.permission_id = p.id
             JOIN {prefix}users u ON u.role_id = rp.role_id
             WHERE u.id = :user_id AND p.code = :code",
            [
                'user_id' => $userId,
                'code'    => $permissionCode,
            ]
        );

        return (int) $result > 0;
    }

    /**
     * 刪除角色的所有權限關聯
     */
    public function deleteRolePermissions(int $roleId): void
    {
        $this->db->execute(
            "DELETE FROM {prefix}role_permissions WHERE role_id = :role_id",
            ['role_id' => $roleId]
        );
    }

    /**
     * 新增角色與權限的關聯
     */
    public function insertRolePermission(int $roleId, int $permissionId): void
    {
        $this->db->execute(
            "INSERT INTO {prefix}role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)",
            [
                'role_id'       => $roleId,
                'permission_id' => $permissionId,
            ]
        );
    }
}
