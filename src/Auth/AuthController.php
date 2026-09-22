<?php

declare(strict_types=1);

namespace YangSheep\CRM\Auth;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Core\Validator;
use YangSheep\CRM\AuditLog\AuditLogService;
use YangSheep\CRM\Setting\SettingService;

class AuthController extends Controller
{
    private AuthService $authService;
    private LoginAttemptService $loginAttemptService;
    private TurnstileVerifier $turnstileVerifier;
    private AuditLogService $auditLogService;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->authService = new AuthService();
        $this->loginAttemptService = new LoginAttemptService();
        $this->turnstileVerifier = new TurnstileVerifier();
        $this->auditLogService = new AuditLogService();
    }

    /**
     * 顯示登入表單
     */
    public function loginForm(): void
    {
        // 已登入則導向後台
        if ($this->authService->check()) {
            $this->redirect('/admin/dashboard');
        }

        $this->view->layout('auth');
        $this->render('auth/login', [
            'title'    => '登入',
            'siteKey'  => $_ENV['TURNSTILE_SITE_KEY'] ?? '',
        ]);
    }

    /**
     * 處理登入請求
     */
    public function login(): void
    {
        $ip         = $this->request->ip();
        $throttleIp = $this->request->clientIpForThrottle();

        // 驗證 CSRF
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF Token 驗證失敗，請重新整理頁面。');
        }

        // 驗證輸入
        $validator = Validator::make($this->request->all(), [
            // 這個欄位收的是「帳號或 Email」。上限必須對齊 email 欄位的 255，
            // 用 username 的 50 會讓較長的 email 在送出前就被擋掉，
            // 而使用者只會看到一句無關的驗證錯誤。
            'username' => 'required|string|min:2|max:255',
            'password' => 'required|string|min:1',
        ]);

        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        $username = trim($this->request->input('username', ''));
        $password = $this->request->input('password', '');

        // 檢查 Turnstile 驗證
        $turnstileToken = $this->request->input('cf-turnstile-response');
        if (!$this->turnstileVerifier->verify($turnstileToken, $ip)) {
            $this->backWithError('人機驗證失敗，請重試。');
        }

        // 先以實際認證查詢解析 account rail key。不能只對 request 字串做
        // mb_strtolower：MySQL collation 可能把更多字串視為同一帳號。
        $attemptIdentifier = $this->authService->loginAttemptIdentifier($username);

        try {
            $admission = ThrottleAdmission::run('admin', $attemptIdentifier, $throttleIp,
                function () use ($throttleIp, $attemptIdentifier, $username, $password, $ip): array {
                    if ($this->loginAttemptService->isLocked($throttleIp, $attemptIdentifier)) {
                        return ['locked' => true, 'user' => null];
                    }
                    $user = $this->authService->attempt($username, $password);
                    $this->loginAttemptService->record($ip, $attemptIdentifier, $user !== null);
                    return ['locked' => false, 'user' => $user];
                });
        } catch (\Throwable) {
            $this->backWithError('驗證服務暫時無法使用，請稍後再試。');
        }
        if ($admission['locked']) {
            $this->backWithError('登入嘗試次數過多，請 15 分鐘後再試。');
        }
        $user = $admission['user'];

        if ($user === null) {
            $this->auditLogService->log(0, 'login_failed', 'user', null, [
                'username' => $username,
                'ip'       => $ip,
            ], $ip);
            $this->backWithError('帳號或密碼錯誤。');
        }

        // 成功 checkpoint 已在 admission 鎖內寫入；不刪除歷史。

        // 🔴 2FA 閘道：若帳號已啟用 TOTP，密碼通過「不」代表登入完成。
        // 僅暫存 pending（uid + 時間，不存整個 user），導向第二階段驗證頁。
        // 此處絕不可呼叫 setUser()/bindAuth()——redirect():never 會 exit。
        if ((int) ($user['totp_enabled'] ?? 0) === 1) {
            // 將 pending 票綁定 UA 指紋：讓 challenge 階段的 session 完整性對齊已登入階段，
            // 避免 challenge 期間 _2fa_pending 被劫持者於不同 UA 環境接管。
            // IP 不綁（CDN/反代下 client IP 浮動），只綁 UA hash。
            Session::set('_2fa_pending', [
                'uid'     => (int) $user['id'],
                'at'      => time(),
                'ua_hash' => hash('sha256', $this->request->userAgent()),
            ]);
            $this->auditLogService->log(
                (int) $user['id'],
                '2fa_challenge_required',
                'user',
                (int) $user['id'],
                ['ip' => $ip],
                $ip
            );
            $this->redirect('/login/2fa');
        }

        try {
            AdminLoginSession::establish($user, $this->request,
                (new SettingService())->get('security', 'single_session') === '1');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }

        // 記錄審計日誌
        $this->auditLogService->log(
            (int) $user['id'],
            'login_success',
            'user',
            (int) $user['id'],
            ['ip' => $ip],
            $ip
        );

        $this->redirect('/admin/dashboard');
    }

    /**
     * 登出
     */
    public function logout(): void
    {
        $user = $this->authService->currentUser();

        if ($user) {
            $this->auditLogService->log(
                (int) $user['id'],
                'logout',
                'user',
                (int) $user['id'],
                ['ip' => $this->request->ip()],
                $this->request->ip()
            );
        }

        // 對齊 portal 登出：先於 DB 層明確撤銷當前 session 列（標記 revoked），
        // 再銷毀 cookie/PHP session，避免遺留孤兒 session 列（與 CustomerAuthController::logout 一致）。
        Session::revokeCurrent();
        Session::destroy();
        $this->redirect('/login');
    }
}
