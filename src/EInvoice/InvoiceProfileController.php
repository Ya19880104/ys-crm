<?php

declare(strict_types=1);

namespace YangSheep\CRM\EInvoice;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;

class InvoiceProfileController extends Controller
{
    private InvoiceProfileRepository $profiles;

    public function __construct(\YangSheep\CRM\Core\Request $request, \YangSheep\CRM\Core\Response $response)
    {
        parent::__construct($request, $response);
        $this->profiles = new InvoiceProfileRepository();
    }

    public function create(): void
    {
        $customerId = (int) $this->request->param('customer_id');
        $this->render('admin/customers/invoice-profile-form', [
            'title'      => '新增發票資料',
            'customerId' => $customerId,
            'profile'    => null,
            'breadcrumb' => [
                ['label' => '客戶', 'url' => '/admin/customers'],
                ['label' => '#' . $customerId, 'url' => '/admin/customers/' . $customerId],
                ['label' => '新增發票資料'],
            ],
        ]);
    }

    public function store(): void
    {
        $customerId = (int) $this->request->param('customer_id');
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF Token 驗證失敗。');
        }

        $this->profiles->create($customerId, $this->request->all());
        $this->redirectWith("/admin/customers/{$customerId}", 'success', '發票資料已新增。');
    }

    public function edit(): void
    {
        $customerId = (int) $this->request->param('customer_id');
        $id         = (int) $this->request->param('id');
        $profile    = $this->profiles->findById($id);

        if ($profile === null || (int) ($profile['customer_id'] ?? 0) !== $customerId) {
            $this->redirectWith("/admin/customers/{$customerId}", 'error', '找不到發票資料。');
        }

        $this->render('admin/customers/invoice-profile-form', [
            'title'      => '編輯發票資料',
            'customerId' => $customerId,
            'profile'    => $profile,
            'breadcrumb' => [
                ['label' => '客戶', 'url' => '/admin/customers'],
                ['label' => '#' . $customerId, 'url' => '/admin/customers/' . $customerId],
                ['label' => '編輯發票資料'],
            ],
        ]);
    }

    public function update(): void
    {
        $customerId = (int) $this->request->param('customer_id');
        $id         = (int) $this->request->param('id');
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF Token 驗證失敗。');
        }

        if (!$this->profiles->update($id, $customerId, $this->request->all())) {
            $this->redirectWith("/admin/customers/{$customerId}", 'error', '找不到可更新的發票資料。');
        }
        $this->redirectWith("/admin/customers/{$customerId}", 'success', '發票資料已更新。');
    }

    public function setDefault(): void
    {
        $customerId = (int) $this->request->param('customer_id');
        $id         = (int) $this->request->param('id');
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF Token 驗證失敗。');
        }

        if (!$this->profiles->setDefault($id, $customerId)) {
            $this->redirectWith("/admin/customers/{$customerId}", 'error', '找不到可設為預設的發票資料。');
        }
        $this->redirectWith("/admin/customers/{$customerId}", 'success', '已設為預設發票資料。');
    }

    public function delete(): void
    {
        $customerId = (int) $this->request->param('customer_id');
        $id         = (int) $this->request->param('id');
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF Token 驗證失敗。');
        }

        if (!$this->profiles->delete($id, $customerId)) {
            $this->redirectWith("/admin/customers/{$customerId}", 'error', '找不到可刪除的發票資料。');
        }
        $this->redirectWith("/admin/customers/{$customerId}", 'success', '發票資料已刪除。');
    }
}
