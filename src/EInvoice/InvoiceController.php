<?php

declare(strict_types=1);

namespace YangSheep\CRM\EInvoice;

use YangSheep\CRM\AuditLog\AuditLogService;
use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Role\RoleService;

/**
 * 電子發票後台控制器。
 *
 * 【為什麼這一頁非有不可】
 * 發票開立的失敗是常態，不是例外 —— 憑證過期、字軌用罄、統編不存在、載具號碼錯、
 * PayNow 端維護。整條自動化鏈（付款成功 → 自動開立 → 失敗 → 退避重試）在沒有
 * 後台的情況下有一個致命缺口：**失敗之後沒有人工介入的入口**。
 *
 * 沒有這一頁的實際後果：
 *   - 重試上限用完的發票靜靜躺在資料表裡，沒有任何畫面看得到
 *   - 客戶打電話來問發票，客服完全查不到狀態
 *   - 要作廢重開只能下 SQL
 *   - migration 050 已經建好四個 invoice.* 權限，但**沒有任何程式讀取它們**
 *
 * 權限分三級（對應 migration 050 的設計）：
 *   invoice.view    檢視（含 API log）—— 一般業務
 *   invoice.issue   手動開立 / 重試 —— 會產生真實發票，限管理層級
 *   invoice.cancel  作廢 / 重開 —— 影響法定憑證，風險最高，另需 step-up 再認證
 *
 * ⚠️ 本控制器的每一個 POST 都會產生或改變**國稅局留存的法定憑證**，
 * 因此一律 CSRF + 權限 + 稽核紀錄，且前端有二次確認。
 */
