<?php

declare(strict_types=1);

namespace YangSheep\CRM\Quote;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\AuditLog\AuditLogService;
use YangSheep\CRM\Setting\SettingService;
use YangSheep\CRM\Portal\Auth\CustomerGuard;
use YangSheep\CRM\Auth\AdminSessionPolicy;

/**
 * 公開報價頁控制器（外部訪客，§7.8）。
 *
 * 路由（routes/web.php）：
 *   GET  /q/{token}          檢視（依 visibility 控制存取；每次成功檢視記 quote_views）
 *   GET  /q/{token}/print    列印專用頁（獨立 HTML，A4 版面，自動觸發 window.print()）
 *   POST /q/{token}/unlock   visibility=password 時輸入密碼解鎖（CSRF）
 *   POST /q/{token}/sign     線上簽署（CSRF）
 *
 * Zero Trust 存取控制（deny-by-default）：
 *   - access_token 隨機不可猜（64 hex）；查無 → 404。
 *   - private          → 一律 404（不可由 token 存取，即使有正確 token）。
 *   - public／password → 匿名分享須在有效期內（Q2，QuoteSharePolicy）；已關閉、過期或未初始化
 *                         一律回與查無相同的通用 404。綁定客戶本人登入不受匿名期限影響。
 *   - public           → 分享有效即可檢視。
 *   - password         → 未解鎖先顯示密碼頁；驗證成功以 session 記住（綁定分享版本與 token，
 *                         改密碼／重新公開後舊解鎖自動失效，見 QuoteUnlockSession）。
 *   - customer_only    → 🔴 P4-2 起真正強制：須客戶登入且登入客戶 customer_id == quote.customer_id。
 *                         · 未登入 → 導 /portal/login?return=/q/{token}（登入後自動返回）。
 *                         · 已登入但非該客戶 → 404（不洩漏存在與否）。
 *                         登入客戶 id 一律取自 CustomerGuard（session），絕不取自前端。
 *
 * 安全：簽署 / 解鎖過 CSRF；金額一律以伺服器端為準（不接受前端傳金額）；
 * 簽名圖嚴格驗證（限 PNG data URL、大小上限，於 QuoteService::signQuote）。
 *
 * 自有版面（layout=public-quote）：專業報價單文件感、深藍 accent、響應式、含列印 CSS。
 */
class PublicQuoteController extends Controller
{
    private QuoteService $quoteService;
    private SettingService $settingService;
    private AuditLogService $auditLogService;

    /** 本次請求是否為管理者預覽（繞過可見性與分享期限，頁面須明確標示）。 */
    private bool $adminPreview = false;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->quoteService    = new QuoteService();
        $this->settingService  = new SettingService();
        $this->auditLogService = new AuditLogService();

        // Q1：報價內容、列印與 token 流程一律不進共用快取；同源後續請求也不帶出含 token 的完整網址。
        // X-Robots-Tag（noindex, nofollow, noarchive）由全域 SecurityHeaders 對每個回應送出，
        // 這裡刻意不再設定——重設同名標頭會覆蓋掉全域值（曾因此少了 noarchive）。
        $this->response->header('Cache-Control', 'private, no-store');
        $this->response->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * 公開檢視報價單。
     */
    /**
     * 取出「這位訪客有權看到」的報價單，否則自行處理回應並回 null。
     *
     * 🔴 【為何抽成共用方法】頁面與 PDF 兩條路徑必須套用**完全相同**的門禁。
     * 若各寫一份，PDF 這條隨時可能比頁面寬鬆 —— 而 PDF 帶著整份報價內容，
     * 一旦門禁不一致，等於開了一個繞過密碼／客戶登入的側門。
     * 共用一段程式，是唯一能保證兩者不會分歧的做法。
     *
     * @return array<string,mixed>|null null 代表已經回應（404／密碼頁／導登入），呼叫端應直接 return
     */
    private function resolveAccessibleQuote(string $token): ?array
    {
        $quote = $this->quoteService->getQuoteByToken($token);

        // 查無 token：404（不洩漏存在與否）。
        if ($quote === null) {
            $this->notFound();
            return null;
        }

        // 管理員預覽：後台頁面會連結到公開 URL（列印 / PDF / 預覽），
        // 管理員須通過與後台等價的身份驗證才能繞過 visibility 與匿名分享期限；頁面會明確標示為預覽。
        if ($this->isVerifiedAdmin()) {
            $this->adminPreview = true;
            return $quote;
        }

        // 🔴 customer_only 強制：傳入登入客戶 id（CustomerGuard，session；未登入回 0）。
        $access = $this->quoteService->checkAccess(
            $quote,
            QuoteUnlockSession::isUnlocked($quote),
            CustomerGuard::customerId()
        );

        if ($access['allowed']) {
            return $quote;
        }

        // 🔴 deny-by-default：只有兩種拒絕需要不同的回應，其餘（private、他人的 customer_only、
        // 匿名分享已關閉／過期／未初始化，以及日後新增的任何理由）一律回與查無相同的通用 404，
        // 不讓回應差異洩漏報價存在與否。
        if ($access['reason'] === 'need_password') {
            $this->renderPasswordPage($quote, null);
        } elseif ($access['reason'] === 'need_login') {
            $this->redirectToPortalLogin($token);
        } else {
            $this->notFound();
        }
        return null;
    }

