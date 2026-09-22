<?php

declare(strict_types=1);

namespace YangSheep\CRM\Customer;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Core\Validator;
use YangSheep\CRM\AuditLog\AuditLogService;
use YangSheep\CRM\Website\WebsiteService;
use YangSheep\CRM\Hosting\HostingService;
use YangSheep\CRM\Job\JobService;
use YangSheep\CRM\Quote\QuoteService;

/**
 * 客戶管理控制器。
 * 架構與 UserController 一致：CSRF + Validator + AuditLog + redirectWith/backWithError。
 * 所有寫入過 CSRF；輸入經 Validator 驗證；輸出於 view 以 e() 轉義。
 */
class CustomerController extends Controller
{
    private CustomerService $customerService;
    private AuditLogService $auditLogService;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->customerService = new CustomerService();
        $this->auditLogService = new AuditLogService();
    }

    /**
     * 客戶列表（分頁 + 篩選 type/status/keyword）。
     */
    public function index(): void
    {
        $page    = max(1, (int) $this->request->query('page', '1'));
        $perPage = 15;

        $filters = [
            'type'    => (string) $this->request->query('type', ''),
            'status'  => (string) $this->request->query('status', ''),
            'keyword' => (string) $this->request->query('keyword', ''),
        ];

        $result = $this->customerService->findAll($page, $perPage, $filters);

        $this->render('admin/customers/index', [
            'title'      => '客戶管理',
            'customers'  => $result['items'],
            'total'      => $result['total'],
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => (int) ceil($result['total'] / $perPage),
            'filters'    => $filters,
        ]);
    }

    /**
     * 新增客戶表單。
     */
    public function create(): void
    {
        $this->guardPermission('customers.create');
        $this->render('admin/customers/create', [
            'title'        => '新增客戶',
            'assignees'    => $this->assigneeOptions(),
            'statusLabels' => $this->statusLabels(),
        ]);
    }

    /**
     * 建立客戶（含聯絡人）。
     */
    public function store(): void
    {
        $this->guardPermission('customers.create');
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $validator = Validator::make($this->request->all(), [
            'type'         => 'required|in:individual,company',
            'display_name' => 'required|string|min:1|max:150',
            'tax_id'       => 'string|max:20',
            'address'      => 'string|max:255',
            'phone'        => 'string|max:50',
            'email'        => 'email|max:255',
            'source'       => 'string|max:100',
            'status'       => 'required|in:active,potential,inactive',
        ]);

        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        $data = $this->collectCustomerData();
        $data['created_by'] = $this->currentUserId() ?: null;

        $contacts = $this->collectContacts();

        try {
            $customerId = $this->customerService->create($data, $contacts);

            $this->auditLogService->log(
                $this->currentUserId(),
                'customer_created',
                'customer',
                $customerId,
                ['display_name' => $data['display_name'], 'type' => $data['type']],
                $this->request->ip()
            );

            $this->redirectWith('/admin/customers/' . $customerId, 'success', '客戶已建立。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 客戶內頁（基本資料 + 聯絡人 + 模組佔位 tab）。
     */
    public function show(): void
    {
        $id = (int) $this->request->param('id');
        $customer = $this->customerService->findWithContacts($id);

        if ($customer === null) {
            $this->redirectWith('/admin/customers', 'error', '客戶不存在。');
        }

        // 內頁「網站」「主機」「工作」「報價單」tab：載入該客戶的資產 / 工作 / 報價清單（純加法，不影響客戶 CRUD）。
        $websiteService = new WebsiteService();
        $hostingService = new HostingService();
        $jobService     = new JobService();
        $quoteService   = new QuoteService();

        // Portal 登入帳號（P4-2）：列出該客戶的 portal 帳號，並判斷管理員是否可管理。
        $canManagePortal = $this->currentUserHasPermission('customer_user.manage');
        $portalUsers = $canManagePortal
            ? (new \YangSheep\CRM\Portal\CustomerUserRepository())->findByCustomer($id)
            : [];

        $invoiceProfileRepo = new \YangSheep\CRM\EInvoice\InvoiceProfileRepository();

        $this->render('admin/customers/show', [
            'title'           => $customer['display_name'],
            'customer'        => $customer,
            'statusLabels'    => $this->statusLabels(),
            'websites'        => $websiteService->findByCustomer($id),
            'hostings'        => $hostingService->findByCustomer($id),
            'customerJobs'    => $jobService->findByCustomer($id),
            'customerQuotes'  => $quoteService->findByCustomer($id),
            'quoteStatusLabels' => \YangSheep\CRM\Quote\QuoteController::statusLabels(),
            'jobPriorityLabels' => ['low' => '低', 'medium' => '中', 'high' => '高', 'critical' => '緊急'],
            'assetStatusLabels' => \YangSheep\CRM\Asset\AssetHelper::ASSET_STATUSES,
            'caseTypeLabels'  => \YangSheep\CRM\Asset\AssetHelper::WEBSITE_CASE_TYPES,
            'hostingTypeLabels' => \YangSheep\CRM\Asset\AssetHelper::HOSTING_TYPES,
            'portalUsers'     => $portalUsers,
            'canManagePortal' => $canManagePortal,
            'canManageInvoiceProfiles' => $this->currentUserHasPermission('customers.edit'),
            'invoiceProfiles' => $invoiceProfileRepo->findAllByCustomer($id),
        ]);
    }

    /**
     * 編輯客戶表單。
     */
    public function edit(): void
    {
        $this->guardPermission('customers.edit');
        $id = (int) $this->request->param('id');
        $customer = $this->customerService->findById($id);

        if ($customer === null) {
            $this->redirectWith('/admin/customers', 'error', '客戶不存在。');
        }

        $this->render('admin/customers/edit', [
            'title'        => '編輯客戶',
            'customer'     => $customer,
            'assignees'    => $this->assigneeOptions(),
            'statusLabels' => $this->statusLabels(),
        ]);
    }

    /**
     * 更新客戶基本資料。
     */
    public function update(): void
    {
        $this->guardPermission('customers.edit');
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $id = (int) $this->request->param('id');

        $validator = Validator::make($this->request->all(), [
            'type'         => 'required|in:individual,company',
            'display_name' => 'required|string|min:1|max:150',
            'tax_id'       => 'string|max:20',
            'address'      => 'string|max:255',
            'phone'        => 'string|max:50',
            'email'        => 'email|max:255',
            'source'       => 'string|max:100',
            'status'       => 'required|in:active,potential,inactive',
        ]);

        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        $data = $this->collectCustomerData();

        try {
            $this->customerService->update($id, $data);

            $this->auditLogService->log(
                $this->currentUserId(),
                'customer_updated',
                'customer',
                $id,
                ['fields' => array_keys($data)],
                $this->request->ip()
            );

            $this->redirectWith('/admin/customers/' . $id, 'success', '客戶已更新。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 刪除客戶。
     */
    public function destroy(): void
    {
        $this->guardPermission('customers.delete');
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $id = (int) $this->request->param('id');

        try {
            $this->customerService->delete($id);

            $this->auditLogService->log(
                $this->currentUserId(),
                'customer_deleted',
                'customer',
                $id,
                null,
                $this->request->ip()
            );

            $this->redirectWith('/admin/customers', 'success', '客戶已刪除。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 為客戶新增聯絡人（從內頁送出）。
     */
    public function storeContact(): void
    {
        $this->guardPermission('customers.edit');
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $customerId = (int) $this->request->param('id');

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
            $contactId = $this->customerService->addContact($customerId, $data);

            $this->auditLogService->log(
                $this->currentUserId(),
                'customer_contact_created',
                'customer',
                $customerId,
                ['contact_id' => $contactId, 'name' => $data['name']],
                $this->request->ip()
            );

            $this->redirectWith('/admin/customers/' . $customerId, 'success', '聯絡人已新增。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 刪除聯絡人。
     */
    public function destroyContact(): void
    {
        $this->guardPermission('customers.edit');
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $customerId = (int) $this->request->param('id');
        $contactId  = (int) $this->request->param('contact_id');

        try {
            $this->customerService->deleteContact($customerId, $contactId);

            $this->auditLogService->log(
                $this->currentUserId(),
                'customer_contact_deleted',
                'customer',
                $customerId,
                ['contact_id' => $contactId],
                $this->request->ip()
            );

            $this->redirectWith('/admin/customers/' . $customerId, 'success', '聯絡人已刪除。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 從請求蒐集客戶欄位（不含 created_by；由呼叫端補上）。
     *
     * @return array<string, mixed>
     */
    private function collectCustomerData(): array
    {
        $data = $this->request->only([
            'type', 'display_name', 'tax_id', 'address',
            'phone', 'email', 'source', 'status', 'notes',
        ]);

        // 個人客戶清空統編（統編僅公司適用）
        if (($data['type'] ?? '') === 'individual') {
            $data['tax_id'] = '';
        }

        // 負責人：空字串視為未指定（NULL）；否則轉整數
        $assignedTo = $this->request->input('assigned_to', '');
        $data['assigned_to'] = ($assignedTo === '' || $assignedTo === null)
            ? null
            : (int) $assignedTo;

        return $data;
    }

    /**
     * 從請求蒐集聯絡人陣列（表單以 contacts[idx][field] 命名）。
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectContacts(): array
    {
        $raw = $this->request->input('contacts', []);
        if (!is_array($raw)) {
            return [];
        }

        $contacts = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $contacts[] = [
                'name'        => (string) ($row['name'] ?? ''),
                'role'        => (string) ($row['role'] ?? ''),
                'is_primary'  => !empty($row['is_primary']) ? 1 : 0,
                'phone'       => (string) ($row['phone'] ?? ''),
                'mobile'      => (string) ($row['mobile'] ?? ''),
                'email'       => (string) ($row['email'] ?? ''),
                'line_id'     => (string) ($row['line_id'] ?? ''),
                'fb_url'      => (string) ($row['fb_url'] ?? ''),
                'threads_url' => (string) ($row['threads_url'] ?? ''),
                'note'        => (string) ($row['note'] ?? ''),
            ];
        }

        return $contacts;
    }

    /**
     * 負責人下拉選項（啟用中的我方使用者）。
     *
     * @return array<int, array{id: int, display_name: string}>
     */
    private function assigneeOptions(): array
    {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT id, display_name FROM {prefix}users
             WHERE status = 'active'
             ORDER BY display_name ASC"
        );
    }

    /**
     * 取得當前登入使用者 ID（無則 0）。
     * 先取變數再存取索引，避免 user 為 null 時的 array offset warning。
     */
    private function currentUserId(): int
    {
        $user = Session::get('user');
        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }

    /**
     * 當前管理員是否擁有指定權限（供內頁條件顯示 portal 帳號管理區塊）。
     */
    private function currentUserHasPermission(string $code): bool
    {
        $uid = $this->currentUserId();
        if ($uid <= 0) {
            return false;
        }
        return (new \YangSheep\CRM\Role\RoleService())->hasPermission($uid, $code);
    }

    /**
     * 狀態代碼 → 中文標籤對照。
     *
     * @return array<string, string>
     */
    private function statusLabels(): array
    {
        return [
            'active'    => '合作中',
            'potential' => '潛在客戶',
            'inactive'  => '已停止',
        ];
    }
}
