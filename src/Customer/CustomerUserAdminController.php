<?php

declare(strict_types=1);

namespace YangSheep\CRM\Customer;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Core\Validator;
use YangSheep\CRM\Portal\Auth\CustomerAuthService;
use YangSheep\CRM\Portal\CustomerUserRepository;
use YangSheep\CRM\AuditLog\AuditLogService;

/**
 * 管理員端：為客戶建立 / 管理 Portal 登入帳號（對應架構設計 §7.5）。
 *
 * 路由（routes/admin.php，需 customer_user.manage 權限）：
 *   POST /admin/customers/{id}/portal-account          建立客戶主帳號（owner）
 *   POST /admin/customers/{id}/portal-account/{uid}/toggle  停用 / 啟用某帳號
 *   POST /admin/customers/{id}/portal-account/{uid}/reset-password  重設密碼（產生一次性密碼）
 *
 * 因無 SMTP（邀請信屬 P4-6 email_queue），建立 / 重設後以一次性密碼「畫面顯示」（flash），
 * 由管理員轉達客戶。所有操作寫 audit_logs（actor = 管理員 id）。
 *
 * Zero Trust：CSRF + 權限（路由群組 customer_user.manage）+ 帳號操作綁 customer_id。
 */
class CustomerUserAdminController extends Controller
{
    private CustomerAuthService $authService;
    private CustomerUserRepository $repo;
    private CustomerService $customerService;
    private AuditLogService $auditLog;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->authService     = new CustomerAuthService();
        $this->repo            = new CustomerUserRepository();
        $this->customerService = new CustomerService();
        $this->auditLog        = new AuditLogService();
    }

    /**
     * 建立客戶主帳號（owner）。
     * 管理員可自填初始密碼，或留空由系統產生一次性密碼（顯示於畫面）。
     */
    public function store(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作。');
        }

        $customerId = (int) $this->request->param('id');

        $customer = $this->customerService->findById($customerId);
        if ($customer === null) {
            $this->redirectWith('/admin/customers', 'error', '客戶不存在。');
        }

        $validator = Validator::make($this->request->all(), [
            'login_email'  => 'required|email|max:255',
            'display_name' => 'string|max:100',
            'password'     => 'string|max:200',
        ]);
        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        $email = (string) $this->request->input('login_email', '');
        $name  = (string) $this->request->input('display_name', '');

        // 密碼：管理員填則用之（需 >=8）；留空則系統產生一次性密碼。
        $inputPassword = (string) $this->request->input('password', '');
        $generated = false;
        if ($inputPassword === '') {
            $inputPassword = CustomerAuthService::generateInitialPassword();
            $generated = true;
        } elseif (mb_strlen($inputPassword) < 8) {
            $this->backWithError('密碼長度至少需 8 個字元（或留空由系統產生）。');
        }

        try {
            $ownerId = $this->authService->createOwner(
                $customerId,
                $email,
                $inputPassword,
                $name !== '' ? $name : (string) $customer['display_name'],
                $this->currentUserId(),
                function (int $ownerId) use ($customerId, $email): void {
                    $this->auditLog->log(
                        $this->currentUserId(),
                        'customer_portal_account_created',
                        'customer_user',
                        $ownerId,
                        ['customer_id' => $customerId, 'login_email' => mb_strtolower(trim($email))],
                        $this->request->ip()
                    );
                }
            );

            // 因無 SMTP：以一次性密碼畫面顯示（flash），請管理員轉達客戶。
            // 注意：僅在「系統產生」或剛建立時顯示一次；不長存、不再次顯示。
            Session::flash('success', sprintf(
                '已建立客戶登入帳號（%s）。%s請立即記下並轉達客戶；此密碼僅顯示一次。',
                mb_strtolower(trim($email)),
                '初始密碼：' . $inputPassword . '。'
            ));
            $this->redirect('/admin/customers/' . $customerId);
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 停用 / 啟用某 portal 帳號（綁 customer_id；不可停用 owner 自身機制由 Service 控）。
     * 此處管理員可同時停用 owner 或 member（與客戶端 owner 不可停自己的規則不同：
     * 管理員為更高權限，可停用整個客戶的任一帳號，但仍綁定 customer_id scope）。
     */
    public function toggle(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作。');
        }

        $customerId = (int) $this->request->param('id');
        $uid        = (int) $this->request->param('uid');
        $active     = $this->request->input('active') === '1';

        // 綁 customer_id 的啟停（Repository 強制 scope）。
        $target = $this->repo->findByIdForCustomer($uid, $customerId);
        if ($target === null) {
            $this->redirectWith('/admin/customers/' . $customerId, 'error', '找不到該帳號。');
        }

        try {
            $this->authService->setAccountActiveByAdmin(
                $uid,
                $customerId,
                $active,
                function () use ($active, $uid, $customerId): void {
                    $this->auditLog->log(
                        $this->currentUserId(),
                        $active ? 'customer_portal_account_enabled' : 'customer_portal_account_disabled',
                        'customer_user',
                        $uid,
                        ['customer_id' => $customerId],
                        $this->request->ip()
                    );
                }
            );
        } catch (\Throwable $e) {
            error_log('YS CRM: portal account status transaction failed: ' . $e->getMessage());
            $this->redirectWith(
                '/admin/customers/' . $customerId,
                'error',
                '帳號狀態未變更；session 撤銷或稽核未能完整保存，請稍後重試。'
            );
        }

        $this->redirectWith('/admin/customers/' . $customerId, 'success',
            $active ? '帳號已啟用。' : '帳號已停用。');
    }

    /**
     * 重設某 portal 帳號密碼（產生一次性密碼，畫面顯示）。
     */
    public function resetPassword(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作。');
        }

        $customerId = (int) $this->request->param('id');
        $uid        = (int) $this->request->param('uid');

        $target = $this->repo->findByIdForCustomer($uid, $customerId);
        if ($target === null) {
            $this->redirectWith('/admin/customers/' . $customerId, 'error', '找不到該帳號。');
        }

        $newPassword = CustomerAuthService::generateInitialPassword();
        try {
            $this->authService->resetPassword(
                $uid,
                $newPassword,
                $this->currentUserId(),
                $customerId,
                $this->request->ip()
            );
        } catch (\Throwable) {
            $this->redirectWith(
                '/admin/customers/' . $customerId,
                'error',
                '密碼重設失敗；原密碼與既有登入態均未變更，請檢查系統狀態後重試。'
            );
        }

        Session::flash('success', sprintf(
            '已重設帳號（%s）密碼。新密碼：%s。請立即記下並轉達客戶；此密碼僅顯示一次。',
            (string) $target['login_email'],
            $newPassword
        ));
        $this->redirect('/admin/customers/' . $customerId);
    }

    /**
     * 當前管理員 id（無則 0）。
     */
    private function currentUserId(): int
    {
        $user = Session::get('user');
        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }
}
