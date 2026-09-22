<?php

declare(strict_types=1);

namespace YangSheep\CRM\Website;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Core\Validator;
use YangSheep\CRM\Asset\AssetHelper;
use YangSheep\CRM\AuditLog\AuditLogService;

/**
 * 客戶網站資產管理控制器。
 * 架構與 CustomerController 一致：CSRF + Validator + AuditLog + redirectWith/backWithError。
 * 所有寫入過 CSRF；輸入經 Validator 驗證；輸出於 view 以 e() 轉義；查詢全程 prepared。
 *
 * 對應架構設計 §7.7（網站 Excel-like 總表，篩選 到期月份 / 狀態 / 客戶）。
 */
class WebsiteController extends Controller
{
    private WebsiteService $websiteService;
    private AuditLogService $auditLogService;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->websiteService = new WebsiteService();
        $this->auditLogService = new AuditLogService();
    }

    /**
     * 網站資產列表（分頁 + 篩選 customer_id / status / due_month）。
     */
    public function index(): void
    {
        $page    = max(1, (int) $this->request->query('page', '1'));
        $perPage = 20;

        $filters = [
            'customer_id' => (string) $this->request->query('customer_id', ''),
            'status'      => (string) $this->request->query('status', ''),
            'due_month'   => (string) $this->request->query('due_month', ''),
        ];

        $result = $this->websiteService->findAll($page, $perPage, $filters);

        $this->render('admin/websites/index', [
            'title'          => '客戶網站',
            'websites'       => $result['items'],
            'total'          => $result['total'],
            'page'           => $page,
            'perPage'        => $perPage,
            'totalPages'     => (int) ceil($result['total'] / $perPage),
            'filters'        => $filters,
            'customers'      => AssetHelper::customerOptions(),
            'dueMonths'      => AssetHelper::dueMonthOptions('customer_websites', 'contract_end'),
            'caseTypeLabels' => AssetHelper::WEBSITE_CASE_TYPES,
            'statusLabels'   => AssetHelper::ASSET_STATUSES,
        ]);
    }

    /**
     * 新增網站資產表單。
     */
    public function create(): void
    {
        $this->guardPermission('websites.create');
        $this->render('admin/websites/create', [
            'title'          => '新增客戶網站',
            'customers'      => AssetHelper::customerOptions(),
            'caseTypeLabels' => AssetHelper::WEBSITE_CASE_TYPES,
            'statusLabels'   => AssetHelper::ASSET_STATUSES,
            'presetCustomer' => (int) $this->request->query('customer_id', '0'),
        ]);
    }

    /**
     * 建立網站資產。
     */
    public function store(): void
    {
        $this->guardPermission('websites.create');
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        if (!$this->validateInput()) {
            return;
        }

        $data = $this->collectData();
        $data['created_by'] = $this->currentUserId() ?: null;

        try {
            $websiteId = $this->websiteService->create($data);

            $this->auditLogService->log(
                $this->currentUserId(),
                'website_created',
                'customer_website',
                $websiteId,
                ['customer_id' => $data['customer_id'], 'url' => $data['url'], 'case_type' => $data['case_type']],
                $this->request->ip()
            );

            $this->redirectWith('/admin/websites', 'success', '網站資產已建立。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 編輯網站資產表單。
     */
    public function edit(): void
    {
        $this->guardPermission('websites.edit');
        $id = (int) $this->request->param('id');
        $website = $this->websiteService->findById($id);

        if ($website === null) {
            $this->redirectWith('/admin/websites', 'error', '網站資產不存在。');
        }

        $this->render('admin/websites/edit', [
            'title'          => '編輯客戶網站',
            'website'        => $website,
            'customers'      => AssetHelper::customerOptions(),
            'caseTypeLabels' => AssetHelper::WEBSITE_CASE_TYPES,
            'statusLabels'   => AssetHelper::ASSET_STATUSES,
        ]);
    }

    /**
     * 更新網站資產。
     */
    public function update(): void
    {
        $this->guardPermission('websites.edit');
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $id = (int) $this->request->param('id');

        if (!$this->validateInput()) {
            return;
        }

        $data = $this->collectData();

        try {
            $this->websiteService->update($id, $data);

            $this->auditLogService->log(
                $this->currentUserId(),
                'website_updated',
                'customer_website',
                $id,
                ['fields' => array_keys($data)],
                $this->request->ip()
            );

            $this->redirectWith('/admin/websites', 'success', '網站資產已更新。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 刪除網站資產。
     */
    public function destroy(): void
    {
        $this->guardPermission('websites.delete');
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $id = (int) $this->request->param('id');

        try {
            $this->websiteService->delete($id);

            $this->auditLogService->log(
                $this->currentUserId(),
                'website_deleted',
                'customer_website',
                $id,
                null,
                $this->request->ip()
            );

            $this->redirectWith('/admin/websites', 'success', '網站資產已刪除。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 驗證輸入；失敗時設 flash 並導回（回傳 false）。
     */
    private function validateInput(): bool
    {
        $validator = Validator::make($this->request->all(), [
            'customer_id'       => 'required|integer',
            'url'               => 'string|max:255',
            'case_type'         => 'required|in:build,hosting,maintenance_hosting,maintenance',
            'contract_start'    => 'string|max:10',
            'contract_end'      => 'string|max:10',
            'maintenance_start' => 'string|max:10',
            'maintenance_end'   => 'string|max:10',
            'status'            => 'required|in:active,expired,terminated',
        ]);

        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
            return false;
        }

        return true;
    }

    /**
     * 從請求蒐集網站資產欄位（不含 created_by；由呼叫端補上）。
     * 日期空字串正規化為 null；URL 修剪空白。
     *
     * @return array<string, mixed>
     */
    private function collectData(): array
    {
        return [
            'customer_id'       => (int) $this->request->input('customer_id', 0),
            'url'               => trim((string) $this->request->input('url', '')),
            'case_type'         => (string) $this->request->input('case_type', 'build'),
            'contract_start'    => $this->nullableDate($this->request->input('contract_start')),
            'contract_end'      => $this->nullableDate($this->request->input('contract_end')),
            'maintenance_start' => $this->nullableDate($this->request->input('maintenance_start')),
            'maintenance_end'   => $this->nullableDate($this->request->input('maintenance_end')),
            'status'            => (string) $this->request->input('status', 'active'),
            'notes'             => trim((string) $this->request->input('notes', '')),
        ];
    }

    /**
     * 日期欄位正規化：空字串 / 非 Y-m-d 格式 → null；合法則回傳 Y-m-d。
     */
    private function nullableDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($dt === false || $dt->format('Y-m-d') !== $value) {
            return null; // 格式不符視為未填，避免寫入無效日期
        }

        return $value;
    }

    /**
     * 取得當前登入使用者 ID（無則 0）。
     */
    private function currentUserId(): int
    {
        $user = Session::get('user');
        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }
}
