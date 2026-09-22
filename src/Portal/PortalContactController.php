<?php

declare(strict_types=1);

namespace YangSheep\CRM\Portal;

use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Core\Validator;
use YangSheep\CRM\Customer\CustomerService;
use YangSheep\CRM\Customer\CustomerContactRepository;
use YangSheep\CRM\AuditLog\AuditLogService;

/**
 * 客戶 Portal 聯絡人（客戶管理「自己的」聯絡人，CRUD）。
 *
 * 🔴 Zero Trust scope：所有操作的 customer_id 一律取自 session（CustomerGuard），非前端。
 *   - 列表：CustomerContactRepository::findByCustomer(session.customer_id)。
 *   - 新增 / 刪除：CustomerService::addContact / deleteContact，後者已驗證聯絡人屬於該 customer。
 *     由於本控制器一律傳 session.customer_id，客戶無法新增/刪除他客戶的聯絡人。
 */
class PortalContactController extends PortalController
{
    private CustomerService $customerService;
    private CustomerContactRepository $contacts;
    private AuditLogService $auditLog;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->customerService = new CustomerService();
        $this->contacts        = new CustomerContactRepository();
        $this->auditLog        = new AuditLogService();
    }

    /**
     * 自己的聯絡人列表。
     */
    public function index(): void
    {
        $list = $this->contacts->findByCustomer($this->customerId());

        $this->renderPortal('portal/contacts/index', [
            'title'    => '聯絡人',
            'contacts' => $list,
        ]);
    }

    /**
     * 新增聯絡人。
     */
    public function store(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('驗證失敗，請重新操作。');
        }

        $validator = Validator::make($this->request->all(), [
            'name'        => 'required|string|min:1|max:100',
            'role'        => 'string|max:100',
            'phone'       => 'string|max:50',
            'mobile'      => 'string|max:50',
            'email'       => 'email|max:255',
            'line_id'     => 'string|max:100',
            'fb_url'      => 'string|max:255',
            'threads_url' => 'string|max:255',
        ]);
        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        $data = $this->request->only([
            'name', 'role', 'phone', 'mobile', 'email',
            'line_id', 'fb_url', 'threads_url', 'note',
        ]);
        $data['is_primary'] = $this->request->input('is_primary') ? 1 : 0;

        try {
            // 🔴 customer_id 取自 session，非前端。
            $contactId = $this->customerService->addContact($this->customerId(), $data);

            $this->auditLog->log(
                0,
                'portal_contact_created',
                'customer_user',
                $this->customerUserId(),
                ['customer_id' => $this->customerId(), 'contact_id' => $contactId],
                $this->request->ip()
            );

            $this->redirectWith('/portal/contacts', 'success', '聯絡人已新增。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 刪除聯絡人（限本客戶）。
     */
    public function destroy(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('驗證失敗，請重新操作。');
        }

        $contactId = (int) $this->request->param('contact_id');

        try {
            // deleteContact 第一參數為 customer_id（session），第二為 contact_id；
            // 服務層會驗證該聯絡人確實屬於此 customer，否則拋例外。
            $this->customerService->deleteContact($this->customerId(), $contactId);

            $this->auditLog->log(
                0,
                'portal_contact_deleted',
                'customer_user',
                $this->customerUserId(),
                ['customer_id' => $this->customerId(), 'contact_id' => $contactId],
                $this->request->ip()
            );

            $this->redirectWith('/portal/contacts', 'success', '聯絡人已刪除。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }
}
