<?php

declare(strict_types=1);

namespace YangSheep\CRM\Portal;

use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Payment\PaymentRepository;
use YangSheep\CRM\Payment\PaymentService;
use YangSheep\CRM\Payment\PaymentProviderRegistry;
use YangSheep\CRM\Payment\PaymentController;
use YangSheep\CRM\Quote\QuoteService;
use YangSheep\CRM\AuditLog\AuditLogService;

/**
 * 客戶 Portal 付款（自己的付款記錄 + 由自己的報價發動付款）。
 *
 * 🔴 Zero Trust scope：
 *   - 付款列表一律 PaymentRepository::findByCustomer(session.customer_id)。
 *   - 發動付款：先驗證報價屬於本客戶（customer_id === session），再走 PaymentService。
 *   - 金額一律伺服器端從 quote.total 重算（PaymentService），絕不接受前端金額。
 *   - 發動付款走 POST + CSRF。付款後導向 gateway，入帳以 webhook/callback 為準
 *     （與公開付款流程共用同一組 return/callback 端點與冪等鍵，下游完全一致）。
 */
class PortalPaymentController extends PortalController
{
    private PaymentService $paymentService;
    private PaymentProviderRegistry $registry;
    private QuoteService $quoteService;
    private AuditLogService $auditLog;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->paymentService = new PaymentService();
        $this->registry       = new PaymentProviderRegistry();
        $this->quoteService   = new QuoteService();
        $this->auditLog       = new AuditLogService();
    }

    /**
     * 自己的付款記錄列表。
     */
    public function index(): void
    {
        $payments = (new PaymentRepository())->findByCustomer($this->customerId());

        $this->renderPortal('portal/payments/index', [
            'title'        => '我的付款記錄',
            'payments'     => $payments,
            'statusLabels' => PaymentController::statusLabels(),
        ]);
    }

    /**
     * POST /portal/quotes/{id}/pay — 由自己的報價發動付款。
     */
    public function pay(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            Session::flash('error', '驗證失敗，請重新操作。');
            $this->response->redirect('/portal/quotes');
        }

        $quoteId = (int) $this->request->param('id');

        // 🔴 歸屬驗證：報價必須屬於當前客戶。
        $quote = $this->quoteService->getQuoteDetail($quoteId);
        if ($quote === null || (int) ($quote['customer_id'] ?? 0) !== $this->customerId()) {
            Session::flash('error', '找不到報價單。');
            $this->response->redirect('/portal/quotes');
        }

        // 前置條件：已啟用付款且尚未付清。
        if ((int) ($quote['payment_enabled'] ?? 0) !== 1) {
            Session::flash('error', '此報價單未啟用線上付款。');
            $this->response->redirect('/portal/quotes/' . $quoteId);
        }
        if (($quote['payment_status'] ?? '') === 'paid' || ($quote['status'] ?? '') === 'paid') {
            Session::flash('info', '此報價單已完成付款。');
            $this->response->redirect('/portal/quotes/' . $quoteId);
        }

        // 與公開付款共用冪等鍵（同一報價的付款不重複建單）。
        $idempotencyKey = 'quote_' . $quoteId . '_pay';
        $providerKey = $this->registry->resolve()->key();
        $returnUrl   = $this->absoluteUrl('/pay/return/' . $providerKey);
        $callbackUrl = $this->absoluteUrl('/pay/callback/' . $providerKey);

        try {
            $result   = $this->paymentService->initiateForQuote($quote, $idempotencyKey, $returnUrl, $callbackUrl);
            $checkout = $result['checkout'];
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->response->redirect('/portal/quotes/' . $quoteId);
        }

        $this->auditLog->log(
            0,
            'portal_payment_initiated',
            'customer_user',
            $this->customerUserId(),
            ['quote_id' => $quoteId, 'customer_id' => $this->customerId(), 'payment_no' => $result['payment']['payment_no'] ?? ''],
            $this->request->ip()
        );

        $this->dispatchCheckout($checkout);
    }

    // ───────────────────────── 內部輔助（與公開付款一致的 gateway 導向） ─────────────────────────

    /**
     * 依 checkout mode 導向 gateway（redirect / form）。
     *
     * @param array<string, mixed> $checkout
     */
    private function dispatchCheckout(array $checkout): void
    {
        $mode = (string) ($checkout['mode'] ?? 'redirect');
        $url  = (string) ($checkout['url'] ?? '');

        if ($mode === 'form') {
            $this->renderAutoSubmitForm($url, (array) ($checkout['fields'] ?? []));
            return;
        }
        if ($url === '') {
            Session::flash('error', '付款導向失敗。');
            $this->response->redirect('/portal/quotes');
        }
        $this->response->redirect($url);
    }

    /**
     * 自動送出 POST 表單到 gateway（mode=form，如 PayUni UPP）。
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
     * 組絕對 URL（供 gateway return/callback）。
     *
     * 🔴 不可用請求帶進來的 Host / X-Forwarded-Proto —— 這個值會成為金流商的
     * NotifyURL。理由同 PublicPaymentController::absoluteUrl()。
     */
    private function absoluteUrl(string $path): string
    {
        return \YangSheep\CRM\Core\RequestOrigin::absoluteUrl($path);
    }
}