    /**
     * 下載 PDF。
     *
     * 【為什麼要有 PDF，而不是叫使用者用瀏覽器列印】
     * Chrome 的頁首頁尾（日期／標題／**網址**／頁碼）由使用者的偏好控制，
     * 網頁無法關閉。報價單是要寄給客戶的文件，上面印著後台網址並不合適。
     * 另外 Chrome 不支援 CSS 的 `@bottom-right { counter(page) }`，
     * 「第 X 頁／共 Y 頁」也只能靠瀏覽器那組頁尾 —— 兩件事同一個成因。
     * 產成 PDF 之後，頁碼由我們自己畫，且不夾帶任何網址。
     */
    /**
     * 列印專用頁：獨立全頁 HTML，版面對齊 QuotePdfDocument（表格式 A4），
     * 載入後自動觸發 window.print()。
     *
     * 與 pdf() 走同一段門禁（resolveAccessibleQuote），與 PDF 同理不記為「已檢視」。
     */
    public function printPage(): void
    {
        $token = (string) $this->request->param('token');
        $quote = $this->resolveAccessibleQuote($token);
        if ($quote === null) {
            return;
        }

        // 不套 layout：view 本身是完整 HTML 頁面。
        $this->render('public/quote/print', [
            'title'        => $quote['quote_number'] . ' — 列印',
            'quote'        => $quote,
            'company'      => $this->companyInfo(),
            'quoteTheme'   => (new QuoteTheme())->resolve(),
            'statusLabels' => QuoteController::statusLabels(),
        ]);
    }

    public function pdf(): void
    {
        $token = (string) $this->request->param('token');
        $quote = $this->resolveAccessibleQuote($token);
        if ($quote === null) {
            return;
        }

        // Historical links use the same print-first workflow, independent of mPDF.
        $this->response->redirect('/q/' . rawurlencode($token) . '/print');
    }

    public function show(): void
    {
        $token = (string) $this->request->param('token');
        $quote = $this->resolveAccessibleQuote($token);
        if ($quote === null) {
            return;
        }

        // 允許檢視：記錄瀏覽（首次被檢視且 status=sent 時自動轉 viewed）。
        // 若為登入客戶，記其 customer_user_id 於瀏覽軌跡。
        // 管理者預覽不記：否則後台自己點一下，報價就會顯示成「客戶已檢視」。
        if (!$this->adminPreview) {
            $this->quoteService->recordView(
                $quote,
                $this->request->ip(),
                $this->request->userAgent(),
                CustomerGuard::check() ? CustomerGuard::id() : null
            );
        }

        // 重新取最新狀態（recordView 可能已把 sent → viewed）。
        $fresh = $this->quoteService->getQuoteByToken($token) ?? $quote;

        $this->renderQuotePage($fresh);
    }

    /**
     * 密碼解鎖（visibility=password）。
     */
    public function unlock(): void
    {
        $token = (string) $this->request->param('token');
        $quote = $this->quoteService->getQuoteByToken($token);

        if ($quote === null) {
            $this->notFound();
            return;
        }

        // 非 password 模式不應走此路由：導回檢視頁。
        if (($quote['visibility'] ?? '') !== 'password') {
            $this->response->redirect('/q/' . $token);
        }

        // 分享已關閉／過期：不再接受密碼嘗試，回應與查無完全相同。
        if (!$this->quoteService->isAnonymousShareActive($quote)) {
            $this->notFound();
            return;
        }

        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->renderPasswordPage($quote, '驗證失敗，請重新嘗試。');
            return;
        }

