<?php

declare(strict_types=1);

namespace YangSheep\CRM\Portal;

use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Validator;
use YangSheep\CRM\AuditLog\AuditLogService;

/**
 * 客戶 Portal 卡片管理（新增 / 刪除 / 設預設）。
 *
 * 🔴 PCI / Zero Trust：
 *   - 絕不接收、不儲存完整卡號或 CVV。表單只收顯示用末四碼（last4）+ 卡別 + 到期，
 *     真實 tokenization 留接點（CustomerPaymentMethodService 以佔位 token 的密文存入，
 *     待金流 vault 上線後改存 provider iframe 回傳的真實 token）。
 *   - 所有卡片操作綁 session.customer_id；by-id 操作於 Repository 層強制 customer_id scope，
 *     跨客戶一律失敗。
 */
class PortalPaymentMethodController extends PortalController
{
    private CustomerPaymentMethodService $service;
    private AuditLogService $auditLog;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->service  = new CustomerPaymentMethodService();
        $this->auditLog = new AuditLogService();
    }

    /**
     * 卡片列表 + 新增表單。
     */
    public function index(): void
    {
        $cards = $this->service->listForCustomer($this->customerId());

        $this->renderPortal('portal/payment-methods/index', [
            'title' => '付款卡片',
            'cards' => $cards,
            'brands' => ['VISA', 'Mastercard', 'JCB', 'AMEX', 'UnionPay'],
        ]);
    }

    /**
     * 新增卡片（僅末四碼等顯示資訊；真實 token 留接點）。
     */
    public function store(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('驗證失敗，請重新操作。');
        }

        $validator = Validator::make($this->request->all(), [
            'brand'     => 'required|string|max:32',
            'last4'     => 'required|string|min:4|max:4',
            'exp_month' => 'string|max:2',
            'exp_year'  => 'string|max:4',
            'label'     => 'string|max:100',
        ]);
        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        try {
            $cardId = $this->service->addCard(
                $this->customerId(),      // 🔴 session.customer_id
                $this->customerUserId(),
                [
                    'provider'   => 'sandbox', // 真實環境由 provider iframe 流程決定
                    'brand'      => (string) $this->request->input('brand', 'Unknown'),
                    'last4'      => (string) $this->request->input('last4', ''),
                    'exp_month'  => $this->request->input('exp_month'),
                    'exp_year'   => $this->request->input('exp_year'),
                    'label'      => (string) $this->request->input('label', ''),
                    'is_default' => $this->request->input('is_default') ? true : false,
                    // provider_token 留接點：真實 tokenization 後由前端傳入。
                ]
            );

            $this->auditLog->log(
                0,
                'portal_payment_method_added',
                'customer_user',
                $this->customerUserId(),
                ['customer_id' => $this->customerId(), 'card_id' => $cardId],
                $this->request->ip()
            );

            $this->redirectWith('/portal/payment-methods', 'success', '已新增付款卡片。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 設為預設卡。
     */
    public function setDefault(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('驗證失敗，請重新操作。');
        }

        $cardId = (int) $this->request->param('id');

        try {
            $this->service->setDefault($cardId, $this->customerId());

            $this->auditLog->log(
                0,
                'portal_payment_method_set_default',
                'customer_user',
                $this->customerUserId(),
                ['customer_id' => $this->customerId(), 'card_id' => $cardId],
                $this->request->ip()
            );

            $this->redirectWith('/portal/payment-methods', 'success', '已設為預設卡片。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 刪除卡片（軟刪除）。
     */
    public function destroy(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('驗證失敗，請重新操作。');
        }

        $cardId = (int) $this->request->param('id');

        try {
            $this->service->deleteCard($cardId, $this->customerId());

            $this->auditLog->log(
                0,
                'portal_payment_method_deleted',
                'customer_user',
                $this->customerUserId(),
                ['customer_id' => $this->customerId(), 'card_id' => $cardId],
                $this->request->ip()
            );

            $this->redirectWith('/portal/payment-methods', 'success', '已刪除付款卡片。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }
}
