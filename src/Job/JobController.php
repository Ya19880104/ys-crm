<?php

declare(strict_types=1);

namespace YangSheep\CRM\Job;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Core\Validator;
use YangSheep\CRM\AuditLog\AuditLogService;
use YangSheep\CRM\Role\RoleService;

/**
 * 工作看板控制器。
 * 架構與 CustomerController / WebsiteController 一致：CSRF + Validator + AuditLog
 * + redirectWith/backWithError。所有寫入過 CSRF；輸入經 Validator；輸出於 view 以 e() 轉義；
 * 查詢全程 prepared（在 Repository）。
 *
 * 細粒度權限：群組進入需 jobs.view（routes 設定）；create/edit/delete/欄位管理於本層
 * 以 RoleService 再檢查對應權限碼（jobs.create / jobs.edit / jobs.delete / job_columns.manage）。
 *
 * 對應架構設計 §7.6。
 */
class JobController extends Controller
{
    private JobService $jobService;
    private AuditLogService $auditLogService;
    private RoleService $roleService;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->jobService     = new JobService();
        $this->auditLogService = new AuditLogService();
        $this->roleService    = new RoleService();
    }

    // ───────────────────────── 看板 ─────────────────────────

    /**
     * 總看板（跨客戶；可選依負責人篩選）。
     */
    public function index(): void
    {
        $assignedTo = (int) $this->request->query('assigned_to', '0');

        $filters = [];
        if ($assignedTo > 0) {
            $filters['assigned_to'] = $assignedTo;
        }

        $board = $this->jobService->getBoard($filters);

        $this->render('admin/jobs/index', [
            'title'          => '工作看板',
            'board'          => $board,
            'columns'        => $this->jobService->getColumns(true),
            'customers'      => $this->customerOptions(),
            'assignees'      => $this->assigneeOptions(),
            'priorityLabels' => $this->priorityLabels(),
            'filterAssignee' => $assignedTo,
            'canCreate'      => $this->can('jobs.create'),
            'canEdit'        => $this->can('jobs.edit'),
            'canManageCols'  => $this->can('job_columns.manage'),
        ]);
    }

    /**
     * 卡片詳情（內容 + 追加內容 + 計時器）。
     */
    public function show(): void
    {
        $id  = (int) $this->request->param('id');
        $job = $this->jobService->getJobDetail($id);

        if ($job === null) {
            $this->redirectWith('/admin/jobs', 'error', '工作不存在。');
        }

        $this->render('admin/jobs/show', [
            'title'          => $job['title'],
            'job'            => $job,
            'priorityLabels' => $this->priorityLabels(),
            'canEdit'        => $this->can('jobs.edit'),
            'canDelete'      => $this->can('jobs.delete'),
        ]);
    }

    // ───────────────────────── 卡片 CRUD ─────────────────────────

    /**
     * 新增卡片表單。
     */
    public function create(): void
    {
        $this->requirePermission('jobs.create');

        $this->render('admin/jobs/create', [
            'title'          => '新增工作',
            'columns'        => $this->jobService->getColumns(true),
            'customers'      => $this->customerOptions(),
            'assignees'      => $this->assigneeOptions(),
            'priorityLabels' => $this->priorityLabels(),
            'presetColumn'   => (int) $this->request->query('column_id', '0'),
            'presetCustomer' => (int) $this->request->query('customer_id', '0'),
        ]);
    }

    /**
     * 建立卡片。
     */
    public function store(): void
    {
        $this->guardCsrf();
        $this->requirePermission('jobs.create');

        if (!$this->validateJobInput()) {
            return;
        }

        $data = $this->collectJobData();
        $data['created_by'] = $this->currentUserId() ?: null;

        // 封面圖（選填）
        $cover = $this->request->file('cover_image');
        if ($cover !== null && ($cover['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $uploaded = $this->jobService->handleImageUpload($cover);
            if ($uploaded === null) {
                $this->backWithError('封面圖上傳失敗：請確認為 jpg/png/gif/webp 且不超過 5MB。');
            }
            $data['cover_image_path'] = $uploaded['path'];
        }

        try {
            $jobId = $this->jobService->createJob($data);

            $this->auditLogService->log(
                $this->currentUserId(),
                'job_created',
                'job',
                $jobId,
                ['title' => $data['title'], 'column_id' => $data['column_id'], 'customer_id' => $data['customer_id'] ?? null],
                $this->request->ip()
            );

            $this->redirectWith('/admin/jobs/' . $jobId, 'success', '工作已建立。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 編輯卡片表單。
     */
    public function edit(): void
    {
        $this->requirePermission('jobs.edit');

        $id  = (int) $this->request->param('id');
        $job = $this->jobService->getJobDetail($id);

        if ($job === null) {
            $this->redirectWith('/admin/jobs', 'error', '工作不存在。');
        }

        $this->render('admin/jobs/edit', [
            'title'          => '編輯工作',
            'job'            => $job,
            'columns'        => $this->jobService->getColumns(true),
            'customers'      => $this->customerOptions(),
            'assignees'      => $this->assigneeOptions(),
            'priorityLabels' => $this->priorityLabels(),
        ]);
    }

    /**
     * 更新卡片。
     */
    public function update(): void
    {
        $this->guardCsrf();
        $this->requirePermission('jobs.edit');

        $id = (int) $this->request->param('id');

        if (!$this->validateJobInput()) {
            return;
        }

        $data = $this->collectJobData();

        // 封面圖（選填；有上傳才覆寫）
        $cover = $this->request->file('cover_image');
        if ($cover !== null && ($cover['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $uploaded = $this->jobService->handleImageUpload($cover);
            if ($uploaded === null) {
                $this->backWithError('封面圖上傳失敗：請確認為 jpg/png/gif/webp 且不超過 5MB。');
            }
            $data['cover_image_path'] = $uploaded['path'];
        }

        try {
            $this->jobService->updateJob($id, $data);

            $this->auditLogService->log(
                $this->currentUserId(),
                'job_updated',
                'job',
                $id,
                ['fields' => array_keys($data)],
                $this->request->ip()
            );

            $this->redirectWith('/admin/jobs/' . $id, 'success', '工作已更新。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 刪除卡片。
     */
    public function destroy(): void
    {
        $this->guardCsrf();
        $this->requirePermission('jobs.delete');

        $id = (int) $this->request->param('id');

        try {
            $this->jobService->deleteJob($id);

            $this->auditLogService->log(
                $this->currentUserId(),
                'job_deleted',
                'job',
                $id,
                null,
                $this->request->ip()
            );

            $this->redirectWith('/admin/jobs', 'success', '工作已刪除。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 移動卡片（拖拉 AJAX 或變更狀態下拉）。
     * - AJAX（JSON）：回傳 JSON。
     * - 表單：redirect 回看板。
     */
    public function move(): void
    {
        $isAjax = $this->request->isAjax();

        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            if ($isAjax) {
                $this->json(['success' => false, 'message' => 'CSRF 驗證失敗'], 419);
                return;
            }
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        if (!$this->roleService->hasPermission($this->currentUserId(), 'jobs.edit')) {
            if ($isAjax) {
                $this->json(['success' => false, 'message' => '權限不足'], 403);
                return;
            }
            $this->backWithError('您沒有移動工作的權限。');
        }

        $id       = (int) $this->request->param('id');
        $columnId = (int) $this->request->input('column_id', 0);
        $sort     = (int) $this->request->input('sort', 0);

        if ($columnId <= 0) {
            if ($isAjax) {
                $this->json(['success' => false, 'message' => '缺少目標欄位'], 422);
                return;
            }
            $this->backWithError('缺少目標欄位。');
        }

        try {
            $this->jobService->moveJob($id, $columnId, $sort);

            $this->auditLogService->log(
                $this->currentUserId(),
                'job_moved',
                'job',
                $id,
                ['column_id' => $columnId, 'sort' => $sort],
                $this->request->ip()
            );

            if ($isAjax) {
                $this->json(['success' => true, 'message' => '已移動']);
                return;
            }
            $this->redirectWith('/admin/jobs', 'success', '工作已移動。');
        } catch (\RuntimeException $e) {
            if ($isAjax) {
                $this->json(['success' => false, 'message' => $e->getMessage()], 422);
                return;
            }
            $this->backWithError($e->getMessage());
        }
    }

    // ───────────────────────── 追加內容 ─────────────────────────

    /**
     * 新增追加內容（含選填附圖）。
     */
    public function addEntry(): void
    {
        $this->guardCsrf();
        $this->requirePermission('jobs.edit');

        $jobId   = (int) $this->request->param('id');
        $content = trim((string) $this->request->input('content', ''));

        $media = null;
        $image = $this->request->file('image');
        if ($image !== null && ($image['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $media = $this->jobService->handleImageUpload($image);
            if ($media === null) {
                $this->backWithError('附圖上傳失敗：請確認為 jpg/png/gif/webp 且不超過 5MB。');
            }
        }

        if ($content === '' && $media === null) {
            $this->backWithError('請輸入內容或選擇附圖。');
        }

        try {
            $entryId = $this->jobService->addEntry($jobId, $this->currentUserId() ?: null, $content, $media);

            $this->auditLogService->log(
                $this->currentUserId(),
                'job_entry_created',
                'job',
                $jobId,
                ['entry_id' => $entryId],
                $this->request->ip()
            );

            $this->redirectWith('/admin/jobs/' . $jobId, 'success', '已新增追加內容。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 刪除追加內容。
     */
    public function deleteEntry(): void
    {
        $this->guardCsrf();
        $this->requirePermission('jobs.edit');

        $jobId   = (int) $this->request->param('id');
        $entryId = (int) $this->request->param('entry_id');

        try {
            $this->jobService->deleteEntry($jobId, $entryId);

            $this->auditLogService->log(
                $this->currentUserId(),
                'job_entry_deleted',
                'job',
                $jobId,
                ['entry_id' => $entryId],
                $this->request->ip()
            );

            $this->redirectWith('/admin/jobs/' . $jobId, 'success', '追加內容已刪除。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    // ───────────────────────── 計時器 ─────────────────────────

    /**
     * 開始 / 繼續計時。
     */
    public function timerStart(): void
    {
        $this->guardCsrf();
        $this->requirePermission('jobs.edit');

        $jobId = (int) $this->request->param('id');
        $note  = trim((string) $this->request->input('note', ''));

        try {
            $timerId = $this->jobService->startTimer($jobId, $this->currentUserId() ?: null, $note);

            $this->auditLogService->log(
                $this->currentUserId(),
                'job_timer_started',
                'job',
                $jobId,
                ['timer_id' => $timerId],
                $this->request->ip()
            );

            $this->redirectWith('/admin/jobs/' . $jobId, 'success', '計時已開始。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 停止計時。
     */
    public function timerStop(): void
    {
        $this->guardCsrf();
        $this->requirePermission('jobs.edit');

        $jobId = (int) $this->request->param('id');

        try {
            $timerId = $this->jobService->stopTimer($jobId);

            $this->auditLogService->log(
                $this->currentUserId(),
                'job_timer_stopped',
                'job',
                $jobId,
                ['timer_id' => $timerId],
                $this->request->ip()
            );

            $this->redirectWith('/admin/jobs/' . $jobId, 'success', '計時已停止。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    // ───────────────────────── 看板欄位管理 ─────────────────────────

    /**
     * 欄位管理頁。
     */
    public function columns(): void
    {
        $this->requirePermission('job_columns.manage');

        $this->render('admin/jobs/columns', [
            'title'   => '看板欄位管理',
            'columns' => $this->jobService->getColumns(false),
        ]);
    }

    /**
     * 建立欄位。
     */
    public function storeColumn(): void
    {
        $this->guardCsrf();
        $this->requirePermission('job_columns.manage');

        $validator = Validator::make($this->request->all(), [
            'name'  => 'required|string|min:1|max:100',
            'slug'  => 'string|max:50',
            'color' => 'string|max:7',
        ]);
        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        try {
            $columnId = $this->jobService->createColumn([
                'name'      => trim((string) $this->request->input('name', '')),
                'slug'      => trim((string) $this->request->input('slug', '')),
                'color'     => (string) $this->request->input('color', ''),
                'is_active' => $this->request->input('is_active') ? 1 : 0,
            ]);

            $this->auditLogService->log(
                $this->currentUserId(),
                'job_column_created',
                'job_column',
                $columnId,
                ['name' => $this->request->input('name')],
                $this->request->ip()
            );

            $this->redirectWith('/admin/jobs/columns', 'success', '欄位已建立。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 更新欄位。
     */
    public function updateColumn(): void
    {
        $this->guardCsrf();
        $this->requirePermission('job_columns.manage');

        $id = (int) $this->request->param('id');

        $validator = Validator::make($this->request->all(), [
            'name'  => 'required|string|min:1|max:100',
            'color' => 'string|max:7',
        ]);
        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        try {
            $this->jobService->updateColumn($id, [
                'name'      => trim((string) $this->request->input('name', '')),
                'color'     => (string) $this->request->input('color', ''),
                'is_active' => $this->request->input('is_active') ? 1 : 0,
            ]);

            $this->auditLogService->log(
                $this->currentUserId(),
                'job_column_updated',
                'job_column',
                $id,
                null,
                $this->request->ip()
            );

            $this->redirectWith('/admin/jobs/columns', 'success', '欄位已更新。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 刪除欄位（含卡片時 Service 拒絕）。
     */
    public function destroyColumn(): void
    {
        $this->guardCsrf();
        $this->requirePermission('job_columns.manage');

        $id = (int) $this->request->param('id');

        try {
            $this->jobService->deleteColumn($id);

            $this->auditLogService->log(
                $this->currentUserId(),
                'job_column_deleted',
                'job_column',
                $id,
                null,
                $this->request->ip()
            );

            $this->redirectWith('/admin/jobs/columns', 'success', '欄位已刪除。');
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
     * 細粒度權限守門：無權限即導回看板（不在路由群組層攔，因群組僅需 jobs.view）。
     */
    private function requirePermission(string $code): void
    {
        if (!$this->roleService->hasPermission($this->currentUserId(), $code)) {
            $this->redirectWith('/admin/jobs', 'error', '您沒有執行此操作的權限。');
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
     * 驗證卡片輸入；失敗時設 flash 並導回（回傳 false）。
     */
    private function validateJobInput(): bool
    {
        $validator = Validator::make($this->request->all(), [
            'title'       => 'required|string|min:1|max:255',
            'column_id'   => 'required|integer',
            'customer_id' => 'integer',
            'priority'    => 'in:low,medium,high,critical',
            'assigned_to' => 'integer',
            'due_date'    => 'string|max:10',
        ]);

        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
            return false;
        }
        return true;
    }

    /**
     * 從請求蒐集卡片欄位（不含 created_by / 封面；由呼叫端補上）。
     *
     * @return array<string, mixed>
     */
    private function collectJobData(): array
    {
        $assignedTo = $this->request->input('assigned_to', '');
        $customerId = $this->request->input('customer_id', '');

        return [
            'column_id'   => (int) $this->request->input('column_id', 0),
            'customer_id' => ($customerId === '' || $customerId === null) ? null : (int) $customerId,
            'title'       => trim((string) $this->request->input('title', '')),
            'description' => trim((string) $this->request->input('description', '')),
            'priority'    => (string) $this->request->input('priority', 'medium'),
            'assigned_to' => ($assignedTo === '' || $assignedTo === null) ? null : (int) $assignedTo,
            'due_date'    => $this->nullableDate($this->request->input('due_date')),
        ];
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
     * 負責人下拉選項（啟用中的我方使用者）。
     *
     * @return array<int, array{id: int, display_name: string}>
     */
    private function assigneeOptions(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT id, display_name FROM {prefix}users WHERE status = 'active' ORDER BY display_name ASC"
        );
    }

    /**
     * 優先度代碼 → 中文標籤。
     *
     * @return array<string, string>
     */
    private function priorityLabels(): array
    {
        return [
            'low'      => '低',
            'medium'   => '中',
            'high'     => '高',
            'critical' => '緊急',
        ];
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
