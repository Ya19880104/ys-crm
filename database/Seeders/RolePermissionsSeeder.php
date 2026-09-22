<?php

declare(strict_types=1);

namespace YangSheep\CRM\Database\Seeders;

use PDO;

class RolePermissionsSeeder
{
    private PDO $pdo;
    private string $prefix;

    public function __construct(PDO $pdo, string $prefix = 'ys_crm_')
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
    }

    public function run(): int
    {
        $mapping = [
            'super_admin' => ['*'], // 全部權限
            'admin' => [
                'user.view',
                'board.manage', 'board.view_all', 'board.view_public',
                'stage.manage',
                'card.create', 'card.edit_all', 'card.edit_own', 'card.delete',
                'card.drag_all', 'card.drag_own', 'card.approve', 'card.assign',
                'card.view_public',
                'audit_log.view',
            ],
            'staff' => [
                'board.view_public',
                'card.create', 'card.edit_own', 'card.drag_own', 'card.view_public',
            ],
            'public' => [
                'board.view_public',
                'card.view_public',
            ],
        ];

        $count = 0;

        foreach ($mapping as $roleSlug => $permissionCodes) {
            // 取得 role_id
            $stmt = $this->pdo->prepare("SELECT id FROM `{$this->prefix}roles` WHERE slug = ?");
            $stmt->execute([$roleSlug]);
            $roleId = $stmt->fetchColumn();

            if (!$roleId) {
                continue;
            }

            if ($permissionCodes === ['*']) {
                // Super Admin 取得全部權限
                $stmt = $this->pdo->prepare("SELECT id FROM `{$this->prefix}permissions`");
                $stmt->execute();
                $permissionIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $placeholders = implode(',', array_fill(0, count($permissionCodes), '?'));
                $stmt = $this->pdo->prepare(
                    "SELECT id FROM `{$this->prefix}permissions` WHERE code IN ({$placeholders})"
                );
                $stmt->execute($permissionCodes);
                $permissionIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }

            $insertStmt = $this->pdo->prepare(
                "INSERT IGNORE INTO `{$this->prefix}role_permissions` (role_id, permission_id) VALUES (?, ?)"
            );

            foreach ($permissionIds as $permissionId) {
                $insertStmt->execute([$roleId, $permissionId]);
                $count++;
            }
        }

        return $count;
    }
}
