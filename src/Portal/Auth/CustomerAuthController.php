<?php

declare(strict_types=1);

namespace YangSheep\CRM\Portal\Auth;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Core\Validator;
use YangSheep\CRM\AuditLog\AuditLogService;

/**
 * 客戶 Portal 認證控制器（第二個對外認證面）。
 *
 * 路由（routes/web.php）：
 *   GET  /portal/login   登入表單
 *   POST /portal/login   處理登入（CSRF 群組內）
 *   POST /portal/logout  登出（CSRF 群組內）
 *
 * Zero Trust：
 *   - CSRF（POST 經 CsrfMiddleware；此處再驗一次 fail-closed）。
 *   - 登入節流（CustomerLoginAttemptService，per-IP + per-account，scope=customer 與管理員隔離）。
 *   - 密碼 password_verify；停用帳號拒絕登入。
 *   - 登入成功 regenerate session id（防 fixation）+ bindAuth('customer')（IP/UA 指紋 + DB 可撤銷）。
 *   - 平面隔離：登入只設客戶 session keys，並清除任何殘留的管理員 `user` key，
 *     確保此 session 純為客戶（無法存取 /admin/*）。
 */
class CustomerAuthController extends Controller
{
    private CustomerAuthService $authService;
    private CustomerLoginAttemptService $throttle;
    private AuditLogService $auditLog;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->authService = new CustomerAuthService();
        $this->throttle    = new CustomerLoginAttemptService();
        $this->auditLog    = new AuditLogService();
    }

    /**
     * 登入表單。已登入則導向 portal 首頁。
     */
    public function loginForm(): void
    {
        if (CustomerGuard::check()) {
            $this->redirect('/portal');
        }

        // 支援 ?return=/portal/quotes/5（登入後導回；僅接受站內相對路徑）。
        $return = $this->safeReturn((string) $this->request->query('return', ''));

        $this->view->layout('portal-auth');
        $this->render('portal/auth/login', [
            'title'  => '客戶專區登入',
            'return' => $return,
            'reason' => (string) $this->request->query('reason', ''),
        ]);
    }

    /**
     * 處理登入。
     */
    public function login(): void
    {
        $ip         = $this->request->ip();
        $throttleIp = $this->request->clientIpForThrottle();

        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('驗證失敗，請重新整理頁面後再試。');
        }

        $validator = Validator::make($this->request->all(), [
            'email'    => 'required|email|max:255',
            'password' => 'required|string|min:1',
        ]);
        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        $email    = mb_strtolower(trim((string) $this->request->input('email', '')));
        $password = (string) $this->request->input('password', '');
        $return   = $this->safeReturn((string) $this->request->input('return', ''));
        $attemptIdentifier = $this->authService->loginAttemptIdentifier($email);

        try {
            $result = \YangSheep\CRM\Auth\ThrottleAdmission::run('customer', $attemptIdentifier, $throttleIp,
                function () use ($throttleIp, $attemptIdentifier, $email, $password, $ip): array {
                    if ($this->throttle->isLocked($throttleIp, $attemptIdentifier)) {
                        return ['reason' => 'locked'];
                    }
                    $result = $this->authService->attempt($email, $password);
                    $this->throttle->record($ip, $attemptIdentifier, $result['reason'] === 'ok');
                    return $result;
                });
        } catch (\Throwable) {
            $this->backWithError('驗證服務暫時無法使用，請稍後再試。');
        }
        if ($result['reason'] === 'locked') {
            $this->auditLog->log(0, 'portal_login_locked', 'customer_user', null,
                ['email' => $email, 'ip' => $ip], $ip);
            $this->backWithError('登入嘗試次數過多，請 15 分鐘後再試。');
        }

        if ($result['reason'] !== 'ok') {
            $this->auditLog->log(0, 'portal_login_failed', 'customer_user', null,
                ['email' => $email, 'reason' => $result['reason'], 'ip' => $ip], $ip);

            // 停用帳號明確提示；其餘一律「帳號或密碼錯誤」（避免帳號列舉）。
            if ($result['reason'] === 'inactive') {
                $this->backWithError('此帳號已停用，請聯絡我們。');
            }
            $this->backWithError('帳號或密碼錯誤。');
        }

        $user = $result['user'];

        // 成功 checkpoint 已在鎖內寫入，保留既有歷史。

        // 🔴 平面隔離：先清除任何殘留的管理員 session 痕跡，再以客戶身分重建。
        Session::remove('user');
        Session::remove('_2fa_pending');

        // 防 fixation：旋轉 session id。
        Session::regenerate();

        // 設客戶 session keys（絕不設 `user`）。
        Session::set(CustomerGuard::SESSION_USER_ID, (int) $user['id']);
        Session::set(CustomerGuard::SESSION_CUSTOMER, (int) $user['customer_id']);
        Session::set(CustomerGuard::SESSION_CONTEXT, CustomerGuard::makeContext($user));

        // 綁定 session 至 customer 平面 + IP/UA 指紋（DbSessionHandler 負責逾時/撤銷）。
        Session::bindAuth(CustomerGuard::SESSION_TYPE, (int) $user['id'], $ip, $this->request->userAgent());

        $this->authService->recordLogin((int) $user['id'], $ip);

        $this->auditLog->log(0, 'portal_login_success', 'customer_user', (int) $user['id'],
            ['customer_id' => (int) $user['customer_id'], 'ip' => $ip], $ip);

        $this->redirect($return !== '' ? $return : '/portal');
    }

    /**
     * 登出（撤銷 session + 清除）。
     */
    public function logout(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            // 即使 CSRF 失敗也安全導回登入頁（登出為冪等、無害）。
            $this->redirect('/portal/login');
        }

        $uid = CustomerGuard::id();
        if ($uid > 0) {
            $this->auditLog->log(0, 'portal_logout', 'customer_user', $uid,
                ['ip' => $this->request->ip()], $this->request->ip());
        }

        Session::revokeCurrent();
        Session::destroy();
        $this->redirect('/portal/login');
    }

    /**
     * 僅接受站內相對路徑作為登入後導回目標（防 open redirect）。
     * 必須以單一 '/' 開頭、非 '//'（協定相對）、非含控制字元。
     *
     * 白名單兩類站內目標（最保守）：
     *   - /portal...        客戶專區內頁。
     *   - /q/{token}        customer_only 報價公開頁（token 為 hex；登入後返回該報價）。
     */
    private function safeReturn(string $return): string
    {
        $return = trim($return);
        if ($return === '') {
            return '';
        }
        if (str_starts_with($return, '//')) {
            return ''; // 協定相對 URL → 拒絕。
        }
        // /portal 內頁。
        if (preg_match('#^/portal(?:/[\w\-/]*)?(?:\?[\w\-=&%./]*)?$#', $return)) {
            return $return;
        }
        // /q/{token}（報價頁；token 僅 16 進位字元）。
        if (preg_match('#^/q/[A-Fa-f0-9]{1,128}$#', $return)) {
            return $return;
        }
        return '';
    }
}
