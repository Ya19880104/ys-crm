<?php

declare(strict_types=1);

namespace YangSheep\CRM\Role;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Core\Validator;
use YangSheep\CRM\AuditLog\AuditLogService;

class RoleController extends Controller
{
    private RoleService $roleService;
    private AuditLogService $auditLogService;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->roleService = new RoleService();
        $this->auditLogService = new AuditLogService();
    }

    /**
     * 角色列表（含權限矩陣）
     */
    public function index(): void
    {
        $roles       = $this->roleService->findAll();
        $permissions = $this->roleService->getPermissions();

        // 為每個角色取得其權限 ID 列表
        $rolePermissions = [];
        foreach ($roles as $role) {
            $rolePermissions[$role['id']] = array_column(
                $this->roleService->getRolePermissions((int) $role['id']),
                'permission_id'
            );
        }

        $this->render('admin/roles/index', [
            'title'           => '角色權限管理',
            'roles'           => $roles,
            'permissions'     => $permissions,
            'rolePermissions' => $rolePermissions,
        ]);
    }

    /**
     * 編輯角色權限表單
     */
    public function edit(): void
    {
        $id   = (int) $this->request->param('id');
        $role = $this->roleService->findById($id);

        if ($role === null) {
            $this->redirectWith('/admin/roles', 'error', '角色不存在。');
        }

        $permissions     = $this->roleService->getPermissions();
        $rolePermissions = array_column(
            $this->roleService->getRolePermissions($id),
            'permission_id'
        );

        $this->render('admin/roles/edit', [
            'title'           => '編輯角色權限 - ' . ($role['name'] ?? ''),
            'role'            => $role,
            'permissions'     => $permissions,
            'rolePermissions' => $rolePermissions,
        ]);
    }

    /**
     * 更新角色權限
     */
    public function update(): void
    {
        $id = (int) $this->request->param('id');

        $role = $this->roleService->findById($id);
        if ($role === null) {
            $this->redirectWith('/admin/roles', 'error', '角色不存在。');
        }

        $permissionIds = $this->request->input('permissions', []);
        if (!is_array($permissionIds)) {
            $permissionIds = [];
        }

        // 過濾為整數陣列
        $permissionIds = array_map('intval', $permissionIds);

        $this->roleService->updatePermissions($id, $permissionIds);

        $currentUser = Session::get('user');
        $this->auditLogService->log(
            (int) ($currentUser['id'] ?? 0),
            'role_permissions_updated',
            'role',
            $id,
            ['permission_ids' => $permissionIds],
            $this->request->ip()
        );

        $this->redirectWith('/admin/roles', 'success', '角色權限已更新。');
    }
}
