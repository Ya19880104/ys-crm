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
use YangSheep\CRM\Customer\CustomerService;
use YangSheep\CRM\AuditLog\AuditLogService;

/**
 * 客戶 Portal 個人資料（基本資料 + 改密碼 + 改顯示名稱）。
 *
 * Zero Trust：
 *   - 一律操作「自己」的 customer_user（id 取自 session，非前端）。
 *   - 改密碼需先驗舊密碼（CustomerAuthService::changeOwnPassword）。
 *   - 客戶基本資料（公司名/電話等）為唯讀顯示（由我方維護），portal 僅能改自己的帳號顯示名稱與密碼。
 */
class PortalProfileController extends PortalController
{
    private CustomerAuthService $authService;
    private AuditLogService $auditLog;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->authService = new CustomerAuthService();
        $this->auditLog    = new AuditLogService();
    }

    /**
     * 個人資料頁。
     */
    public function index(): void
    {
        // 客戶基本資料（唯讀顯示）。
        $customer = (new CustomerService())->findById($this->customerId());

        $this->renderPortal('portal/profile/index', [
            'title'    => '個人資料',
            'customer' => $customer,
            'me'       => CustomerGuard::context(),
        ]);
    }

    /**
     * 變更顯示名稱。
     */
    public function updateProfile(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('驗證失敗，請重新操作。');
        }

        $validator = Validator::make($this->request->all(), [
            'display_name' => 'required|string|min:1|max:100',
        ]);
        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        $name = (string) $this->request->input('display_name', '');

        try {
            $this->authService->changeOwnDisplayName($this->customerUserId(), $name);

            // 同步刷新 session context 快照（避免介面顯示滯後）。
            $ctx = CustomerGuard::context();
            $ctx['display_name'] = trim($name);
            Session::set(CustomerGuard::SESSION_CONTEXT, $ctx);

            $this->auditLog->log(
                0,
                'portal_profile_updated',
                'customer_user',
                $this->customerUserId(),
                ['customer_id' => $this->customerId()],
                $this->request->ip()
            );

            $this->redirectWith('/portal/profile', 'success', '個人資料已更新。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 變更密碼（需驗舊密碼）。
     */
    public function changePassword(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('驗證失敗，請重新操作。');
        }

        $validator = Validator::make($this->request->all(), [
            'current_password' => 'required|string|min:1',
            'new_password'     => 'required|string|min:8|max:200|confirmed',
        ]);
        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        $current = (string) $this->request->input('current_password', '');
        $new     = (string) $this->request->input('new_password', '');
        $customerUserId = $this->customerUserId();
        $customerId     = $this->customerId();
        $ip             = $this->request->ip();

        try {
            $this->authService->changeOwnPassword(
                $customerUserId,
                $current,
                $new,
                function (int $_revoked) use ($customerUserId, $customerId, $ip): void {
                    $this->auditLog->log(
                        0,
                        'portal_password_changed',
                        'customer_user',
                        $customerUserId,
                        ['customer_id' => $customerId],
                        $ip
                    );
                }
            );

            $this->redirectWith('/portal/profile', 'success', '密碼已變更，請以新密碼登入。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }
}
