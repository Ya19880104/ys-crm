<?php

declare(strict_types=1);

namespace YangSheep\CRM\Payment;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\AuditLog\AuditLogService;
use YangSheep\CRM\Quote\QuoteService;
use YangSheep\CRM\Quote\QuoteShareChangedException;
use YangSheep\CRM\Quote\QuoteShareClosedException;
use YangSheep\CRM\Quote\QuoteUnlockSession;
use YangSheep\CRM\Payment\Providers\SandboxProvider;
use YangSheep\CRM\Portal\Auth\CustomerGuard;

/**
 * 公開付款控制器（外部訪客，免登入；對應架構設計 §7.9）。
 *
 * 路由（routes/web.php）：
 *   POST /q/{token}/pay              由已簽署且 payment_enabled 的報價發動付款（CSRF 群組內）
 *   GET  /pay/return/{provider}      gateway 導回的瀏覽器頁（顯示結果；實際入帳以 callback 為準）
 *   POST /pay/callback/{provider}    server-to-server webhook（★ CSRF 豁免：以 provider 驗章）
 *   GET  /pay/sandbox/{payment_no}   沙盒確認頁（模擬成功/失敗）
 *   POST /pay/sandbox/{payment_no}   沙盒送出（★ CSRF 豁免：本層自驗 CSRF + 沙盒簽章後打 callback）
 *
 * Zero Trust：
 *   - 發動付款驗 CSRF；金額一律伺服器端從 quote.total 重算（PaymentService）。
 *   - webhook 免 CSRF 但「必須驗章」（provider->verifyCallback）；驗章失敗回 4xx 不入帳。
 *   - 冪等：confirmPaid 內 FOR UPDATE 鎖 + 已 paid 短路 + provider_txn_id 唯一索引。
 *   - sandbox 送出在 CSRF 群組外，故本層自行驗證 CSRF（防 CSRF 觸發模擬付款）。
 */
class PublicPaymentController extends Controller
{
    private PaymentService $paymentService;
    private PaymentProviderRegistry $registry;
    private QuoteService $quoteService;
    private AuditLogService $auditLogService;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->paymentService  = new PaymentService();
        $this->registry        = new PaymentProviderRegistry();
        $this->quoteService    = new QuoteService();
        $this->auditLogService = new AuditLogService();

