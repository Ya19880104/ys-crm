<?php

declare(strict_types=1);

namespace YangSheep\CRM\Quote;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Core\Validator;
use YangSheep\CRM\AuditLog\AuditLogService;
use YangSheep\CRM\Role\RoleService;
use YangSheep\CRM\Setting\SettingService;

/**
 * 報價單後台控制器（CRUD + 編輯器 + 狀態動作）。
 * 架構與 JobController / CustomerController 一致：CSRF + Validator + AuditLog
 * + redirectWith/backWithError。所有寫入過 CSRF；輸入經 Validator；輸出於 view 以 e() 轉義；
 * 查詢全程 prepared（在 Repository）。金額一律由 Service 伺服器端重算（不信前端）。
 *
 * 細粒度權限：群組進入需 quotes.view（routes 設定）；create/edit/delete/送出於本層以
 * RoleService 再檢查對應權限碼。
 *
 * 對應架構設計 §7.8。
 */
class QuoteController extends Controller
{
    private QuoteService $quoteService;
    private AuditLogService $auditLogService;
    private RoleService $roleService;
    private SettingService $settingService;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->quoteService    = new QuoteService();
        $this->auditLogService = new AuditLogService();
        $this->roleService     = new RoleService();
        $this->settingService  = new SettingService();
    }

    // ───────────────────────── 列表 ─────────────────────────

    /**
     * 報價單列表（分頁 + 篩選 status/customer/keyword）。
     */
    public function index(): void
    {
        $page    = max(1, (int) $this->request->query('page', '1'));
        $perPage = 15;

        $filters = [
            'status'      => (string) $this->request->query('status', ''),
            'customer_id' => (int) $this->request->query('customer_id', '0'),
            'keyword'     => (string) $this->request->query('keyword', ''),
        ];

        $result = $this->quoteService->findAll($page, $perPage, $filters);

        $this->render('admin/quotes/index', [
            'title'         => '報價單',
            'quotes'        => $result['items'],
            'total'         => $result['total'],
            'page'          => $page,
            'perPage'       => $perPage,
            'totalPages'    => (int) ceil($result['total'] / $perPage),
            'filters'       => $filters,
            'customers'     => $this->customerOptions(),
            'statusLabels'  => self::statusLabels(),
            'visLabels'     => self::visibilityLabels(),
            'canCreate'     => $this->can('quotes.create'),
            'canEdit'       => $this->can('quotes.edit'),
            'canDelete'     => $this->can('quotes.delete'),
        ]);
    }

    // ───────────────────────── 後台檢視 ─────────────────────────

    /**
     * 後台檢視：報價全貌 + 公開連結 + 瀏覽軌跡 + 簽署狀態。
     */
    public function show(): void
    {
        $this->renderShowPage((int) $this->request->param('id'));
    }

    /**
     * 渲染後台檢視頁；分享設定驗證失敗時帶著使用者輸入與錯誤重新顯示（不導回）。
     *
     * @param array<string, mixed> $extra shareInput / shareError
     */
    private function renderShowPage(int $id, array $extra = [], int $status = 200): void
    {
        $quote = $this->quoteService->getQuoteForAdmin($id);

        if ($quote === null) {
            $this->redirectWith('/admin/quotes', 'error', '報價單不存在。');
        }

        // 付款記錄（§7.9）：若此報價已啟用付款，撈最新一筆付款供顯示連結與狀態。
        $payment = null;
        if ((int) ($quote['payment_enabled'] ?? 0) === 1) {
            $payment = (new \YangSheep\CRM\Payment\PaymentService())->findLatestByQuote($id);
        }

        if ($status !== 200) {
            $this->response->status($status);
        }

        $this->render('admin/quotes/show', [
            'title'        => $quote['quote_number'] . '｜' . $quote['title'],
            'quote'        => $quote,
            'company'      => $this->companyInfo(),
            'publicUrl'    => $this->publicUrl($quote),
            'shareView'    => $this->quoteService->shareView($quote),
            'shareInput'   => $extra['shareInput'] ?? null,
            'shareError'   => $extra['shareError'] ?? null,
            'statusLabels' => self::statusLabels(),
            'visLabels'    => self::visibilityLabels(),
            'canEdit'      => $this->can('quotes.edit'),
            'canDelete'    => $this->can('quotes.delete'),
            'payment'      => $payment,
            'paymentStatusLabels' => \YangSheep\CRM\Payment\PaymentController::statusLabels(),
        ]);
    }

    /**
     * 只更新分享期限（報價詳情頁的「修改分享設定」）。已簽署／已付款的報價同樣可用。
     */
    public function updateShare(): void
    {
        $this->guardCsrf();
        $this->requirePermission('quotes.edit');

        $id     = (int) $this->request->param('id');
        $input  = $this->collectShareInput();
        $before = $this->quoteService->findById($id);

        try {
            $event = $this->quoteService->updateSharePolicy($id, $input);
            if ($event === QuoteSharePolicy::EVENT_NONE) {
                $this->redirectWith('/admin/quotes/' . $id, 'success', '分享設定沒有變更。');
            }

            $this->auditShare($id, $event, $before, $this->quoteService->findById($id));
            $msg = $event === QuoteSharePolicy::EVENT_REOPENED
                ? '已重新公開：已產生新連結，舊連結永久失效。'
                : '分享設定已更新。';
            $this->redirectWith('/admin/quotes/' . $id, 'success', $msg);
        } catch (QuoteShareValidationException $e) {
            $this->auditShareRejected($id, $e, $input, (string) ($before['visibility'] ?? ''));
            $this->renderShowPage($id, [
                'shareInput' => $input,
                'shareError' => ['field' => $e->field, 'message' => $e->getMessage()],
            ], 422);
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    // ───────────────────────── 編輯器 ─────────────────────────

    /**
     * 新增報價單表單（編輯器）。
     */
    public function create(): void
    {
        $this->requirePermission('quotes.create');

        $this->render('admin/quotes/create', [
            'title'          => '新增報價單',
            'quote'          => [],
            'items'          => [],
            'customers'      => $this->customerOptions(),
            'statusLabels'   => self::statusLabels(),
            'visLabels'      => self::visibilityLabels(),
            'presetCustomer' => (int) $this->request->query('customer_id', '0'),
            'defaultTaxRate' => $this->defaultTaxRate(),
            'shareView'      => $this->quoteService->shareView([]),
        ]);
    }

    /**
     * 建立報價單（含明細）。
     */
    public function store(): void
    {
        $this->guardCsrf();
        $this->requirePermission('quotes.create');

        if (!$this->validateQuoteInput()) {
            return;
        }

        $data = $this->collectQuoteData();
        $data['created_by'] = $this->currentUserId() ?: null;
        $data['send_now']   = (string) $this->request->input('action', '') === 'send';
        $data['share']      = $this->collectShareInput();

        $items = $this->collectItems();

        try {
            $quoteId = $this->quoteService->createQuote($data, $items);

            $this->auditLogService->log(
                $this->currentUserId(),
                'quote_created',
                'quote',
                $quoteId,
                ['title' => $data['title'], 'customer_id' => $data['customer_id'] ?? null, 'sent' => $data['send_now']],
                $this->request->ip()
            );

            $created = $this->quoteService->findById($quoteId);
            if ($created !== null && QuoteSharePolicy::isAnonymous((string) $created['visibility'])) {
                $this->auditShare($quoteId, QuoteSharePolicy::EVENT_OPENED, null, $created);
            }

            $msg = $data['send_now'] ? '報價單已建立並送出。' : '報價單已建立。';
            $this->redirectWith('/admin/quotes/' . $quoteId, 'success', $msg);
        } catch (QuoteShareValidationException $e) {
            $this->auditShareRejected(null, $e, $data['share'], (string) $data['visibility']);
            $this->renderFormAfterShareError(null, $e);
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 編輯報價單表單（編輯器）。
     */
    public function edit(): void
    {
        $this->requirePermission('quotes.edit');

        $id    = (int) $this->request->param('id');
        $quote = $this->quoteService->getQuoteDetail($id);

        if ($quote === null) {
            $this->redirectWith('/admin/quotes', 'error', '報價單不存在。');
        }
        if (in_array($quote['status'], ['signed', 'paid'], true)) {
            $this->redirectWith('/admin/quotes/' . $id, 'error', '已簽署或已付款的報價單不可再編輯。');
        }

        $this->render('admin/quotes/edit', [
            'title'        => '編輯報價單：' . $quote['quote_number'],
            'quote'        => $quote,
            'items'        => $quote['items'],
            'customers'    => $this->customerOptions(),
            'statusLabels' => self::statusLabels(),
            'visLabels'    => self::visibilityLabels(),
            'defaultTaxRate' => $this->defaultTaxRate(),
            'shareView'      => $this->quoteService->shareView($quote),
        ]);
    }

    /**
     * 更新報價單（含明細）。
     */
    public function update(): void
    {
        $this->guardCsrf();
        $this->requirePermission('quotes.edit');

        $id = (int) $this->request->param('id');

        if (!$this->validateQuoteInput()) {
            return;
        }

        $data          = $this->collectQuoteData();
        $data['share'] = $this->collectShareInput();
        $items         = $this->collectItems();
        $before        = $this->quoteService->findById($id);

        try {
            $shareEvent = $this->quoteService->updateQuote($id, $data, $items);

            // 編輯後若按「儲存並送出」，再嘗試送出（草稿/逾期 → sent）。
            if ((string) $this->request->input('action', '') === 'send') {
                try {
                    $this->quoteService->sendQuote($id);
                } catch (\RuntimeException) {
                    // 目前狀態不可送出（如已 viewed）則忽略送出，僅儲存。
                }
            }

            $this->auditLogService->log(
                $this->currentUserId(),
                'quote_updated',
                'quote',
                $id,
                ['fields' => array_keys($data)],
                $this->request->ip()
            );

            if ($shareEvent !== QuoteSharePolicy::EVENT_NONE) {
                $this->auditShare(
                    $id,
                    $shareEvent,
                    $before,
                    $this->quoteService->findById($id),
                    $shareEvent === QuoteSharePolicy::EVENT_CLOSED ? 'visibility_changed' : null
                );
            }

            $this->redirectWith('/admin/quotes/' . $id, 'success', '報價單已更新。');
        } catch (QuoteShareValidationException $e) {
            $this->auditShareRejected($id, $e, $data['share'], (string) $data['visibility']);
            $this->renderFormAfterShareError($before, $e);
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 立即關閉公開連結（匿名分享）。報價資料、簽署與款項都不受影響。
     */
    public function closeShare(): void
    {
        $this->guardCsrf();
        $this->requirePermission('quotes.edit');

        $id = (int) $this->request->param('id');

        try {
            $before = $this->quoteService->findById($id);
            if (!$this->quoteService->closeShare($id)) {
                $this->redirectWith('/admin/quotes/' . $id, 'error', '此報價目前沒有可關閉的公開連結。');
            }

            $this->auditShare($id, QuoteSharePolicy::EVENT_CLOSED, $before, $this->quoteService->findById($id), 'manual');
            $this->redirectWith('/admin/quotes/' . $id, 'success', '公開連結已關閉，訪客將無法再開啟。重新公開時會產生新連結。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 刪除報價單。
     */
    public function destroy(): void
    {
        $this->guardCsrf();
        $this->requirePermission('quotes.delete');

        $id = (int) $this->request->param('id');

        try {
            $this->quoteService->deleteQuote($id);

            $this->auditLogService->log(
                $this->currentUserId(),
                'quote_deleted',
                'quote',
                $id,
                null,
                $this->request->ip()
            );

            $this->redirectWith('/admin/quotes', 'success', '報價單已刪除。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    // ───────────────────────── 狀態動作 ─────────────────────────

    /**
     * 送出報價單（draft/expired → sent）。
     */
    public function send(): void
    {
        $this->guardCsrf();
        $this->requirePermission('quotes.edit');

        $id = (int) $this->request->param('id');

        try {
            $this->quoteService->sendQuote($id);

            $this->auditLogService->log(
                $this->currentUserId(),
                'quote_sent',
                'quote',
                $id,
                null,
                $this->request->ip()
            );

            $this->redirectWith('/admin/quotes/' . $id, 'success', '報價單已標記為已送出。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 變更狀態（後台手動：如標記作廢 void、標記已付款 paid 等）。
     * 合法性由 Service 狀態機把關。
     */
    public function changeStatus(): void
    {
        $this->guardCsrf();
        $this->requirePermission('quotes.edit');

        $id = (int) $this->request->param('id');
        $to = (string) $this->request->input('status', '');

        try {
            $this->quoteService->transitionStatus($id, $to);

            $this->auditLogService->log(
                $this->currentUserId(),
                'quote_status_changed',
                'quote',
                $id,
                ['to' => $to],
                $this->request->ip()
            );

            $this->redirectWith('/admin/quotes/' . $id, 'success', '報價單狀態已更新。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    // ───────────────────────── 內部輔助 ─────────────────────────

    /**
     * CSRF 驗證守門（表單寫入用）。失敗即導回。
     */
    private function guardCsrf(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }
    }

    /**
     * 細粒度權限守門：無權限即導回列表。
     */
    private function requirePermission(string $code): void
    {
        if (!$this->roleService->hasPermission($this->currentUserId(), $code)) {
            $this->redirectWith('/admin/quotes', 'error', '您沒有執行此操作的權限。');
        }
    }

    /**
     * 是否具備某權限（供 view 顯示按鈕）。
     */
    private function can(string $code): bool
    {
        return $this->roleService->hasPermission($this->currentUserId(), $code);
    }

    /**
     * 驗證報價主檔輸入；失敗時設 flash 並導回（回傳 false）。
     */
    private function validateQuoteInput(): bool
    {
        $validator = Validator::make($this->request->all(), [
            'title'       => 'required|string|min:1|max:255',
            'customer_id' => 'integer',
            'visibility'  => 'required|in:private,public,password,customer_only',
            'tax_rate'    => 'numeric',
            'valid_until' => 'string|max:10',
        ]);

        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
            return false;
        }

        // 密碼可見性需有密碼（建立時必填；編輯時可留空沿用既有）。
        $visibility = (string) $this->request->input('visibility', '');
        $isCreate   = $this->request->param('id') === null;
        if ($visibility === 'password' && $isCreate) {
            $pwd = (string) $this->request->input('access_password', '');
            if ($pwd === '') {
                $this->backWithError('選擇「密碼保護」時必須設定存取密碼。');
                return false;
            }
        }

        return true;
    }

    /**
     * 從請求蒐集報價主檔欄位（不含 created_by / send_now；由呼叫端補上）。
     *
     * @return array<string, mixed>
     */
    private function collectQuoteData(): array
    {
        $customerId = $this->request->input('customer_id', '');

        return [
            'customer_id'     => ($customerId === '' || $customerId === null) ? null : (int) $customerId,
            'title'           => trim((string) $this->request->input('title', '')),
            'visibility'      => (string) $this->request->input('visibility', 'private'),
            'access_password' => (string) $this->request->input('access_password', ''),
            'tax_rate'        => (float) $this->request->input('tax_rate', 0),
            'valid_until'     => $this->nullableDate($this->request->input('valid_until')),
            'terms'           => trim((string) $this->request->input('terms', '')),
            'notes'           => trim((string) $this->request->input('notes', '')),
            'payment_enabled' => $this->request->input('payment_enabled') ? 1 : 0,
        ];
    }

    /**
     * 蒐集分享期限欄位（Q3）。
     *
     * share_form=1 代表表單確實送出了分享區；缺席時 Service 對「第一次公開」採預設（啟用＋30 天），
     * 對進行中的分享則不變動——絕不會因為少送欄位就默認成無期限。
     *
     * @return array<string, mixed>
     */
    private function collectShareInput(): array
    {
        $flag = fn(string $key): bool => (string) $this->request->input($key, '') === '1';

        return [
            'present'           => $flag('share_form'),
            'auto_expire'       => $flag('share_auto_expire'),
            'days'              => (string) $this->request->input('share_duration_days', ''),
            'unlimited_ack'     => $flag('share_unlimited_ack'),
            'reopen_confirm'    => $flag('share_reopen_confirm'),
            'close_now_confirm' => $flag('share_close_now_confirm'),
        ];
    }

    /**
     * 分享設定驗證失敗：以剛送出的內容直接重新顯示表單（不導回），
     * 保留使用者輸入、把錯誤標在分享區；已生效的分享狀態與警語維持不變。
     * 存取密碼永不回填。
     *
     * @param array<string, mixed>|null $existing 編輯時為修改前的報價列；新增時為 null
     */
    private function renderFormAfterShareError(?array $existing, QuoteShareValidationException $e): void
    {
        $submitted = $this->collectQuoteData();
        unset($submitted['access_password']);

        $vars = [
            'quote'          => $existing !== null ? array_merge($existing, $submitted) : $submitted,
            'items'          => $this->collectItems(),
            'customers'      => $this->customerOptions(),
            'statusLabels'   => self::statusLabels(),
            'visLabels'      => self::visibilityLabels(),
            'defaultTaxRate' => $this->defaultTaxRate(),
            'shareView'      => $this->quoteService->shareView($existing ?? []),
            'shareInput'     => $this->collectShareInput(),
            'shareError'     => ['field' => $e->field, 'message' => $e->getMessage()],
        ];

        $this->response->status(422);
        if ($existing !== null) {
            $this->render('admin/quotes/edit', ['title' => '編輯報價單：' . $existing['quote_number']] + $vars);
            return;
        }
        $this->render('admin/quotes/create', ['title' => '新增報價單', 'presetCustomer' => 0] + $vars);
    }

    /**
     * 分享政策稽核：記錄 actor、事件與前後值（只記政策與時間點，不記 token 或密碼）。
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function auditShare(int $quoteId, string $event, ?array $before, ?array $after, ?string $reason = null): void
    {
        $detail = ['event' => $event];
        if ($reason !== null) {
            $detail['reason'] = $reason;
        }
        if ($before !== null) {
            $detail['before'] = self::shareAuditSnapshot($before);
        }
        if ($after !== null) {
            $detail['after'] = self::shareAuditSnapshot($after);
        }

        $this->auditLogService->log($this->currentUserId(), 'quote_share_' . $event, 'quote', $quoteId, $detail, $this->request->ip());
    }

    /**
     * 分享設定被拒（缺少確認、天數不合法）也留紀錄：誰、想改成什麼、卡在哪個欄位。
     *
     * @param array<string, mixed> $input
     */
    private function auditShareRejected(?int $quoteId, QuoteShareValidationException $e, array $input, string $visibility): void
    {
        $this->auditLogService->log(
            $this->currentUserId(),
            'quote_share_rejected',
            'quote',
            $quoteId,
            [
                'field'                 => $e->field,
                'visibility'            => $visibility,
                'requested_auto_expire' => !empty($input['auto_expire']),
                'requested_days'        => (string) ($input['days'] ?? ''),
            ],
            $this->request->ip()
        );
    }

    /**
     * @param array<string, mixed> $quote
     * @return array<string, mixed>
     */
    private static function shareAuditSnapshot(array $quote): array
    {
        $iso = static fn($ts): ?string => ($ts === null || $ts === '') ? null : gmdate('c', (int) $ts);

        return [
            'visibility'        => (string) ($quote['visibility'] ?? ''),
            'auto_expire'       => $quote['share_auto_expire'] ?? null,
            'duration_days'     => $quote['share_duration_days'] ?? null,
            'enabled_at_utc'    => $iso($quote['share_enabled_at'] ?? null),
            'expires_at_utc'    => $iso($quote['share_expires_at'] ?? null),
            'closed_at_utc'     => $iso($quote['share_closed_at'] ?? null),
            'unlimited_ack_utc' => $iso($quote['share_unlimited_ack_at'] ?? null),
            'revision'          => (int) ($quote['share_policy_revision'] ?? 0),
        ];
    }

    /**
     * 從請求蒐集明細陣列（表單以 items[idx][field] 命名）。
     * 僅蒐集原始輸入；金額計算與空列過濾由 Service::normalizeItems 處理。
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectItems(): array
    {
        $raw = $this->request->input('items', []);
        if (!is_array($raw)) {
            return [];
        }

        $items = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = [
                'name'        => (string) ($row['name'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'qty'         => (string) ($row['qty'] ?? ''),
                'unit'        => (string) ($row['unit'] ?? ''),
                'unit_price'  => (string) ($row['unit_price'] ?? ''),
            ];
        }

        return $items;
    }

    /**
     * 日期正規化：空 / 非 Y-m-d → null。
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
     * 客戶下拉選項。
     *
     * @return array<int, array{id: int, display_name: string}>
     */
    private function customerOptions(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT id, display_name FROM {prefix}customers ORDER BY display_name ASC"
        );
    }

    /**
     * 我方公司資訊（供後台檢視頁顯示報價抬頭）。
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
     * 預設稅率（公司設定可擴充；本階段固定 5，留接點）。
     * 接點：未來可改讀 settings('company','default_tax_rate')。
     */
    private function defaultTaxRate(): float
    {
        return 5.0;
    }

    /**
     * 組公開報價 URL（visibility ≠ private 時於後台顯示可複製連結）。
     * private 回 null（無公開連結）。
     */
    private function publicUrl(array $quote): ?string
    {
        if (($quote['visibility'] ?? 'private') === 'private') {
            return null;
        }
        $token = (string) ($quote['access_token'] ?? '');
        if ($token === '') {
            return null;
        }

        // 這條連結會被複製後寄給客戶，Host header 由請求方控制，不能拿來拼。
        return \YangSheep\CRM\Core\RequestOrigin::absoluteUrl('/q/' . $token);
    }

    /**
     * 取得當前登入使用者 ID（無則 0）。
     */
    private function currentUserId(): int
    {
        $user = Session::get('user');
        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }

    /**
     * 狀態代碼 → 中文標籤。
     *
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            'draft'   => '草稿',
            'sent'    => '已送出',
            'viewed'  => '已檢視',
            'signed'  => '已簽署',
            'paid'    => '已付款',
            'expired' => '已逾期',
            'void'    => '已作廢',
        ];
    }

    /**
     * 可見性代碼 → 中文標籤。
     *
     * @return array<string, string>
     */
    public static function visibilityLabels(): array
    {
        return [
            'private'       => '私密（僅後台）',
            'public'        => '公開連結',
            'password'      => '密碼保護',
            'customer_only' => '僅限客戶',
        ];
    }
}
