<?php

declare(strict_types=1);

namespace YangSheep\CRM\Role;

use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\Session;

class RoleService
{
    private RoleRepository $repository;

    public function __construct()
    {
        $this->repository = new RoleRepository();
    }

    /**
     * 取得所有角色
     */
    public function findAll(): array
    {
        return $this->repository->findAll();
    }

    /**
     * 根據 ID 取得角色
     */
    public function findById(int $id): ?array
    {
        return $this->repository->findById($id);
    }

    /**
     * 取得所有權限定義
     */
    public function getPermissions(): array
    {
        return $this->repository->findAllPermissions();
    }

    /**
     * 取得指定角色的權限列表
     */
    public function getRolePermissions(int $roleId): array
    {
        return $this->repository->findRolePermissions($roleId);
    }

    /**
     * 核心權限檢查：使用者是否擁有特定權限碼
     */
    public function hasPermission(int $userId, string $permissionCode): bool
    {
        return $this->repository->userHasPermission($userId, $permissionCode);
    }

    /**
     * 更新角色的權限
     *
     * @param int   $roleId        角色 ID
     * @param int[] $permissionIds 權限 ID 陣列
     */
    public function updatePermissions(int $roleId, array $permissionIds): void
    {
        $db = Database::getInstance();
        $db->transaction(function () use ($roleId, $permissionIds) {
            $this->repository->deleteRolePermissions($roleId);

            foreach ($permissionIds as $permissionId) {
                $this->repository->insertRolePermission($roleId, (int) $permissionId);
            }
        });
    }
}