class InvoiceController extends Controller
{
    private InvoiceService $invoiceService;
    private InvoiceRepository $invoices;
    private InvoiceApiLogRepository $apiLogs;
    private InvoiceSettings $invoiceSettings;
    private AuditLogService $auditLogService;
    private RoleService $roleService;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->invoiceService  = new InvoiceService();
        $this->invoices        = new InvoiceRepository();
        $this->apiLogs         = new InvoiceApiLogRepository();
        $this->invoiceSettings = new InvoiceSettings();
        $this->auditLogService = new AuditLogService();
        $this->roleService     = new RoleService();
    }

    // ───────────────────────── 列表 ─────────────────────────

    public function index(): void
    {
        $page    = max(1, (int) $this->request->query('page', '1'));
        $perPage = 20;

        $filters = [
            'status'    => (string) $this->request->query('status', ''),
            'keyword'   => (string) $this->request->query('keyword', ''),
            'date_from' => (string) $this->request->query('date_from', ''),
            'date_to'   => (string) $this->request->query('date_to', ''),
        ];

        // 「需人工處理」現在是一個真正的 status（abandoned）。
        // 它先前只是「failed 且沒排重試」這種靠查詢條件表達的概念 ——
        // 那守不住 claimForIssue() 的 CAS，金流商重送通知就能把它撿回來重送。
        $needsAttention = (string) $this->request->query('attention', '') === '1';

        $result = $needsAttention
            ? $this->invoices->findNeedsAttention($page, $perPage)
            : $this->invoices->findAll($page, $perPage, $filters);

        $this->render('admin/invoices/index', [
            'title'          => '電子發票',
            'invoices'       => $result['items'],
            'total'          => $result['total'],
            'page'           => $page,
            'perPage'        => $perPage,
            'totalPages'     => (int) max(1, ceil($result['total'] / $perPage)),
            'filters'        => $filters,
            'needsAttention' => $needsAttention,
            'counts'         => $this->invoices->statusCounts(),
            'attentionCount' => $this->invoices->needsAttentionCount(),
            'statusLabels'   => self::statusLabels(),
            // canIssue / canCancel / environment 曾經傳進來但 view 從未使用
            //（只出現在 @var 註解裡）—— 每次載入都白跑兩次權限查詢，
            // 而且註解會讓人以為那些值真的有在用。
            'moduleEnabled'  => $this->invoiceSettings->enabled(),
            'isConfigured'   => $this->invoiceSettings->isConfigured(),
            'maxRetries'     => InvoiceRepository::MAX_RETRIES,
            'titleBadge'     => $this->environmentBadge(),
            'breadcrumb'     => [['label' => '電子發票']],
        ]);
    }

    // ───────────────────────── 詳情 ─────────────────────────

    public function show(): void
    {
        $id      = (int) $this->request->param('id');
        $invoice = $this->invoices->findById($id);

        if ($invoice === null) {
            $this->redirectWith('/admin/invoices', 'error', '找不到發票紀錄。');
        }

        // 這張發票的 API 往來全紀錄 —— 診斷 422 的唯一依據（PayNow 會逐欄列出錯誤）。
        $logs = $this->apiLogs->findAll(1, 50, ['invoice_id' => $id]);

        $this->render('admin/invoices/show', [
            'title'        => $invoice['invoice_number'] ?: ('發票 #' . $id),
            'invoice'      => $invoice,
            'snapshot'     => InvoiceDisplay::decode((string) ($invoice['payload_snapshot'] ?? '')),
            'logs'         => $logs['items'],
            'statusLabels' => self::statusLabels(),
            'isExhausted'  => InvoiceRepository::isExhausted($invoice),
            'retryExhausted' => InvoiceRepository::isRetryExhausted($invoice),
            'maxRetries'   => InvoiceRepository::MAX_RETRIES,
            'canIssue'     => $this->can('invoice.issue'),
            'canCancel'    => $this->can('invoice.cancel'),
            'titleBadge'   => $this->environmentBadge(),
            'breadcrumb'   => [
                ['label' => '電子發票', 'url' => '/admin/invoices'],
                ['label' => $invoice['invoice_number'] ?: ('#' . $id)],
            ],
        ]);
    }

    // ───────────────────── 官方發票列印頁 ─────────────────────

    public function printUrl(): void
    {
        $id = (int) $this->request->param('id');
        if (!\YangSheep\CRM\Core\Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF Token 驗證失敗。');
        }

        $result = $this->invoiceService->getOfficialInvoiceUrl($id);

        if (($result['success'] ?? false) && !empty($result['url'])) {
            $this->redirect($result['url']);
        } else {
            $msg = $result['message'] ?? '無法取得官方發票頁。';
            $this->redirectWith("/admin/invoices/{$id}", 'error', $msg);
        }
    }

    // ───────────────────────── API 紀錄 ─────────────────────────

    /**
     * 全站 API 往來紀錄。
     *
     * 【為什麼要有全站視角】單張發票的 log 在詳情頁就看得到。這一頁是給
     * 「整批都開不出來」的情境用的 —— 憑證過期、字軌用罄、對方系統異常時，
     * 要看的是「最近所有呼叫是不是都同一個錯」，而不是一張一張點進去。
     */
    public function logs(): void
    {
        $page    = max(1, (int) $this->request->query('page', '1'));
        $perPage = 30;

        $filters = [
            'operation' => (string) $this->request->query('operation', ''),
            'success'   => (string) $this->request->query('success', ''),
            'date_from' => (string) $this->request->query('date_from', ''),
            'date_to'   => (string) $this->request->query('date_to', ''),
        ];

        $result = $this->apiLogs->findAll($page, $perPage, $filters);

        $this->render('admin/invoices/logs', [
            'title'      => '發票 API 紀錄',
            'logs'       => $result['items'],
            'total'      => $result['total'],
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => (int) max(1, ceil($result['total'] / $perPage)),
            'filters'    => $filters,
            'titleBadge' => $this->environmentBadge(),
            'breadcrumb' => [
                ['label' => '電子發票', 'url' => '/admin/invoices'],
                ['label' => 'API 紀錄'],
            ],
        ]);
    }

    // ───────────────────────── 開立 / 重試 ─────────────────────────

    /**
     * 手動開立或重試（需 invoice.issue）。
     *
     * 這是「重試上限用完之後」唯一的出路：管理員修正發票資料後按這個按鈕。
     * 重試計數會由本次結果重新決定（成功則歸零，失敗則再累加一次）。
     */
    public function issue(): void
    {
        $this->guardCsrf();

        $id      = (int) $this->request->param('id');
        $invoice = $this->invoices->findById($id);
        if ($invoice === null) {
            $this->redirectWith('/admin/invoices', 'error', '找不到發票紀錄。');
        }

        $paymentId = (int) ($invoice['payment_id'] ?? 0);
        if ($paymentId <= 0) {
            $this->redirectWith('/admin/invoices/' . $id, 'error', '這筆發票沒有對應的付款紀錄，無法開立。');
        }

        $result = $this->invoiceService->issueForPayment($paymentId, 'manual', $this->currentUserId());

        $this->auditLogService->log(
            $this->currentUserId(),
            'invoice_issue_manual',
            'invoice',
            $id,
            ['payment_id' => $paymentId, 'success' => (bool) ($result['success'] ?? false), 'message' => (string) ($result['message'] ?? '')],
            $this->request->ip()
        );

        $this->redirectWith(
            '/admin/invoices/' . $id,
            ($result['success'] ?? false) ? 'success' : 'error',
            (string) ($result['message'] ?? '')
        );
    }

    /**
     * 從「付款紀錄」直接開立發票（需 invoice.issue）。
     *
     * 🔴 【為什麼要有這個入口】原本要開一張發票只有兩條路：
     *   1. 設定「付款後自動開立」，由金流回呼觸發
     *   2. 對**已存在**的發票列按重試
     *
     * 也就是說「這筆付款已經入帳了，幫我開張發票」在後台完全做不到 ——
     * 除非事前就把自動開立打開。實測時就卡在這裡：一筆已付款的訂單、
     * 發票模組已啟用、憑證也設好了，畫面上卻找不到任何可以按的地方。
     *
     * 動作本身沒有新邏輯：呼叫的是與自動開立完全相同的
     * InvoiceService::issueForPayment()，該方法已經處理「未入帳不開」、
     * 「零元不開」、「非台幣不開」、跨 process 互斥與防重複開立。
     * 這裡只是把那條路徑接上一個人可以按的按鈕。
     */
    public function issueForPayment(): void
    {
        $this->guardCsrf();

        $paymentId = (int) $this->request->param('id');
        if ($paymentId <= 0) {
            $this->redirectWith('/admin/payments', 'error', '找不到付款紀錄。');
        }

        $result = $this->invoiceService->issueForPayment($paymentId, 'manual', $this->currentUserId());

        // 不論成敗都會留下發票列（憑證未設定時也會建待重試的列），
        // 所以導向那一列比導回付款頁更有用 —— 錯誤原因與 API 紀錄都在那裡。
        $invoice = $this->invoices->findLatestByPayment($paymentId);
        $target  = $invoice !== null
            ? '/admin/invoices/' . (int) $invoice['id']
            : '/admin/payments/' . $paymentId;

        $this->auditLogService->log(
            $this->currentUserId(),
            'invoice_issue_from_payment',
            'payment',
            $paymentId,
            [
                'success'    => (bool) ($result['success'] ?? false),
                'message'    => (string) ($result['message'] ?? ''),
                'invoice_id' => $invoice !== null ? (int) $invoice['id'] : null,
            ],
            $this->request->ip()
        );

        $this->redirectWith(
            $target,
            ($result['success'] ?? false) ? 'success' : 'error',
            (string) ($result['message'] ?? '')
        );
    }

    // ───────────────────────── 作廢 ─────────────────────────

    /**
     * 作廢（需 invoice.cancel + step-up）。
     *
     * ⚠️ 作廢是**不可逆**的：國稅局會留下作廢紀錄，且同一組號碼不能再用。
     */
    public function cancel(): void
    {
        $this->guardCsrf();

        $id      = (int) $this->request->param('id');
        $invoice = $this->invoices->findById($id);
        if ($invoice === null) {
            $this->redirectWith('/admin/invoices', 'error', '找不到發票紀錄。');
        }

        $result = $this->invoiceService->cancelInvoice($id, $this->currentUserId());

        $this->auditLogService->log(
            $this->currentUserId(),
            'invoice_cancelled',
            'invoice',
            $id,
            [
                'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
                'success'        => (bool) ($result['success'] ?? false),
                'message'        => (string) ($result['message'] ?? ''),
            ],
            $this->request->ip()
        );

        $this->redirectWith(
            '/admin/invoices/' . $id,
            ($result['success'] ?? false) ? 'success' : 'error',
            (string) ($result['message'] ?? '')
        );
    }

    /**
     * 作廢後重開（需 invoice.cancel + step-up）。
     *
     * 用於「開錯了」：抬頭錯、統編錯、載具錯。重開會用加上 -R 尾碼的訂單編號，
     * 並排除舊號碼避免 reconcile 誤判為「已開過」。
     */
    public function reissue(): void
    {
        $this->guardCsrf();

        $id      = (int) $this->request->param('id');
        $invoice = $this->invoices->findById($id);
        if ($invoice === null) {
            $this->redirectWith('/admin/invoices', 'error', '找不到發票紀錄。');
        }

        $paymentId = (int) ($invoice['payment_id'] ?? 0);
        if ($paymentId <= 0) {
            $this->redirectWith('/admin/invoices/' . $id, 'error', '這筆發票沒有對應的付款紀錄，無法重開。');
        }

        $result = $this->invoiceService->reissueForPayment($paymentId, $this->currentUserId());

        $this->auditLogService->log(
            $this->currentUserId(),
            'invoice_reissued',
            'invoice',
            $id,
            [
                'payment_id'         => $paymentId,
                'old_invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
                'success'            => (bool) ($result['success'] ?? false),
                'message'            => (string) ($result['message'] ?? ''),
            ],
            $this->request->ip()
        );

        $target = (int) ($result['invoice_id'] ?? 0);

        $this->redirectWith(
            '/admin/invoices/' . ($target > 0 ? $target : $id),
            ($result['success'] ?? false) ? 'success' : 'error',
            (string) ($result['message'] ?? '')
        );
    }

    // ───────────────────────── 內部輔助 ─────────────────────────

    /**
     * 標題旁的環境標記。
     *
     * 正式環境不顯示 —— 「一切正常」不需要標記，只有「現在不是正常狀態」才需要。
     * 每多一個常駐標記，其他標記就少一分被看見的機會。
     *
     * @return array{label: string, class: string, title: string}|null
     */
    private function environmentBadge(): ?array
    {
        if ($this->invoiceSettings->environment() === 'production') {
            return null;
        }

        return [
            'label' => '測試環境',
            'class' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
            'title' => '開出來的發票不具法律效力，也不會上傳國稅局。',
        ];
    }

    private function guardCsrf(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
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
     * @return array<string,mixed>
     */
    private function decodeJson(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 狀態代碼 → 中文標籤。
     *
     * @return array<string,string>
     */
    public static function statusLabels(): array
    {
        return [
            'pending'   => '待開立',
            'scheduled' => '已排程',
            'issuing'   => '開立中',
            'issued'    => '已開立',
            'failed'    => '開立失敗',
            'abandoned' => '需人工處理',
            'cancelled' => '已作廢',
        ];
    }
}
