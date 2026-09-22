<?php

declare(strict_types=1);

namespace YangSheep\CRM\Portal;

use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Core\Validator;
use YangSheep\CRM\Portal\Auth\CustomerGuard;
use YangSheep\CRM\Portal\Auth\CustomerAuthService;
use YangSheep\CRM\AuditLog\AuditLogService;

/**
 * 客戶 Portal 團隊（子帳號管理；🔴 僅 owner 可用）。
 *
 * 雙重強制（後端強制，非僅前端隱藏）：
 *   1) 路由掛 CustomerAuthMiddleware:team（owner 才放行；member 即使被勾選 team 也擋）。
 *   2) 本控制器每個動作再 assertOwner()（defense in depth）。
 *
 * Zero Trust scope：
 *   - 子帳號一律建在 owner 自己的 customer_id 下（取自 session）。
 *   - 停用 / 改權限以 CustomerUserRepository 的 customer_id 綁定方法，無法跨客戶操作。
 */
class PortalTeamController extends PortalController
{
    private CustomerUserRepository $repo;
    private CustomerAuthService $authService;
    private AuditLogService $auditLog;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->repo        = new CustomerUserRepository();
        $this->authService = new CustomerAuthService();
        $this->auditLog    = new AuditLogService();
    }

    /**
     * 子帳號列表 + 新增表單。
     */
    public function index(): void
    {
        $this->assertOwner();

        $members = $this->repo->findByCustomer($this->customerId());

        $this->renderPortal('portal/team/index', [
            'title'      => '團隊與子帳號',
            'members'    => $members,
            'allScopes'  => CustomerGuard::ALL_SCOPES,
            'scopeLabels' => self::scopeLabels(),
        ]);
    }

    /**
     * 建立子帳號（member）。
     */
    public function store(): void
    {
        $this->assertOwner();

        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('驗證失敗，請重新操作。');
        }

        $validator = Validator::make($this->request->all(), [
            'email'        => 'required|email|max:255',
            'password'     => 'required|string|min:8|max:200',
            'display_name' => 'string|max:100',
        ]);
        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        $email    = (string) $this->request->input('email', '');
        $password = (string) $this->request->input('password', '');
        $name     = (string) $this->request->input('display_name', '');
        $scopes   = $this->collectScopes();

        try {
            $memberId = $this->authService->createMember(
                $this->customerId(),      // 🔴 owner 自己的 customer_id（session）
                $this->customerUserId(),  // parent = 當前 owner
                $email,
                $password,
                $name,
                $scopes
            );

            $this->auditLog->log(
                0,
                'portal_member_created',
                'customer_user',
                $memberId,
                ['customer_id' => $this->customerId(), 'by_owner' => $this->customerUserId(), 'scopes' => $scopes],
                $this->request->ip()
            );

            $this->redirectWith('/portal/team', 'success', '子帳號已建立。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 停用 / 啟用子帳號。
     */
    public function toggleActive(): void
    {
        $this->assertOwner();

        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('驗證失敗，請重新操作。');
        }

        $targetId = (int) $this->request->param('id');
        $active   = $this->request->input('active') === '1';

        try {
            $this->authService->setMemberActive(
                $targetId,
                $this->customerId(),
                $this->customerUserId(),
                $active,
                function () use ($active, $targetId): void {
                    $this->auditLog->log(
                        0,
                        $active ? 'portal_member_enabled' : 'portal_member_disabled',
                        'customer_user',
                        $targetId,
                        ['customer_id' => $this->customerId(), 'by_owner' => $this->customerUserId()],
                        $this->request->ip()
                    );
                }
            );

            $this->redirectWith('/portal/team', 'success', $active ? '子帳號已啟用。' : '子帳號已停用。');
        } catch (\Throwable $e) {
            error_log('YS CRM: portal member status transaction failed: ' . $e->getMessage());
            $this->backWithError('帳號狀態未變更；session 撤銷或稽核未能完整保存，請稍後重試。');
        }
    }

    /**
     * 變更子帳號權限 scopes。
     */
    public function updatePermissions(): void
    {
        $this->assertOwner();

        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('驗證失敗，請重新操作。');
        }

        $targetId = (int) $this->request->param('id');
        $scopes   = $this->collectScopes();

        try {
            $this->authService->updateMemberScopes($targetId, $this->customerId(), $scopes);

            $this->auditLog->log(
                0,
                'portal_member_permissions_updated',
                'customer_user',
                $targetId,
                ['customer_id' => $this->customerId(), 'by_owner' => $this->customerUserId(), 'scopes' => $scopes],
                $this->request->ip()
            );

            $this->redirectWith('/portal/team', 'success', '子帳號權限已更新。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    // ───────────────────────── 內部 ─────────────────────────

    /**
     * 後端強制：非 owner 一律拒絕（即使 middleware 已擋，這裡再保險）。
     */
    private function assertOwner(): void
    {
        if (!CustomerGuard::isOwner()) {
            Session::flash('error', '只有主帳號可以管理團隊。');
            $this->response->redirect('/portal');
        }
    }

    /**
     * 從表單蒐集 scopes（permissions[]），過濾白名單由 Service 處理。
     *
     * @return array<int, string>
     */
    private function collectScopes(): array
    {
        $raw = $this->request->input('permissions', []);
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_map('strval', $raw));
    }

    /**
     * scope 代碼 → 中文標籤。
     *
     * @return array<string, string>
     */
    public static function scopeLabels(): array
    {
        return [
            'quotes'          => '報價單',
            'payments'        => '付款記錄',
            'assets'          => '主機與網站',
            'contacts'        => '聯絡人',
            'payment_methods' => '付款卡片',
        ];
    }
}
