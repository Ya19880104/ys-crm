<?php

declare(strict_types=1);

namespace YangSheep\CRM\Auth;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\Encryption;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\AuditLog\AuditLogService;
use YangSheep\CRM\Setting\SettingService;

/**
 * 兩階段驗證（2FA / TOTP）+ step-up 再認證控制器。
 *
 * 零信任不變式（務必維持）：
 *  - 登入時若帳號已啟用 2FA，密碼通過後「不」呼叫 setUser()/bindAuth()，
 *    僅在 session 暫存 `_2fa_pending`（uid + 時間），導向 challenge 頁。
 *  - 只有 challengeVerify() 內 TOTP 驗證成功，才完成登入
 *    （regenerate → setUser → bindAuth）。
 *  - challengeVerify() 一定先驗 `_2fa_pending` 有效（存在 + 未逾時 + uid），再驗 TOTP。
 */
class TwoFactorController extends Controller
{
    /** challenge 待驗證的逾時秒數（密碼通過到完成 2FA 的時限） */
    private const PENDING_TTL = 300; // 5 分鐘

    /** challenge 階段允許的 TOTP 連續失敗次數上限（達到即作廢 pending，需重新登入） */
    private const MAX_CHALLENGE_FAILS = 5;

    private Database $db;
    private Encryption $encryption;
    private AuditLogService $auditLog;
    private LoginAttemptService $loginAttempts;
    private TotpReplayGuard $totpReplayGuard;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->db = Database::getInstance();
        $this->encryption = new Encryption();
        $this->auditLog = new AuditLogService();
        $this->loginAttempts = new LoginAttemptService();
        $this->totpReplayGuard = new TotpReplayGuard();
    }

    // =====================================================================
    // 任務 A：啟用 2FA（已登入使用者於後台設定）
    // =====================================================================

    /**
     * 顯示 2FA 設定頁：產生（或沿用本次設定流程的）secret，顯示供掃描/手動輸入。
     */
    public function setupForm(): void
    {
        $user = Session::get('user');
        if ($user === null) {
            $this->redirect('/login');
        }

        // 已啟用則無需再設定
        if ((int) ($user['totp_enabled'] ?? 0) === 1) {
            $this->redirectWith('/admin/dashboard', 'info', '您已啟用兩階段驗證。');
        }

        // 沿用本次設定流程暫存的 secret，避免每次刷新都換一組（造成已掃描的 QR 失效）
        $secret = Session::get('_2fa_setup_secret');
        if (!is_string($secret) || $secret === '') {
            $secret = Totp::generateSecret();
            Session::set('_2fa_setup_secret', $secret);
        }

        $issuer = $this->issuerName();
        $label = (string) ($user['email'] ?? $user['username'] ?? 'user');
        $uri = Totp::provisioningUri($secret, $label, $issuer);

        $this->view->layout('auth');
        $this->render('auth/2fa-setup', [
            'title'  => '啟用兩階段驗證',
            'secret' => $secret,
            'uri'    => $uri,
            'issuer' => $issuer,
        ]);
    }

    /**
     * 驗證設定流程輸入的碼，成功則加密儲存 secret 並啟用 2FA。
     */
    public function setupVerify(): void
    {
        $user = Session::get('user');
        if ($user === null) {
            $this->redirect('/login');
        }

        // 敏感端點：驗證成功後輪替 CSRF token（用後即換，防重放）。本端點成功即 redirect，
        // 輪替不影響當前流程；多分頁開著舊表單者需重整取新 token（取捨可接受）。
        if (!Csrf::validate($this->request->input('_csrf_token'), regenerate: true)) {
            $this->redirectWith('/admin/2fa/setup', 'error', 'CSRF Token 驗證失敗，請重試。');
        }

        $secret = Session::get('_2fa_setup_secret');
        if (!is_string($secret) || $secret === '') {
            // 設定 secret 不存在（逾時 / session 換新），重新開始
            $this->redirectWith('/admin/2fa/setup', 'error', '設定流程已逾時，請重新開始。');
        }

        // 重新查現任登入者的 fresh 資料（取 password hash，不信任 session 快照）
        $fresh = $this->db->fetch(
            "SELECT * FROM {prefix}users WHERE id = :id AND status = 'active' LIMIT 1",
            ['id' => (int) $user['id']]
        );
        if ($fresh === null) {
            Session::destroy();
            $this->redirectWith('/login', 'error', '帳號狀態已變更，請重新登入。');
        }

        // 建議 2：啟用 2FA 前必須驗證「目前密碼」，避免 session 被接管者於受害者
        // 已登入狀態下，逕自綁定攻擊者自己的 authenticator。
        $currentPassword = (string) $this->request->input('current_password', '');
        if ($currentPassword === '' || !password_verify($currentPassword, (string) $fresh['password'])) {
            $this->auditLog->log(
                (int) $fresh['id'],
                '2fa_setup_password_failed',
                'user',
                (int) $fresh['id'],
                ['ip' => $this->request->ip()],
                $this->request->ip()
            );
            $this->redirectWith('/admin/2fa/setup', 'error', '目前密碼不正確，請重新輸入。');
        }

        $code = (string) $this->request->input('code', '');

        // 取得命中的時間步以供重放保護初始化；未命中回 null。
        $matchedStep = Totp::verifyWithStep($secret, $code);
        if ($matchedStep === null) {
            $this->auditLog->log(
                (int) $fresh['id'],
                '2fa_setup_failed',
                'user',
                (int) $fresh['id'],
                ['ip' => $this->request->ip()],
                $this->request->ip()
            );
            $this->redirectWith('/admin/2fa/setup', 'error', '驗證碼不正確，請確認驗證器時間後重試。');
        }

        // 驗證成功 → 加密儲存 secret，啟用 2FA，並記錄首個已使用的時間步（重放保護基準）
        $encrypted = $this->encryption->encrypt($secret);
        $this->db->execute(
            "UPDATE {prefix}users SET totp_secret = :secret, totp_enabled = 1, totp_last_step = :step WHERE id = :id",
            ['secret' => $encrypted, 'step' => $matchedStep, 'id' => (int) $fresh['id']]
        );

        // 清除設定暫存
        Session::remove('_2fa_setup_secret');

        // 更新 session 內的 user 快照（讓 Require2faMiddleware 立即放行）
        $user['totp_enabled'] = 1;
        Session::set('user', $user);

        $this->auditLog->log(
            (int) $fresh['id'],
            '2fa_enabled',
            'user',
            (int) $fresh['id'],
            ['ip' => $this->request->ip()],
            $this->request->ip()
        );

        $this->redirectWith('/admin/dashboard', 'success', '兩階段驗證已啟用。');
    }

    // =====================================================================
    // 任務 A：登入第二階段（密碼通過後的 TOTP challenge）
    // =====================================================================

    /**
     * 顯示登入第二階段輸入頁。需 `_2fa_pending` 存在且未逾時。
     */
    public function challengeForm(): void
    {
        $pending = $this->validPending();
        if ($pending === null) {
            // 無有效 pending（未先過密碼 / 逾時）→ 回登入頁
            $this->redirect('/login');
        }

        $this->view->layout('auth');
        $this->render('auth/2fa-challenge', [
            'title' => '兩階段驗證',
        ]);
    }

    /**
     * 驗證登入第二階段的 TOTP，成功才真正完成登入。
     *
     * 順序（不可調換）：先驗 `_2fa_pending` 有效 → 查 user → 解密 secret → 驗 TOTP
     *  → regenerate → setUser → bindAuth。
     */
    public function challengeVerify(): void
    {
        // 1. 先驗 pending 有效（存在 + 未逾時 + uid）
        $pending = $this->validPending();
        if ($pending === null) {
            $this->redirectWith('/login', 'error', '驗證階段已逾時，請重新登入。');
        }

        // 2. CSRF
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->redirectWith('/login/2fa', 'error', 'CSRF Token 驗證失敗，請重試。');
        }

        $ip         = $this->request->ip();
        $throttleIp = $this->request->clientIpForThrottle();
        $uid        = (int) $pending['uid'];
        $throttleKey = '2fa:uid:' . $uid;

        // 🔴🔴 序列化同一 uid 的 2FA 嘗試（GET_LOCK），消除 isLocked/record TOCTOU 競態。
        // 沒有這道鎖，N 個並行請求能在首筆失敗可見前全部通過 isLocked(false)，
        // 讓攻擊者一波得到超過 MAX_CHALLENGE_FAILS 次猜測。
        try {
            $outcome = $this->loginAttempts->withThrottleLock($throttleKey, function () use ($pending, $ip, $throttleIp, $uid, $throttleKey): array {
                return $this->doChallengeVerify($pending, $ip, $throttleIp, $uid, $throttleKey);
            });
        } catch (\RuntimeException) {
            $this->redirectWith('/login/2fa', 'error', '系統忙碌中，請稍後重試。');
        }
        $this->redirectVerificationOutcome($outcome);
    }

    /**
     * challengeVerify 的實際邏輯（在 GET_LOCK 保護下執行）。
     *
     * @param array{uid: int, at: int, fails: int, ua_hash: string} $pending
     */
    private function doChallengeVerify(array $pending, string $ip, ?string $throttleIp, int $uid, string $throttleKey): array
    {
        // 節流檢查（在鎖內，保證 isLocked 與 record 之間沒有其他請求插入）
        if ($this->loginAttempts->isLocked($throttleIp, $throttleKey)) {
            Session::remove('_2fa_pending');
            return ['/login', 'error', '嘗試次數過多，請 15 分鐘後再試。'];
        }

        // 重新查 user（不信任 session 內任何 user 資料；必須 active + 已啟用 2FA）
        $user = $this->db->fetch(
            "SELECT * FROM {prefix}users WHERE id = :id AND status = 'active' LIMIT 1",
            ['id' => $uid]
        );

        if ($user === null || (int) ($user['totp_enabled'] ?? 0) !== 1 || empty($user['totp_secret'])) {
            Session::remove('_2fa_pending');
            $this->auditLog->log($uid, '2fa_challenge_invalid_account', 'user', $uid, ['ip' => $ip], $ip);
            return ['/login', 'error', '登入失敗，請重新登入。'];
        }

        // 解密 secret 並驗 TOTP（取命中時間步以做重放保護）
        $code = (string) $this->request->input('code', '');
        $matchedStep = null;
        try {
            $secret = $this->encryption->decrypt((string) $user['totp_secret']);
            $matchedStep = Totp::verifyWithStep($secret, $code);
        } catch (\Throwable) {
            $matchedStep = null;
        }

        // 重放保護（RFC 6238 單次）：以 affected-row CAS 原子消耗時間步。
        $ok = $matchedStep !== null
            && $this->totpReplayGuard->consume($uid, $matchedStep);

        if (!$ok) {
            // 🔴 在鎖內紀錄失敗 — 下一個等鎖的請求一定看得到這筆 INSERT。
            $this->loginAttempts->record($ip, $throttleKey, false);
            $fails = (int) ($pending['fails'] ?? 0) + 1;
            $this->auditLog->log($uid, '2fa_challenge_failed', 'user', $uid, ['ip' => $ip, 'fails' => $fails], $ip);

            if ($fails >= self::MAX_CHALLENGE_FAILS) {
                Session::remove('_2fa_pending');
                return ['/login', 'error', '驗證失敗次數過多，請重新登入。'];
            }

            Session::set('_2fa_pending', [
                'uid'     => $uid,
                'at'      => (int) $pending['at'],
                'fails'   => $fails,
                'ua_hash' => (string) ($pending['ua_hash'] ?? ''),
            ]);
            $remaining = self::MAX_CHALLENGE_FAILS - $fails;
            return ['/login/2fa', 'error', "驗證碼不正確，請重試（剩餘 {$remaining} 次）。"];
        }

        $this->loginAttempts->record($ip, $throttleKey, true);

        // 通過 → 完成登入
        unset($user['password']);

        try {
            AdminLoginSession::establish($user, $this->request,
                (new SettingService())->get('security', 'single_session') === '1');
        } catch (\RuntimeException $e) {
            return ['/login/2fa', 'error', $e->getMessage()];
        }

        $this->db->execute(
            "UPDATE {prefix}users SET last_login_at = NOW() WHERE id = :id",
            ['id' => $uid]
        );

        Session::remove('_2fa_pending');

        $this->auditLog->log(
            $uid,
            '2fa_challenge_success',
            'user',
            $uid,
            ['ip' => $this->request->ip()],
            $this->request->ip()
        );

        return ['/admin/dashboard'];
    }

    // =====================================================================
    // 任務 D：step-up 再認證（敏感操作前重新確認本人）
    // =====================================================================

    /**
     * 顯示再認證頁。需已登入。
     */
    public function reauthForm(): void
    {
        $user = Session::get('user');
        if ($user === null) {
            $this->redirect('/login');
        }

        $return = $this->safeReturn($this->request->query('return'));

        $this->view->layout('auth');
        $this->render('auth/reauth', [
            'title'         => '安全驗證',
            'return'        => $return,
            'requiresTotp'  => (int) ($user['totp_enabled'] ?? 0) === 1,
        ]);
    }

    /**
     * 驗證現任登入者的密碼（+ 若已啟用 2FA 則一併驗 TOTP），成功則記錄 step-up 時間。
     */
    public function reauthVerify(): void
    {
        $user = Session::get('user');
        if ($user === null) {
            $this->redirect('/login');
        }

        $return = $this->safeReturn($this->request->input('return'));

        if (!Csrf::validate($this->request->input('_csrf_token'), regenerate: true)) {
            $this->redirectWith('/admin/reauth?return=' . rawurlencode($return), 'error', 'CSRF Token 驗證失敗，請重試。');
        }

        $uid = (int) $user['id'];
        $ip         = $this->request->ip();
        $throttleIp = $this->request->clientIpForThrottle();
        $throttleKey = 'reauth:uid:' . $uid;

        // 🔴🔴 序列化同一 uid 的 reauth 嘗試，消除 isLocked/record TOCTOU 競態。
        try {
            $outcome = $this->loginAttempts->withThrottleLock($throttleKey, function () use ($uid, $ip, $throttleIp, $throttleKey, $return): array {
                return $this->doReauthVerify($uid, $ip, $throttleIp, $throttleKey, $return);
            });
        } catch (\RuntimeException) {
            $this->redirectWith('/admin/reauth?return=' . rawurlencode($return), 'error', '系統忙碌中，請稍後重試。');
        }
        $this->redirectVerificationOutcome($outcome);
    }

    /**
     * reauthVerify 的實際邏輯（在 GET_LOCK 保護下執行）。
     */
    private function doReauthVerify(int $uid, string $ip, ?string $throttleIp, string $throttleKey, string $return): array
    {
        if ($this->loginAttempts->isLocked($throttleIp, $throttleKey)) {
            return ['/admin/reauth?return=' . rawurlencode($return), 'error', '嘗試次數過多，請 15 分鐘後再試。'];
        }

        $password = (string) $this->request->input('password', '');

        $fresh = $this->db->fetch(
            "SELECT * FROM {prefix}users WHERE id = :id AND status = 'active' LIMIT 1",
            ['id' => $uid]
        );

        if ($fresh === null) {
            Session::destroy();
            return ['/login', 'error', '帳號狀態已變更，請重新登入。'];
        }

        $passOk = $password !== '' && password_verify($password, (string) $fresh['password']);

        $totpOk = true;
        $matchedStep = null;
        if ((int) ($fresh['totp_enabled'] ?? 0) === 1) {
            $code = (string) $this->request->input('code', '');
            $totpOk = false;
            try {
                if (!empty($fresh['totp_secret'])) {
                    $secret = $this->encryption->decrypt((string) $fresh['totp_secret']);
                    $matchedStep = Totp::verifyWithStep($secret, $code);
                    $totpOk = $matchedStep !== null;
                }
            } catch (\Throwable) {
                $totpOk = false;
            }
        }

        if ($passOk && $totpOk && $matchedStep !== null) {
            $totpOk = $this->totpReplayGuard->consume($uid, $matchedStep);
        }

        if (!$passOk || !$totpOk) {
            $this->loginAttempts->record($ip, $throttleKey, false);
            $this->auditLog->log($uid, 'reauth_failed', 'user', $uid, ['ip' => $ip], $ip);
            return [
                '/admin/reauth?return=' . rawurlencode($return),
                'error',
                '驗證失敗，請確認密碼' . ((int) ($fresh['totp_enabled'] ?? 0) === 1 ? '與驗證碼' : '') . '。'
            ];
        }

        $this->loginAttempts->record($ip, $throttleKey, true);
        Session::set('_stepup_at', time());

        $this->auditLog->log(
            $uid,
            'reauth_success',
            'user',
            $uid,
            ['ip' => $this->request->ip(), 'return' => $return],
            $this->request->ip()
        );

        return [$return];
    }

    /** Exit only after the throttle callback returned and its finally released the lock. */
    private function redirectVerificationOutcome(array $outcome): never
    {
        if (isset($outcome[1], $outcome[2])) {
            $this->redirectWith($outcome[0], $outcome[1], $outcome[2]);
        }
        $this->redirect($outcome[0]);
    }

    // =====================================================================
    // 內部輔助
    // =====================================================================

    /**
     * 取得有效的登入第二階段 pending 狀態；無效（不存在 / 結構錯 / 逾時 / 無 uid / UA 不符）回 null。
     *
     * UA 綁定：建立 pending 時（AuthController::login）已存入當下請求的 ua_hash；
     * 此處比對當前請求 UA hash 與 pending 內 ua_hash，不符即作廢 pending（移除 session key）
     * 並回 null，迫使重新登入。讓 challenge 階段 session 完整性對齊已登入階段。
     * IP 不綁（CDN/反代下 client IP 浮動），只綁 UA。
     *
     * @return array{uid:int, at:int, fails:int, ua_hash:string}|null
     */
    private function validPending(): ?array
    {
        $pending = Session::get('_2fa_pending');
        if (!is_array($pending)) {
            return null;
        }

        $uid = (int) ($pending['uid'] ?? 0);
        $at = (int) ($pending['at'] ?? 0);

        if ($uid <= 0 || $at <= 0) {
            return null;
        }

        // 逾時檢查
        if (time() - $at > self::PENDING_TTL) {
            Session::remove('_2fa_pending');
            return null;
        }

        // UA 指紋檢查：與已登入階段（SessionIntegrityMiddleware）一致的綁定策略。
        // 僅在 pending 內存有 ua_hash 時比對（向後相容舊結構）；不符即作廢。
        $uaHash = (string) ($pending['ua_hash'] ?? '');
        if ($uaHash !== '' && !hash_equals($uaHash, hash('sha256', $this->request->userAgent()))) {
            Session::remove('_2fa_pending');
            return null;
        }

        return [
            'uid'     => $uid,
            'at'      => $at,
            'fails'   => (int) ($pending['fails'] ?? 0),
            'ua_hash' => $uaHash,
        ];
    }

    /**
     * 取得發行者名稱（provisioning URI 用），優先採站台名稱。
     */
    private function issuerName(): string
    {
        $name = $_ENV['APP_NAME'] ?? '';
        if (!is_string($name) || trim($name) === '') {
            $name = 'YS CRM';
        }
        return $name;
    }

    /**
     * 將 return 參數限制為本站內部相對路徑，避免 open redirect。
     * 只允許以單一 '/' 開頭、且非 '//'（協定相對）或反斜線的路徑；否則回退安全預設。
     */
    private function safeReturn(mixed $return): string
    {
        $default = '/admin/dashboard';
        if (!is_string($return) || $return === '') {
            return $default;
        }

        // 必須是站內絕對路徑
        if ($return[0] !== '/') {
            return $default;
        }
        // 排除協定相對網址 //evil.com 與含反斜線的繞過
        if (str_starts_with($return, '//') || str_contains($return, '\\')) {
            return $default;
        }
        // 排除任何控制字元 / 換行
        if (preg_match('/[\x00-\x1F\x7F]/', $return)) {
            return $default;
        }

        return $return;
    }
}
