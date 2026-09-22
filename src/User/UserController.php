<?php

declare(strict_types=1);

namespace YangSheep\CRM\User;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Core\Validator;
use YangSheep\CRM\AuditLog\AuditLogService;
use YangSheep\CRM\Role\RoleService;

class UserController extends Controller
{
    private UserService $userService;
    private RoleService $roleService;
    private AuditLogService $auditLogService;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->userService = new UserService();
        $this->roleService = new RoleService();
        $this->auditLogService = new AuditLogService();
    }

    /**
     * 使用者列表（分頁）
     */
    public function index(): void
    {
        $page    = max(1, (int) $this->request->query('page', '1'));
        $perPage = 15;

        $result = $this->userService->findAll($page, $perPage);

        $this->render('admin/users/index', [
            'title'       => '使用者管理',
            'users'       => $result['items'],
            'total'       => $result['total'],
            'page'        => $page,
            'perPage'     => $perPage,
            'totalPages'  => (int) ceil($result['total'] / $perPage),
        ]);
    }

    /**
     * 新增使用者表單
     */
    public function create(): void
    {
        $roles = $this->roleService->findAll();

        $this->render('admin/users/create', [
            'title' => '新增使用者',
            'roles' => $roles,
        ]);
    }

    /**
     * 建立使用者
     */
    public function store(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $validator = Validator::make($this->request->all(), [
            'username'     => 'required|string|min:3|max:50',
            'password'     => 'required|string|min:10|confirmed',
            'display_name' => 'required|string|min:1|max:100',
            'email'        => 'required|email|max:255',
            'role_id'      => 'required|integer',
        ]);

        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        $data = $this->request->only([
            'username', 'password', 'display_name', 'email', 'role_id',
        ]);

        try {
            $userId = $this->userService->create($data);

            $currentUser = Session::get('user');
            $this->auditLogService->log(
                (int) ($currentUser['id'] ?? 0),
                'user_created',
                'user',
                $userId,
                ['username' => $data['username']],
                $this->request->ip()
            );

            $this->redirectWith('/admin/users', 'success', '使用者已建立。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 編輯使用者表單
     */
    public function edit(): void
    {
        $id   = (int) $this->request->param('id');
        $user = $this->userService->findById($id);

        if ($user === null) {
            $this->redirectWith('/admin/users', 'error', '使用者不存在。');
        }

        $roles = $this->roleService->findAll();

        $this->render('admin/users/edit', [
            'title'    => '編輯使用者',
            'editUser' => $user,
            'roles'    => $roles,
        ]);
    }

    /**
     * 更新使用者
     */
    public function update(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $id = (int) $this->request->param('id');

        $rules = [
            'display_name' => 'required|string|min:1|max:100',
            'email'        => 'required|email|max:255',
            'role_id'      => 'required|integer',
        ];

        // 密碼為選填（空字串表示不修改）
        $password = $this->request->input('password', '');
        if ($password !== '') {
            $rules['password'] = 'string|min:10|confirmed';
        }

        $validator = Validator::make($this->request->all(), $rules);

        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        $data = $this->request->only(['display_name', 'email', 'role_id']);
        if ($password !== '') {
            $data['password'] = $password;
        }

        // 🔴 頭像必須**先驗證再持久化**。
        // 原本是「先存其他欄位，再處理頭像；頭像不合法就 redirect」，結果是角色／Email／
        // 密碼已經改掉、卻沒有留下 user_updated 稽核紀錄（redirect 發生在 audit 之前）。
        // 現在先把檔案驗好，確定可用才進入寫入流程。
        $avatarFile = $this->request->file('avatar');
        $avatarPath = null;
        if ($avatarFile !== null && ($avatarFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $avatarPath = $this->userService->handleAvatarUpload($avatarFile);
            if ($avatarPath === null) {
                $this->backWithError('頭像上傳失敗：請確認為 JPG / PNG / WebP、不超過 2MB 且尺寸在 4000×4000 以內。其他變更未儲存。');
            }
        }

        $removeAvatar = $this->request->input('remove_avatar') === '1';
        $avatarChanged = $removeAvatar || $avatarPath !== null;
        $newAvatarPath = $avatarPath ?? '';
        $oldAvatarPath = null;
        $transactionCommitted = false;

        try {
            $this->userService->update(
                $id,
                $data,
                function (int $_revoked) use (
                    $id,
                    $data,
                    $avatarChanged,
                    $newAvatarPath,
                    &$oldAvatarPath
                ): void {
                    // 交易內只改 DB 參照，不刪實體檔；audit 失敗時兩者一起回滾。
                    if ($avatarChanged) {
                        $oldAvatarPath = $this->userService->stageAvatarChange($id, $newAvatarPath);
                    }
                    $currentUser = Session::get('user');
                    $this->auditLogService->log(
                        (int) ($currentUser['id'] ?? 0),
                        'user_updated',
                        'user',
                        $id,
                        ['fields' => array_merge(array_keys($data), $avatarChanged ? ['avatar_path'] : [])],
                        $this->request->ip()
                    );
                }
            );
            $transactionCommitted = true;

            // 檔案系統無法 rollback；只在 DB/audit commit 後清除舊檔。
            $this->userService->finalizeAvatarChange($oldAvatarPath, $newAvatarPath);

            if ($avatarChanged) {
                $currentUser = Session::get('user');
                if ((int) ($currentUser['id'] ?? 0) === $id) {
                    $fresh = $this->userService->findById($id);
                    if ($fresh !== null) {
                        $currentUser['avatar_path'] = (string) ($fresh['avatar_path'] ?? '');
                        Session::set('user', $currentUser);
                    }
                }
            }

            $this->redirectWith('/admin/users', 'success', '使用者已更新。');
        } catch (\Throwable $e) {
            if (!$transactionCommitted) {
                $this->userService->discardPendingAvatar($avatarPath);
            }
            if ($e instanceof \RuntimeException) {
                $this->backWithError($e->getMessage());
            }
            error_log('User update failed: ' . $e->getMessage());
            $this->backWithError('使用者更新失敗，請稍後再試。');
        }
    }

    /**
     * 停用使用者（不刪除，改為 inactive）
     */
    public function destroy(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $id = (int) $this->request->param('id');

        $currentUser = Session::get('user');

        // 不能停用自己
        if ((int) ($currentUser['id'] ?? 0) === $id) {
            $this->backWithError('無法停用自己的帳號。');
        }

        $this->userService->deactivate(
            $id,
            function (int $_revoked) use ($currentUser, $id): void {
                $this->auditLogService->log(
                    (int) ($currentUser['id'] ?? 0),
                    'user_deactivated',
                    'user',
                    $id,
                    null,
                    $this->request->ip()
                );
            }
        );

        $this->redirectWith('/admin/users', 'success', '使用者已停用。');
    }
}
