<?php

declare(strict_types=1);

namespace YangSheep\CRM\Hosting;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Core\Validator;
use YangSheep\CRM\Asset\AssetHelper;
use YangSheep\CRM\AuditLog\AuditLogService;

/**
 * 客戶主機資產管理控制器。
 * 架構與 CustomerController / WebsiteController 一致：
 * CSRF + Validator + AuditLog + redirectWith/backWithError。
 * 所有寫入過 CSRF；輸入經 Validator 驗證；輸出於 view 以 e() 轉義；查詢全程 prepared。
 *
 * 對應架構設計 §7.7（主機 Excel-like 總表，篩選 到期月份 / 狀態 / 客戶）。
 */
class HostingController extends Controller
{
    private HostingService $hostingService;
    private AuditLogService $auditLogService;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->hostingService = new HostingService();
        $this->auditLogService = new AuditLogService();
    }

    /**
     * 主機資產列表（分頁 + 篩選 customer_id / status / due_month）。
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

        $result = $this->hostingService->findAll($page, $perPage, $filters);

        $this->render('admin/hosting/index', [
            'title'        => '客戶主機',
            'hostings'     => $result['items'],
            'total'        => $result['total'],
            'page'         => $page,
            'perPage'      => $perPage,
            'totalPages'   => (int) ceil($result['total'] / $perPage),
            'filters'      => $filters,
            'customers'    => AssetHelper::customerOptions(),
            'dueMonths'    => AssetHelper::dueMonthOptions('customer_hosting', 'end_date'),
            'typeLabels'   => AssetHelper::HOSTING_TYPES,
            'statusLabels' => AssetHelper::ASSET_STATUSES,
        ]);
    }

    /**
     * 新增主機資產表單。
     */
    public function create(): void
    {
        $this->guardPermission('hosting.create');
        $this->render('admin/hosting/create', [
            'title'          => '新增客戶主機',
            'customers'      => AssetHelper::customerOptions(),
            'websitesByCust' => $this->websitesMapForSelect(),
            'typeLabels'     => AssetHelper::HOSTING_TYPES,
            'statusLabels'   => AssetHelper::ASSET_STATUSES,
            'presetCustomer' => (int) $this->request->query('customer_id', '0'),
        ]);
    }

    /**
     * 建立主機資產。
     */
    public function store(): void
    {
        $this->guardPermission('hosting.create');
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        if (!$this->validateInput()) {
            return;
        }

        $data = $this->collectData();
        $data['created_by'] = $this->currentUserId() ?: null;

        try {
            $hostingId = $this->hostingService->create($data);

            $this->auditLogService->log(
                $this->currentUserId(),
                'hosting_created',
                'customer_hosting',
                $hostingId,
                ['customer_id' => $data['customer_id'], 'type' => $data['type'], 'ip_address' => $data['ip_address']],
                $this->request->ip()
            );

            $this->redirectWith('/admin/hosting', 'success', '主機資產已建立。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 編輯主機資產表單。
     */
    public function edit(): void
    {
        $this->guardPermission('hosting.edit');
        $id = (int) $this->request->param('id');
        $hosting = $this->hostingService->findById($id);

        if ($hosting === null) {
            $this->redirectWith('/admin/hosting', 'error', '主機資產不存在。');
        }

        $this->render('admin/hosting/edit', [
            'title'          => '編輯客戶主機',
            'hosting'        => $hosting,
            'customers'      => AssetHelper::customerOptions(),
            'websitesByCust' => $this->websitesMapForSelect(),
            'typeLabels'     => AssetHelper::HOSTING_TYPES,
            'statusLabels'   => AssetHelper::ASSET_STATUSES,
        ]);
    }

    /**
     * 更新主機資產。
     */
    public function update(): void
    {
        $this->guardPermission('hosting.edit');
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $id = (int) $this->request->param('id');

        if (!$this->validateInput()) {
            return;
        }

        $data = $this->collectData();

        try {
            $this->hostingService->update($id, $data);

            $this->auditLogService->log(
                $this->currentUserId(),
                'hosting_updated',
                'customer_hosting',
                $id,
                ['fields' => array_keys($data)],
                $this->request->ip()
            );

            $this->redirectWith('/admin/hosting', 'success', '主機資產已更新。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 刪除主機資產。
     */
    public function destroy(): void
    {
        $this->guardPermission('hosting.delete');
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $id = (int) $this->request->param('id');

        try {
            $this->hostingService->delete($id);

            $this->auditLogService->log(
                $this->currentUserId(),
                'hosting_deleted',
                'customer_hosting',
                $id,
                null,
                $this->request->ip()
            );

            $this->redirectWith('/admin/hosting', 'success', '主機資產已刪除。');
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
            'customer_id'        => 'required|integer',
            'type'               => 'required|in:shared,vps',
            'ip_address'         => 'string|max:45',
            'account_email'      => 'email|max:255',
            'spec'               => 'string|max:5000',
            'start_date'         => 'string|max:10',
            'end_date'           => 'string|max:10',
            'status'             => 'required|in:active,expired,terminated',
            'related_website_id' => 'integer',
        ]);

        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
            return false;
        }

        return true;
    }

    /**
     * 從請求蒐集主機資產欄位（不含 created_by；由呼叫端補上）。
     * 日期空字串正規化為 null；文字修剪空白；related_website_id 空值轉 null。
     *
     * @return array<string, mixed>
     */
    private function collectData(): array
    {
        $relatedRaw = $this->request->input('related_website_id', '');
        $relatedWebsiteId = ($relatedRaw === '' || $relatedRaw === null) ? null : (int) $relatedRaw;

        return [
            'customer_id'        => (int) $this->request->input('customer_id', 0),
            'type'               => (string) $this->request->input('type', 'shared'),
            'ip_address'         => trim((string) $this->request->input('ip_address', '')),
            'account_email'      => trim((string) $this->request->input('account_email', '')),
            'spec'               => trim((string) $this->request->input('spec', '')),
            'start_date'         => $this->nullableDate($this->request->input('start_date')),
            'end_date'           => $this->nullableDate($this->request->input('end_date')),
            'status'             => (string) $this->request->input('status', 'active'),
            'related_website_id' => $relatedWebsiteId,
            'notes'              => trim((string) $this->request->input('notes', '')),
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
            return null;
        }

        return $value;
    }

    /**
     * 取得「客戶 → 其網站清單」映射，供關聯網站下拉於前端依選定客戶過濾。
     * 僅含有網站的客戶；每筆網站取 id 與顯示標籤（URL 或案件類型）。
     * 一次查詢避免 N+1；資料量大時仍可接受（CRM 場景）。
     *
     * @return array<int, array<int, array{id: int, label: string}>> 以 customer_id 為鍵
     */
    private function websitesMapForSelect(): array
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT id, customer_id, url, case_type
             FROM {prefix}customer_websites
             ORDER BY customer_id ASC, id DESC"
        );

        $map = [];
        foreach ($rows as $row) {
            $cid   = (int) $row['customer_id'];
            $url   = trim((string) ($row['url'] ?? ''));
            $ctype = AssetHelper::WEBSITE_CASE_TYPES[$row['case_type']] ?? (string) $row['case_type'];
            $label = $url !== '' ? $url : ('（' . $ctype . ' #' . (int) $row['id'] . '）');
            $map[$cid][] = [
                'id'    => (int) $row['id'],
                'label' => $label,
            ];
        }

        return $map;
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