        // 節流（Zero Trust）：token 外洩後防 access_password 暴力猜測（per-quote + per-ip）。
        $throttle = new QuoteAccessThrottle();
        $recordIp = $this->request->ip();
        $ip       = $this->request->clientIpForThrottle();
        $qid      = (int) $quote['id'];
        $input = (string) $this->request->input('access_password', '');
        try {
            $admission = \YangSheep\CRM\Auth\ThrottleAdmission::run(QuoteAccessThrottle::UNLOCK_SCOPE, 'q' . $qid, $ip,
                function () use ($throttle, $qid, $ip, $recordIp, $quote, $input): string {
        if ($throttle->isQuoteLocked(QuoteAccessThrottle::UNLOCK_SCOPE, $qid, QuoteAccessThrottle::UNLOCK_QUOTE_MAX, QuoteAccessThrottle::UNLOCK_MINUTES)
            || ($ip !== null && (
                $throttle->isGlobalLocked(QuoteAccessThrottle::UNLOCK_SCOPE, $ip, QuoteAccessThrottle::UNLOCK_GLOBAL_MAX, QuoteAccessThrottle::UNLOCK_MINUTES)
                || $throttle->isLocked(QuoteAccessThrottle::UNLOCK_SCOPE, $ip, $qid, QuoteAccessThrottle::UNLOCK_MAX, QuoteAccessThrottle::UNLOCK_MINUTES)
            ))) {
            return 'locked';
        }
        if (!$this->quoteService->verifyAccessPassword($quote, $input)) {
            $throttle->record(QuoteAccessThrottle::UNLOCK_SCOPE, $recordIp, $qid, false);
            return 'invalid';
        }

        // 解鎖：清除失敗計數、以 session 記住（per-quote），導回檢視頁。
        if ($ip !== null) {
            $throttle->clear(QuoteAccessThrottle::UNLOCK_SCOPE, $ip, $qid);
        }
        $throttle->record(QuoteAccessThrottle::UNLOCK_SCOPE, $recordIp, $qid, true);
        return 'ok';
                });
        } catch (\Throwable) {
            $admission = 'unavailable';
        }
        if ($admission !== 'ok') {
            $this->renderPasswordPage($quote, $admission === 'invalid' ? '密碼錯誤，請再試一次。' : '驗證服務忙碌或嘗試次數過多，請稍後再試。');
            return;
        }
        QuoteUnlockSession::markUnlocked($quote);
        $this->response->redirect('/q/' . $token);
    }

    /**
     * 線上簽署。
     */
    public function sign(): void
    {
        $token = (string) $this->request->param('token');
        $quote = $this->quoteService->getQuoteByToken($token);

        if ($quote === null) {
            $this->notFound();
            return;
        }

        // 存取控制：必須是可檢視狀態才可簽署（private 拒、password 須已解鎖、
        // customer_only 須登入且為該客戶）。一律以 CustomerGuard（session）判斷登入客戶。
        $access = $this->quoteService->checkAccess(
            $quote,
            QuoteUnlockSession::isUnlocked($quote),
            CustomerGuard::customerId()
        );
        if (!$access['allowed']) {
            // 不可簽署：依原因導向對應頁。
            if ($access['reason'] === 'need_password') {
                $this->renderPasswordPage($quote, '請先輸入密碼以檢視並簽署。');
                return;
            }
            if ($access['reason'] === 'need_login') {
                $this->redirectToPortalLogin($token);
                return;
            }
            // private / forbidden / share_closed → 與查無相同的 404。
            $this->notFound();
            return;
        }

        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            Session::flash('error', '驗證失敗，請重新整理後再簽署。');
            $this->response->redirect('/q/' . $token);
        }

        // 節流（Zero Trust）：防簽署端點被自動化濫發（per-quote + per-ip）。每次提交計一次。
        $throttle = new QuoteAccessThrottle();
        $recordIp = $this->request->ip();
        $ip       = $this->request->clientIpForThrottle();
        $qid      = (int) $quote['id'];
        try {
            $admitted = \YangSheep\CRM\Auth\ThrottleAdmission::run(QuoteAccessThrottle::SIGN_SCOPE, 'q' . $qid, $ip,
                function () use ($throttle, $qid, $ip, $recordIp): bool {
        if ($throttle->isQuoteLocked(QuoteAccessThrottle::SIGN_SCOPE, $qid, QuoteAccessThrottle::SIGN_QUOTE_MAX, QuoteAccessThrottle::SIGN_MINUTES)
            || ($ip !== null && (
                $throttle->isGlobalLocked(QuoteAccessThrottle::SIGN_SCOPE, $ip, QuoteAccessThrottle::SIGN_GLOBAL_MAX, QuoteAccessThrottle::SIGN_MINUTES)
                || $throttle->isLocked(QuoteAccessThrottle::SIGN_SCOPE, $ip, $qid, QuoteAccessThrottle::SIGN_MAX, QuoteAccessThrottle::SIGN_MINUTES)
            ))) {
            return false;
        }
        $throttle->record(QuoteAccessThrottle::SIGN_SCOPE, $recordIp, $qid, false);
        return true;
                });
        } catch (\Throwable) {
            $admitted = false;
        }
        if (!$admitted) {
            Session::flash('error', '操作過於頻繁或服務忙碌，請稍後再試。');
            $this->response->redirect('/q/' . $token);
        }

        $signerName    = (string) $this->request->input('signer_name', '');
        $signatureData = (string) $this->request->input('signature_data', '');

        // 簽署人類型：登入客戶簽署記為 'customer'，否則 'guest'（公開連結）。
        $signerType = CustomerGuard::check() ? 'customer' : 'guest';

        try {
            // 交易內重驗：以本次請求開始時看到的授權版本為準；經匿名分享存取時另驗期限。
            $signatureId = $this->quoteService->signQuote(
                $quote,
                $signerName,
                $signatureData,
                $this->request->ip(),
                $this->request->userAgent(),
                $signerType,
                [
                    'revision'       => (int) ($quote['share_policy_revision'] ?? 0),
                    'require_active' => ($access['via'] ?? '') === 'anonymous',
                ]
            );

            // 稽核（actor 為 0=匿名/訪客；target 為報價）。
            $this->auditLogService->log(
                0,
                'quote_signed',
                'quote',
                (int) $quote['id'],
                ['signature_id' => $signatureId, 'signer' => trim($signerName)],
                $this->request->ip()
            );

            Session::flash('success', '簽署完成，感謝您！');
            $this->response->redirect('/q/' . $token);
        } catch (QuoteShareClosedException) {
            // 開頁後分享才關閉／到期：不附任何 flash，回應與查無相同。
            $this->notFound();
        } catch (QuoteShareChangedException $e) {
            Session::flash('error', $e->getMessage());
            $this->response->redirect('/q/' . $token);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->response->redirect('/q/' . $token);
        }
    }

    // ───────────────────────── 渲染輔助 ─────────────────────────

    /**
     * 渲染報價單檢視頁（含簽名區、列印按鈕）。
     *
     * @param array<string, mixed> $quote 含 items
     */
    private function renderQuotePage(array $quote): void
    {
        // flash（success/error）由 Controller::render 自動注入 $_flash，layout 直接取用。
        $this->view->layout('public-quote');
        $this->render('public/quote/show', [
            'title'        => $quote['quote_number'] . '｜' . $quote['title'],
            'quote'        => $quote,
            'company'      => $this->companyInfo(),
            'quoteTheme'   => (new QuoteTheme())->resolve(),
            'statusLabels' => QuoteController::statusLabels(),
            'adminPreview' => $this->adminPreview,
            'shareView'    => $this->adminPreview ? $this->quoteService->shareView($quote) : null,
        ]);
    }

    /**
     * 渲染密碼輸入頁。
     */
    private function renderPasswordPage(array $quote, ?string $error): void
    {
        // $_csrf 由 Controller::render 自動注入，密碼表單直接取用。
        $this->view->layout('public-quote');
        $this->render('public/quote/password', [
            'title'   => '需要密碼',
            'token'   => (string) $quote['access_token'],
            'company' => $this->companyInfo(),
            'quoteTheme' => (new QuoteTheme())->resolve(),
            'error'   => $error,
        ]);
    }

    /**
     * customer_only 報價未登入：導向客戶專區登入頁，帶 return 讓登入後自動返回本報價。
     * return 僅為站內 /q/{token} 路徑（CustomerAuthController 另以白名單再驗，雙重防 open redirect）。
     */
    private function redirectToPortalLogin(string $token): void
    {
        $return = '/q/' . rawurlencode($token);
        Session::flash('info', '此報價單僅限客戶檢視，請先登入客戶專區。');
        $this->response->redirect('/portal/login?return=' . rawurlencode($return));
    }

    /**
     * 404：報價不存在或不可存取。
     */
    private function notFound(): void
    {
        $this->response->status(404);
        $this->view->layout('public-quote');
        $this->render('public/quote/not-found', [
            'title'   => '報價連結無法使用',
            'company' => $this->companyInfo(),
            'quoteTheme' => (new QuoteTheme())->resolve(),
        ]);
    }

    /**
     * 我方公司資訊（讀 settings company 群組 + site logo）。
     *
     * @return array<string, string>
     */
    private function companyInfo(): array
    {
        $g = $this->settingService->getGroup('company');
        return [
            'name'    => (string) ($g['company_name'] ?? ''),
            'tax_id'  => (string) ($g['company_tax_id'] ?? ''),
            'address' => (string) ($g['company_address'] ?? ''),
            'phone'   => (string) ($g['company_phone'] ?? ''),
            'email'   => (string) ($g['company_email'] ?? ''),
            'contact' => (string) ($g['company_contact'] ?? ''),
            'seal'    => (string) ($g['company_seal'] ?? ''),
            'logo'    => (string) ($this->settingService->get('site', 'site_logo') ?? ''),
        ];
    }

    /**
     * 管理員身份驗證（與後台中間層等價）。
     *
     * 公開路由不走後台中間層；使用完整唯讀驗證，失敗後回到一般報價門禁。
     */
    private function isVerifiedAdmin(): bool
    {
        return AdminSessionPolicy::allows($this->request, 'quotes.view');
    }
}
