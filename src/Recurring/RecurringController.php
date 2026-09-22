<?php

declare(strict_types=1);

namespace YangSheep\CRM\Recurring;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\AuditLog\AuditLogService;
use YangSheep\CRM\Role\RoleService;

/**
 * 週期帳務後台控制器（對應架構設計 §7.8 週期單、§7.9）。
 *
 * 架構與 PaymentController / QuoteController 一致：CSRF + 細粒度權限 + AuditLog
 * + redirectWith/backWithError。
 *
 * 權限：群組進入需 recurring.view（routes 設定）；建立/編輯/啟停/立即產生於本層再檢查 recurring.manage。
 */
class RecurringController extends Controller
{
    private RecurringService $service;
    private RecurringRepository $repository;
    private AuditLogService $auditLog;
    private RoleService $roleService;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->service    = new RecurringService();
        $this->repository = new RecurringRepository();
        $this->auditLog   = new AuditLogService();
        $this->roleService = new RoleService();
    }

    // ───────────────────────── 列表 ─────────────────────────

    public function index(): void
    {
        $page    = max(1, (int) $this->request->query('page', '1'));
        $perPage = 20;

        $filters = [
            'is_active'   => (string) $this->request->query('is_active', ''),
            'customer_id' => (int) $this->request->query('customer_id', '0'),
        ];

        $result = $this->service->findAll($page, $perPage, $filters);

        $this->render('admin/recurring/index', [
            'title'        => '週期帳務',
            'schedules'    => $result['items'],
            'total'        => $result['total'],
            'page'         => $page,
            'perPage'      => $perPage,
            'totalPages'   => (int) ceil($result['total'] / $perPage),
            'filters'      => $filters,
            'customers'    => $this->customerOptions(),
            'unitLabels'   => self::unitLabels(),
            'modeLabels'   => self::modeLabels(),
            'canManage'    => $this->can('recurring.manage'),
        ]);
    }

    // ───────────────────────── 建立 ─────────────────────────

    /**
     * 建立表單：選一張既有報價作為週期帳單範本。
     */
    public function create(): void
    {
        $this->requirePermission('recurring.manage');

        $this->render('admin/recurring/create', [
            'title'      => '建立週期排程',
            'quotes'     => $this->candidateQuotes(),
            'unitLabels' => self::unitLabels(),
            'modeLabels' => self::modeLabels(),
        ]);
    }

    /**
     * 儲存新排程（POST + CSRF + recurring.manage）。
     */
    public function store(): void
    {
        $this->guardCsrf();
        $this->requirePermission('recurring.manage');

        $quoteId = (int) $this->request->input('quote_id', 0);
        if ($quoteId <= 0) {
            $this->backWithError('請選擇來源報價單。');
        }

        $params = [
            'interval_unit'          => (string) $this->request->input('interval_unit', 'month'),
            'interval_value'         => (int) $this->request->input('interval_value', 1),
            'first_run_at'           => (string) $this->request->input('first_run_at', ''),
            'advance_generate_days'  => $this->nullableInput('advance_generate_days'),
            'auto_charge_after_days' => $this->nullableInput('auto_charge_after_days'),
            'payment_mode'           => (string) $this->request->input('payment_mode', 'manual_atm'),
            'actor_id'               => $this->currentUserId(),
        ];

        try {
            $id = $this->service->createFromQuote($quoteId, $params);
            $this->redirectWith('/admin/recurring/' . $id, 'success', '已建立週期排程。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    // ───────────────────────── 詳情 / 編輯 ─────────────────────────

    public function show(): void
    {
        $id = (int) $this->request->param('id');
        $schedule = $this->repository->findById($id);
        if ($schedule === null) {
            $this->redirectWith('/admin/recurring', 'error', '找不到週期排程。');
        }

        $this->render('admin/recurring/show', [
            'title'      => '週期排程 #' . $id,
            'schedule'   => $schedule,
            'generated'  => $this->generatedQuotes($id),
            'unitLabels' => self::unitLabels(),
            'modeLabels' => self::modeLabels(),
            'canManage'  => $this->can('recurring.manage'),
        ]);
    }

    public function edit(): void
    {
        $this->requirePermission('recurring.manage');

        $id = (int) $this->request->param('id');
        $schedule = $this->repository->findById($id);
        if ($schedule === null) {
            $this->redirectWith('/admin/recurring', 'error', '找不到週期排程。');
        }

        $this->render('admin/recurring/edit', [
            'title'      => '編輯週期排程 #' . $id,
            'schedule'   => $schedule,
            'unitLabels' => self::unitLabels(),
            'modeLabels' => self::modeLabels(),
        ]);
    }

    /**
     * 更新排程（POST + CSRF + recurring.manage）。允許改 interval / next_run / advance / 模式 / 啟停。
     */
    public function update(): void
    {
        $this->guardCsrf();
        $this->requirePermission('recurring.manage');

        $id = (int) $this->request->param('id');
        $schedule = $this->repository->findById($id);
        if ($schedule === null) {
            $this->redirectWith('/admin/recurring', 'error', '找不到週期排程。');
        }

        $unit = (string) $this->request->input('interval_unit', $schedule['interval_unit']);
        if (!in_array($unit, RecurringService::INTERVAL_UNITS, true)) {
            $unit = (string) $schedule['interval_unit'];
        }
        $mode = (string) $this->request->input('payment_mode', $schedule['payment_mode']);
        if (!in_array($mode, RecurringService::PAYMENT_MODES, true)) {
            $mode = (string) $schedule['payment_mode'];
        }

        $update = [
            'interval_unit'          => $unit,
            'interval_value'         => max(1, (int) $this->request->input('interval_value', $schedule['interval_value'])),
            'payment_mode'           => $mode,
            'advance_generate_days'  => $this->nullableInput('advance_generate_days'),
            'auto_charge_after_days' => $this->nullableInput('auto_charge_after_days'),
            'is_active'              => $this->request->input('is_active', '0') === '1' ? 1 : 0,
        ];

        $nextRun = trim((string) $this->request->input('next_run_at', ''));
        if ($nextRun !== '') {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $nextRun);
            if ($dt !== false && $dt->format('Y-m-d') === $nextRun) {
                $update['next_run_at'] = $nextRun;

                // 🔴 只有日期**真的改變**才重設錨點。
                //
                // 編輯表單一律預填目前的 next_run_at（views/admin/recurring/edit.php），
                // 所以管理員就算只改「提前產生天數」，也會把目前值原樣回送。
                // 若無條件重設，1/31 的排程在推進成 2/28 之後，任何一次無關的編輯
                // 都會把錨點改寫成 28 —— F07 漂移就這樣被靜默且永久地復活。
                if (RecurringService::isBillingDateChanged($nextRun, $schedule['next_run_at'] ?? null)) {
                    $update['billing_anchor_day'] = RecurringService::anchorDayFromDate($nextRun);
                }
            }
        }

        $this->repository->update($id, $update);
        $this->auditLog->log(
            $this->currentUserId(),
            'recurring_schedule_updated',
            'recurring',
            $id,
            ['is_active' => $update['is_active'], 'interval' => "{$update['interval_value']} {$unit}"],
            $this->request->ip()
        );

        $this->redirectWith('/admin/recurring/' . $id, 'success', '已更新週期排程。');
    }

    /**
     * 立即產生一期帳單（POST + CSRF + recurring.manage）。
     * 走 RecurringService::generateOne（冪等：本期已產生則不重複）。
     */
    public function generateNow(): void
    {
        $this->guardCsrf();
        $this->requirePermission('recurring.manage');

        $id = (int) $this->request->param('id');
        $schedule = $this->repository->findById($id);
        if ($schedule === null) {
            $this->redirectWith('/admin/recurring', 'error', '找不到週期排程。');
        }

        try {
            $result = $this->service->generateOne($id, date('Y-m-d'), $this->currentUserId());
            if ($result === 'generated') {
                $this->redirectWith('/admin/recurring/' . $id, 'success', '已產生本期帳單與待付款。');
            } else {
                $this->redirectWith('/admin/recurring/' . $id, 'info', '本期帳單已存在（冪等略過），未重複產生。');
            }
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 刪除排程（POST + CSRF + recurring.manage）。已產生報價保留（FK SET NULL）。
     */
    public function destroy(): void
    {
        $this->guardCsrf();
        $this->requirePermission('recurring.manage');

        $id = (int) $this->request->param('id');
        $schedule = $this->repository->findById($id);
        if ($schedule === null) {
            $this->redirectWith('/admin/recurring', 'error', '找不到週期排程。');
        }

        $this->repository->delete($id);
        $this->auditLog->log(
            $this->currentUserId(),
            'recurring_schedule_deleted',
            'recurring',
            $id,
            ['quote_id' => $schedule['quote_id'] ?? null],
            $this->request->ip()
        );

        $this->redirectWith('/admin/recurring', 'success', '已刪除週期排程（已產生的帳單保留）。');
    }

    // ───────────────────────── 內部輔助 ─────────────────────────

    private function guardCsrf(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }
    }

    private function requirePermission(string $code): void
    {
        if (!$this->roleService->hasPermission($this->currentUserId(), $code)) {
            $this->redirectWith('/admin/recurring', 'error', '您沒有執行此操作的權限。');
        }
    }

    private function can(string $code): bool
    {
        return $this->roleService->hasPermission($this->currentUserId(), $code);
    }

    private function currentUserId(): int
    {
        $user = Session::get('user');
        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }

    /**
     * 表單可空整數輸入：空字串 → null（用全域設定）；否則非負整數。
     */
    private function nullableInput(string $key): ?int
    {
        $raw = $this->request->input($key, '');
        if ($raw === '' || $raw === null) {
            return null;
        }
        return max(0, (int) $raw);
    }

    /**
     * 客戶下拉選項（篩選用）。
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
     * 可作為週期範本的報價（尚未是任何排程來源者；排除草稿/作廢）。
     *
     * @return array<int, array<string, mixed>>
     */
    private function candidateQuotes(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT q.id, q.quote_number, q.title, q.total, q.currency,
                    c.display_name AS customer_name
             FROM {prefix}quotes q
             LEFT JOIN {prefix}customers c ON q.customer_id = c.id
             WHERE q.status NOT IN ('void')
               AND NOT EXISTS (
                   SELECT 1 FROM {prefix}recurring_schedules s WHERE s.quote_id = q.id
               )
             ORDER BY q.created_at DESC, q.id DESC
             LIMIT 200"
        );
    }

    /**
     * 此排程已產生的週期報價（詳情頁顯示）。
     *
     * @return array<int, array<string, mixed>>
     */
    private function generatedQuotes(int $scheduleId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT q.id, q.quote_number, q.title, q.total, q.currency, q.status,
                    q.payment_status, q.created_at
             FROM {prefix}quotes q
             WHERE q.recurring_schedule_id = :sid
             ORDER BY q.created_at DESC, q.id DESC",
            ['sid' => $scheduleId]
        );
    }

    /**
     * 週期單位 → 中文。
     *
     * @return array<string, string>
     */
    public static function unitLabels(): array
    {
        return ['day' => '日', 'month' => '月', 'year' => '年'];
    }

    /**
     * 付款模式 → 中文。
     *
     * @return array<string, string>
     */
    public static function modeLabels(): array
    {
        return ['auto_card' => '綁卡自動扣款', 'manual_atm' => '人工/ATM 收款'];
    }
}