        // Q1：付款入口與結果頁不進共用快取。Referrer 用 strict-origin 而不是報價頁的 no-referrer：
        // 只送出站台 origin、永不帶含 token 的路徑，同時保留部分金流商驗證來源網域所需的 Referer。
        $this->response->header('Cache-Control', 'private, no-store');
        $this->response->header('Referrer-Policy', 'strict-origin');
    }

    // ───────────────────────── 由報價發動付款 ─────────────────────────

    /**
     * GET /q/{token}/pay
     * 付款方式選擇頁（信用卡 / 虛擬 ATM）。報價簽署後由公開頁「前往付款」按鈕連入。
     * 僅在「可檢視 + 已簽署 + 已啟用付款 + 未付款」時顯示；否則導回報價頁。
     * 實際發動付款由本頁兩個方式表單 POST /q/{token}/pay（pay()）處理。
     */
    public function payPage(): void
    {
        $token = (string) $this->request->param('token');
        $quote = $this->quoteService->getQuoteByToken($token);

        if ($quote === null) {
            $this->paymentNotFound();
            return;
        }

        // 存取控制（與報價公開頁 / pay() 一致）；customer_only 需帶登入客戶 id。
        // 匿名分享已關閉／過期 → 導回報價頁，由該頁回與查無相同的通用 404。
        $access = $this->quoteService->checkAccess($quote, QuoteUnlockSession::isUnlocked($quote), CustomerGuard::customerId());
        if (!$access['allowed']) {
            $this->response->redirect('/q/' . $token);
        }

        // 必須已簽署 + 啟用付款 + 未付款，否則導回報價頁（由報價頁顯示簽署或已付款狀態）。
        if (($quote['status'] ?? '') !== 'signed'
            || (int) ($quote['payment_enabled'] ?? 0) !== 1
            || ($quote['payment_status'] ?? '') === 'paid'
            || ($quote['status'] ?? '') === 'paid'
        ) {
            $this->response->redirect('/q/' . $token);
        }

        $this->view->layout('public-quote');
        $this->render('public/payment/select', [
            'title'   => '付款方式',
            'company' => $this->companyInfo(),
            'quote'   => $quote,
            'token'   => $token,
        ]);
    }

    /**
     * POST /q/{token}/pay
     * 報價公開頁的「前往付款」表單。CSRF 已由 web.php 群組驗證。
     */
    public function pay(): void
    {
        $token = (string) $this->request->param('token');
        $quote = $this->quoteService->getQuoteByToken($token);

        if ($quote === null) {
            $this->paymentNotFound();
            return;
        }

        // 存取控制（與報價公開頁一致）：須可檢視才可付款。customer_only 報價需帶登入客戶 id
        // （讓登入的該客戶可付自己的 customer_only 報價；他客戶/未登入則 deny）。
        $access = $this->quoteService->checkAccess($quote, QuoteUnlockSession::isUnlocked($quote), CustomerGuard::customerId());
        if (!$access['allowed']) {
            // 無法檢視 → 導回報價頁（由報價頁處理密碼/登入）。
            $this->response->redirect('/q/' . $token);
        }

        // 必須已簽署 + 已啟用付款 + 尚未付款。
        if (($quote['status'] ?? '') !== 'signed' && ($quote['payment_status'] ?? '') !== 'partial') {
            // 僅允許已簽署的報價付款（signed 才出現付款入口）。
            Session::flash('error', '請先完成簽署再進行付款。');
            $this->response->redirect('/q/' . $token);
        }
        if ((int) ($quote['payment_enabled'] ?? 0) !== 1) {
            Session::flash('error', '此報價單未啟用線上付款。');
            $this->response->redirect('/q/' . $token);
        }
        if (($quote['payment_status'] ?? '') === 'paid' || ($quote['status'] ?? '') === 'paid') {
            Session::flash('info', '此報價單已完成付款。');
            $this->response->redirect('/q/' . $token);
        }

        // 付款方式（信用卡 / 虛擬 ATM）；公開頁付款方式選擇器送出 method 欄位。
        $method = (string) $this->request->input('method', 'credit');
        $method = in_array($method, ['credit', 'atm'], true) ? $method : 'credit';

        // 冪等鍵：僅綁定報價（與客戶 Portal 一致），同一張報價只會有「一筆」pending，
        // 避免不同付款方式/不同入口裂解成多筆 pending 而被各自 gateway 重複入帳（重複扣款）。
        // 改付款方式時由 initiateForQuote 更新既有 pending 的 method，而非新建。
        $idempotencyKey = 'quote_' . (int) $quote['id'] . '_pay';

        $returnUrl   = $this->absoluteUrl('/pay/return/' . $this->registry->resolve()->key());
        $callbackUrl = $this->absoluteUrl('/pay/callback/' . $this->registry->resolve()->key());

        // 發動付款是真正的提交點：在建立 pending 付款（外部發派意圖）的同一交易內鎖列重驗
        // 分享授權，不信任開頁時的判斷。經匿名分享存取時另驗期限；客戶本人登入則只比對版本。
        $accessGuard = function () use ($quote, $access): void {
            $this->quoteService->lockAndRecheckAnonymousShare(
                (int) $quote['id'],
                (int) ($quote['share_policy_revision'] ?? 0),
                ($access['via'] ?? '') === 'anonymous'
            );
        };

        try {
            $result   = $this->paymentService->initiateForQuote($quote, $idempotencyKey, $returnUrl, $callbackUrl, $method, $accessGuard);
            $checkout = $result['checkout'];
        } catch (QuoteShareClosedException) {
            // 開頁後分享才關閉／到期：不附 flash，由報價頁回與查無相同的通用 404。
            $this->response->redirect('/q/' . $token);
        } catch (QuoteShareChangedException $e) {
            Session::flash('error', $e->getMessage());
            $this->response->redirect('/q/' . $token);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->response->redirect('/q/' . $token);
        }

        // 依 mode 導向 gateway。
        $this->dispatchCheckout($checkout);
    }

    // ───────────────────────── gateway 瀏覽器導回 ─────────────────────────

    /**
     * GET /pay/return/{provider}
     * gateway 付款後的瀏覽器導回頁。僅顯示結果，實際入帳以 webhook 為準。
     */
    public function paymentReturn(): void
    {
        // 嘗試由查詢字串取 payment_no（各 provider 導回參數不同；此為通用顯示）。
        $paymentNo = (string) ($this->request->query('payment_no', '')
            ?: $this->request->query('MerTradeNo', '')
            ?: $this->request->query('referenceId', ''));

        $payment = $paymentNo !== '' ? $this->paymentService->findByNo($paymentNo) : null;

        $this->view->layout('public-quote');
        $this->render('public/payment/result', [
            'title'        => '付款結果',
            'company'      => $this->companyInfo(),
            'payment'      => $payment,
            'statusLabels' => PaymentController::statusLabels(),
        ]);
    }

    // ───────────────────────── webhook（CSRF 豁免，驗章入帳） ─────────────────────────

    /**
     * POST /pay/callback/{provider}
     * server-to-server webhook。★ 不經 CSRF middleware；以 provider->verifyCallback 驗章。
     * 驗章失敗回 4xx 不入帳；成功則 confirmPaid（冪等 + 金額比對）。
     */
    public function callback(): void
    {
        $providerKey = (string) $this->request->param('provider');

        // 未知 provider → 400（不 fallback，避免以錯誤 provider 驗章誤判）。
        if (!$this->registry->has($providerKey)) {
            $this->response->status(400)->html('unknown provider');
            return;
        }
        $provider = $this->registry->get($providerKey);

        // 取回呼內容：表單 POST + JSON body 皆已由 Request 併入 post；
        // 另備原始 body（Shopline 簽章需原始字串）。
        $post   = $this->request->all();
        $rawBody = $this->rawBody();
        $server = $_SERVER;
        $server['__raw_body'] = $rawBody;

        $verified = $provider->verifyCallback($post, $server);

        // 驗章失敗 → 4xx，絕不入帳。
        if (($verified['ok'] ?? false) !== true) {
            $this->auditLogService->log(0, 'payment_callback_invalid_sign', 'payment', null,
                ['provider' => $providerKey], $this->request->ip());
            $this->response->status(400)->html('invalid signature');
            return;
        }

        // 取 payment_no：優先用 provider 驗章/解密後回傳的 reference（最可靠），
        // 回退到各家回呼外層欄位。
        $paymentNo = (string) ($verified['reference'] ?? '');
        if ($paymentNo === '') {
            $paymentNo = $this->resolvePaymentNoFromCallback($providerKey, $post, $rawBody);
        }
        if ($paymentNo === '') {
            $this->response->status(400)->html('missing reference');
            return;
        }

        // 🔒 provider 一致性（防 provider confusion）：該付款列的 provider 必須等於回呼 URL 的 provider。
        // 例如不可用 /pay/callback/sandbox 對一筆 provider=payuni 的付款偽造入帳；亦自然擋掉
        // 「正式環境用 sandbox 端點打真實付款」的攻擊面（沙盒付款列才會 provider=sandbox）。
        $repo = new PaymentRepository();
        $paymentRow = $repo->findByNo($paymentNo);
        if ($paymentRow === null || (string) ($paymentRow['provider'] ?? '') !== $providerKey) {
            $this->auditLogService->log(0, 'payment_callback_provider_mismatch', 'payment', null,
                ['provider_url' => $providerKey, 'payment_no' => $paymentNo], $this->request->ip());
            $this->response->status(409)->html('provider mismatch');
            return;
        }

        // 防重放：provider_txn_id 已存在於「其他」付款 → 拒絕（唯一索引為最終防線，此處先擋）。
        $txnId = (string) ($verified['txn_id'] ?? '');
        if ($txnId !== '') {
            $existingTxn = $repo->findByProviderTxn($txnId);
            if ($existingTxn !== null && (string) ($existingTxn['payment_no'] ?? '') !== $paymentNo) {
                $this->response->status(409)->html('duplicate transaction');
                return;
            }
        }

        $rawCallback = json_encode([
            'provider' => $providerKey,
            'post'     => $post,
            'raw'      => $rawBody,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $this->paymentService->confirmPaid($paymentNo, $verified, $rawCallback);
        } catch (\RuntimeException $e) {
            // 金額不符等 → 回 422（明細已於 service 標 failed 並寫 audit）。
            // 對 webhook 一律回通用訊息，不外洩內部例外字串（避免 payment_no 枚舉 oracle）。
            $this->response->status(422)->html('rejected');
            return;
        }

        // 成功回 200（gateway 約定）。
        $this->response->status(200)->html('OK');
    }

    // ───────────────────────── 沙盒確認頁 ─────────────────────────

    /**
     * GET /pay/sandbox/{payment_no}
     * 沙盒確認頁：讓使用者按「模擬付款成功 / 失敗」。
     */
    public function sandboxForm(): void
    {
        $paymentNo = (string) $this->request->param('payment_no');
        $payment   = $this->paymentService->findByNo($paymentNo);

        if ($payment === null || ($payment['provider'] ?? '') !== 'sandbox') {
            $this->paymentNotFound();
            return;
        }

        $this->view->layout('public-quote');
        $this->render('public/payment/sandbox', [
            'title'     => '沙盒付款確認',
            'company'   => $this->companyInfo(),
            'payment'   => $payment,
            'sign'      => SandboxProvider::sign($paymentNo),
            'csrf'      => Csrf::token(),
        ]);
    }

    /**
     * POST /pay/sandbox/{payment_no}
     * 沙盒送出：★ 在 CSRF 群組外，本層自驗 CSRF；以沙盒簽章模擬 gateway 回呼走 confirmPaid。
     */
    public function sandboxSubmit(): void
    {
        $paymentNo = (string) $this->request->param('payment_no');

        // 自驗 CSRF（此路由不在 web.php CSRF 群組內）。
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            Session::flash('error', '驗證失敗，請重新操作。');
            $this->response->redirect('/pay/sandbox/' . rawurlencode($paymentNo));
        }

        $payment = $this->paymentService->findByNo($paymentNo);
        if ($payment === null || ($payment['provider'] ?? '') !== 'sandbox') {
            $this->paymentNotFound();
            return;
        }

        $result = (string) $this->request->input('result', 'fail'); // 'success' | 'fail'

        // 模擬 gateway 回呼：以沙盒簽章 + 金額（伺服器端 payment.amount）組回呼。
        $provider = $this->registry->get('sandbox');
        $callbackPost = [
            'payment_no' => $paymentNo,
            'sign'       => SandboxProvider::sign($paymentNo),
            'result'     => $result === 'success' ? 'success' : 'fail',
            'amount'     => (int) round((float) $payment['amount']),
        ];

        $verified = $provider->verifyCallback($callbackPost, $_SERVER);
        if (($verified['ok'] ?? false) !== true) {
            Session::flash('error', '沙盒簽章驗證失敗。');
            $this->response->redirect('/pay/sandbox/' . rawurlencode($paymentNo));
        }

        $rawCallback = json_encode(['provider' => 'sandbox', 'post' => $callbackPost], JSON_UNESCAPED_UNICODE);

        try {
            $this->paymentService->confirmPaid($paymentNo, $verified, $rawCallback);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->response->redirect('/pay/sandbox/' . rawurlencode($paymentNo));
        }

        // 導回付款結果頁。
        $this->response->redirect('/pay/return/sandbox?payment_no=' . rawurlencode($paymentNo));
    }

    // ───────────────────────── 內部輔助 ─────────────────────────

    /**
     * 依 checkout mode 導向 gateway：
     *   - redirect：302 導向 url。
     *   - form：輸出自動送出的 POST 表單。
     *
     * @param array<string, mixed> $checkout
     */
    private function dispatchCheckout(array $checkout): void
    {
        $mode = (string) ($checkout['mode'] ?? 'redirect');
        $url  = (string) ($checkout['url'] ?? '');

        if ($mode === 'form') {
            $fields = (array) ($checkout['fields'] ?? []);
            $this->renderAutoSubmitForm($url, $fields);
            return;
        }

        // 預設 redirect。
        if ($url === '') {
            Session::flash('error', '付款導向失敗。');
            $this->response->redirect('/');
        }
        $this->response->redirect($url);
    }

    /**
     * 輸出自動送出的 POST 表單到 gateway（mode=form，如 PayUni UPP）。
     *
     * @param array<string, string> $fields
     */
    private function renderAutoSubmitForm(string $url, array $fields): void
    {
        $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $inputs = '';
        foreach ($fields as $name => $value) {
            $inputs .= '<input type="hidden" name="' . $esc($name) . '" value="' . $esc($value) . '">' . "\n";
        }

        $html = '<!DOCTYPE html><html lang="zh-TW"><head><meta charset="UTF-8">'
            . '<meta name="robots" content="noindex,nofollow"><title>導向付款頁…</title></head>'
            . '<body style="font-family:system-ui,sans-serif;text-align:center;padding:40px;color:#1E293B;">'
            . '<p>正在前往付款頁面，請稍候…</p>'
            . '<form id="ys-pay-form" method="POST" action="' . $esc($url) . '">' . "\n"
            . $inputs
            . '<noscript><button type="submit" style="padding:10px 20px;background:#1E40AF;color:#fff;border:0;border-radius:8px;">繼續前往付款</button></noscript>'
            . '</form>'
            . '<script>document.getElementById("ys-pay-form").submit();</script>'
            . '</body></html>';

        $this->response->html($html);
    }

    /**
     * 依 provider 從回呼解析 payment_no（各家欄位不同）。
     */
    private function resolvePaymentNoFromCallback(string $providerKey, array $post, string $rawBody): string
    {
        return match ($providerKey) {
            // PayUni：MerTradeNo 在解密後的內容；但回呼外層通常不含明文。
            // 此處先嘗試外層；PayUni 實務上 payment_no 已於 verifyCallback 解出，
            // 但介面僅回 txn_id；為穩健改以「先查 txn 對應的 payment」回退（見下）。
            'payuni' => (string) ($post['MerTradeNo'] ?? ''),
            // Shopline：referenceOrderId 在 body.data 內。
            'shopline' => (string) ($this->jsonPath($rawBody, ['data', 'referenceOrderId']) ?? ''),
            // Sandbox：payment_no 直接在 post。
            default => (string) ($post['payment_no'] ?? ''),
        };
    }

    /**
     * 從 JSON 字串依路徑取值（簡易）。
     *
     * @param array<int, string> $path
     */
    private function jsonPath(string $json, array $path): mixed
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $cur = $data;
        foreach ($path as $key) {
            if (!is_array($cur) || !array_key_exists($key, $cur)) {
                return null;
            }
            $cur = $cur[$key];
        }
        return $cur;
    }

    /**
     * 讀取原始請求 body（Shopline webhook 簽章需原始字串）。
     */
    private function rawBody(): string
    {
        $raw = file_get_contents('php://input');
        return $raw !== false ? $raw : '';
    }

    /**
     * 組絕對 URL（含 scheme + host）。
     *
     * 🔴 **這個方法的輸出會變成送給金流商的 ReturnURL / NotifyURL。**
     * 原本它直接拿 Host header 與 X-Forwarded-Proto 拼字串，兩者都由請求方控制 ——
     * 也就是說任何人只要對本站發一次帶著偽造 Host 的建立付款請求，
     * 就能指定「這筆交易的付款完成通知要送到哪裡」。
     * 一律走 RequestOrigin（APP_URL / 白名單 / SERVER_NAME）。
     */
    private function absoluteUrl(string $path): string
    {
        return \YangSheep\CRM\Core\RequestOrigin::absoluteUrl($path);
    }

    /**
     * 我方公司資訊（公開頁版面用）。
     *
     * @return array<string, string>
     */
    private function companyInfo(): array
    {
        $settings = new \YangSheep\CRM\Setting\SettingService();
        $g = $settings->getGroup('company');
        return [
            'name'    => (string) ($g['company_name'] ?? ''),
            'phone'   => (string) ($g['company_phone'] ?? ''),
            'email'   => (string) ($g['company_email'] ?? ''),
            'logo'    => (string) ($settings->get('site', 'site_logo') ?? ''),
        ];
    }

    /**
     * 付款相關 404（公開頁版面）。
     */
    private function paymentNotFound(): void
    {
        $this->response->status(404);
        $this->view->layout('public-quote');
        $this->render('public/payment/result', [
            'title'        => '找不到付款',
            'company'      => $this->companyInfo(),
            'payment'      => null,
            'statusLabels' => PaymentController::statusLabels(),
        ]);
    }
}
