<?php

declare(strict_types=1);

namespace YangSheep\CRM\Portal;

use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Quote\QuoteService;
use YangSheep\CRM\Quote\QuoteController;
use YangSheep\CRM\Payment\PaymentRepository;
use YangSheep\CRM\Setting\SettingService;

/**
 * 客戶 Portal 報價單（自己的報價列表 + 檢視；含付款入口）。
 *
 * 🔴 Zero Trust scope：
 *   - 列表一律 QuoteService::findByCustomer(session.customer_id)。
 *   - 檢視某報價：先以 id 取得，再驗證該報價 customer_id === session.customer_id，
 *     不符即 404（防客戶 A 用 id 猜看客戶 B 的報價）。customer_only 報價在此可正常檢視
 *     （已通過客戶登入 + 歸屬驗證，等同 §5.5 customer_only 的授權條件）。
 */
class PortalQuoteController extends PortalController
{
    private QuoteService $quoteService;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->quoteService = new QuoteService();
    }

    /**
     * 自己的報價列表。
     */
    public function index(): void
    {
        $quotes = $this->quoteService->findByCustomer($this->customerId());

        $this->renderPortal('portal/quotes/index', [
            'title'        => '我的報價單',
            'quotes'       => $quotes,
            'statusLabels' => QuoteController::statusLabels(),
        ]);
    }

    /**
     * 檢視單一報價（含明細 + 付款狀態 + 公開連結）。
     */
    public function show(): void
    {
        $id    = (int) $this->request->param('id');
        $quote = $this->requireOwnQuote($id);

        // 最新一筆付款（顯示付款狀態 / 付款入口）。
        $latestPayment = (new PaymentRepository())->findLatestByQuote($id);

        $this->renderPortal('portal/quotes/show', [
            'title'         => $quote['quote_number'] . '｜' . $quote['title'],
            'quote'         => $quote,
            'latestPayment' => $latestPayment,
            'company'       => $this->companyInfo(),
            'statusLabels'  => QuoteController::statusLabels(),
        ]);
    }

    /**
     * 取得「屬於當前客戶」的報價，否則導回列表（視同 404，不洩漏存在與否）。
     *
     * @return array<string, mixed> 含 items
     */
    private function requireOwnQuote(int $id): array
    {
        $quote = $this->quoteService->getQuoteDetail($id);

        // 🔴 歸屬驗證：報價必須存在且 customer_id 等於 session.customer_id。
        if ($quote === null || (int) ($quote['customer_id'] ?? 0) !== $this->customerId()) {
            $this->response->status(404);
            $this->renderPortal('portal/errors/not-found', [
                'title' => '找不到報價單',
            ]);
            // renderPortal 已輸出；中止後續流程。
            exit;
        }

        return $quote;
    }

    /**
     * 我方公司資訊（供報價檢視頁顯示）。
     *
     * @return array<string, string>
     */
    private function companyInfo(): array
    {
        $settings = new SettingService();
        $g = $settings->getGroup('company');
        return [
            'name'    => (string) ($g['company_name'] ?? ''),
            'tax_id'  => (string) ($g['company_tax_id'] ?? ''),
            'address' => (string) ($g['company_address'] ?? ''),
            'phone'   => (string) ($g['company_phone'] ?? ''),
            'email'   => (string) ($g['company_email'] ?? ''),
        ];
    }
}
