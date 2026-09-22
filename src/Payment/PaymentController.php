<?php

declare(strict_types=1);

namespace YangSheep\CRM\Payment;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\AuditLog\AuditLogService;
use YangSheep\CRM\Role\RoleService;

/**
 * 付款記錄後台控制器（對應架構設計 §7.9）。
 *
 * 架構與 QuoteController / CustomerController 一致：CSRF + 細粒度權限 + AuditLog
 * + redirectWith/backWithError。查詢全程 prepared（Repository）；輸出於 view 以 e() 轉義。
 *
 * 權限：群組進入需 payment.view（routes 設定）；手動標記已付款於本層再檢查 payment.manage。
 */
class PaymentController extends Controller
{
    private PaymentService $paymentService;
    private AuditLogService $auditLogService;
    private RoleService $roleService;

    public function __construct(
        Request $request,
        Response $response,
        ?PaymentService $paymentService = null,
        ?RoleService $roleService = null
    ) {
        parent::__construct($request, $response);
        $this->paymentService  = $paymentService ?? new PaymentService();
        $this->auditLogService = new AuditLogService();
        $this->roleService     = $roleService ?? new RoleService();
    }

    // ───────────────────────── 列表 ─────────────────────────

    /**
     * 付款記錄列表（分頁 + 篩選 provider/status/customer/keyword）。
     */
    public function index(): void
    {
        $page    = max(1, (int) $this->request->query('page', '1'));
        $perPage = 20;

        $filters = [
            'provider'    => (string) $this->request->query('provider', ''),
            'status'      => (string) $this->request->query('status', ''),
            'customer_id' => (int) $this->request->query('customer_id', '0'),
            'keyword'     => (string) $this->request->query('keyword', ''),
        ];

        $result = $this->paymentService->findAll($page, $perPage, $filters);

        $this->render('admin/payments/index', [
            'title'         => '付款記錄',
            'payments'      => $result['items'],
            'total'         => $result['total'],
            'page'          => $page,
            'perPage'       => $perPage,
            'totalPages'    => (int) ceil($result['total'] / $perPage),
            'filters'       => $filters,
            'customers'     => $this->customerOptions(),
            'statusLabels'  => self::statusLabels(),
            'providerLabels' => self::providerLabels(),
            'canManage'     => $this->can('payment.manage'),
        ]);
    }

    // ───────────────────────── 詳情 ─────────────────────────

    /**
     * 單筆付款詳情（含原始請求 / 回呼）。
     */
    public function show(): void
    {
        $id      = (int) $this->request->param('id');
        $payment = $this->paymentService->findById($id);

        if ($payment === null) {
            $this->redirectWith('/admin/payments', 'error', '找不到付款紀錄。');
        }

        // 發票區塊：已開的顯示連結，沒開的（且有權限）顯示開立按鈕。
        // 這一頁是操作者看到「這筆錢收到了」的地方，發票的下一步就該在這裡。
        $invoiceSettings = new \YangSheep\CRM\EInvoice\InvoiceSettings();
        $invoice         = (new \YangSheep\CRM\EInvoice\InvoiceRepository())
            ->findLatestByPayment((int) $payment['id']);

        $canViewInvoice = $this->can('invoice.view');
        $invoiceSummary = null;
        $invoiceProvenance = '';
        if ($canViewInvoice) {
            if ($invoice !== null) {
                $snapshot = \YangSheep\CRM\EInvoice\InvoiceDisplay::decode((string) ($invoice['payload_snapshot'] ?? ''));
                $invoiceSummary = \YangSheep\CRM\EInvoice\InvoiceDisplay::summary($snapshot);
                $invoiceProvenance = $snapshot !== []
                    ? '最新發票的開立請求快照；不隨客戶主檔修改，是否成功開立請見發票狀態。'
                    : '已有發票紀錄，但沒有可讀取的快照；不以目前資料代替歷史內容。';
            } else {
                try {
                    $intent = (new \YangSheep\CRM\EInvoice\InvoiceService())->resolveSnapshot($payment);
                    $invoiceSummary = \YangSheep\CRM\EInvoice\InvoiceDisplay::fromIntent($intent);
                    $invoiceProvenance = '尚未開立：依目前客戶發票偏好預覽，送出時仍需通過完整驗證。';
                } catch (\Throwable) {
                    $invoiceSummary = \YangSheep\CRM\EInvoice\InvoiceDisplay::summary([]);
                    $invoiceProvenance = '尚未開立，目前無法取得發票偏好。';
                }
            }
        }

        $this->render('admin/payments/show', [
            'title'           => $payment['payment_no'],
            'payment'         => $payment,
            'statusLabels'    => self::statusLabels(),
            'providerLabels'  => self::providerLabels(),
            'canManage'       => $this->can('payment.manage'),
            'canIssueInvoice' => $this->can('invoice.issue'),
            'invoiceEnabled'  => $invoiceSettings->enabled(),
            'invoiceEnv'      => $invoiceSettings->environment(),
            'invoice'         => $invoice,
            'canViewInvoice'  => $canViewInvoice,
            'invoiceSummary'  => $invoiceSummary,
            'invoiceProvenance' => $invoiceProvenance,
        ]);
    }

    // ───────────────────────── 手動標記已付款 ─────────────────────────

    /**
     * 後台手動標記已付款（離線收款補登）。需 CSRF + payment.manage。
     */
    public function markPaid(): void
    {
        $this->guardCsrf();
        $this->requirePermission('payment.manage');

        $id      = (int) $this->request->param('id');
        $payment = $this->paymentService->findById($id);
        if ($payment === null) {
            $this->redirectWith('/admin/payments', 'error', '找不到付款紀錄。');
        }

        try {
            $this->paymentService->markPaidManual((string) $payment['payment_no'], $this->currentUserId());
            $this->redirectWith('/admin/payments/' . $id, 'success', '已將付款標記為已付款。');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * 發動退款。
     *
     * 三種結果分別給不同的使用者訊息 —— 特別是「不確定」不能顯示成失敗：
     * 若讓操作者以為沒退成而再按一次，就可能真的退兩次。
     */
    public function refund(): void
    {
        $this->guardCsrf();
        $this->requirePermission('payment.manage');

        // 金額留空 = 全額退款。前端傳來的值只作為上限內的請求，
        // 實際可退金額由 PaymentService 以伺服器端資料重新驗證。
        //
        // 🔴 非空但不是正整數的輸入必須明確拒絕，不能讓它經 (int) 轉型變成 0。
        // 0 在本 API 是「退全額」的意思：把 "abc"、"-1"、"0" 通通轉成 0，
        // 等於使用者打錯字就觸發一次全額退款，而且畫面上完全看不出發生了什麼。
        $id = (int) $this->request->param('id');
        try {
            $amount = RefundAmount::parse((string) $this->request->input('amount', ''));
        } catch (\RuntimeException $e) {
            $this->redirectWith('/admin/payments/' . $id, 'error', $e->getMessage());
        }

        // 只有金額先通過精確範圍檢查，才進入 PaymentService（更不可能提早打 provider）。
        $payment = $this->paymentService->findById($id);
        if ($payment === null) {
            $this->redirectWith('/admin/payments', 'error', '找不到付款紀錄。');
        }

        // F04 冪等：表單 render 時發放的請求識別字。同一張表單重送（F5／上一頁／
        // 連點）會帶同一個 id → 只真正退一次。
        //
        // 🔴 缺少或格式不符一律**拒絕**，不得靜默降級成無保護路徑。
        // 最實際的觸發情境是部署期間：管理員停在舊版 render 的頁面上按送出，
        // 該頁沒有這個欄位 —— 若默默放行，等於在完全沒有重播保護的狀態下發動退款，
        // 而畫面上看不出任何差異。要求重新整理頁面是可接受的代價。
        $requestId = trim((string) $this->request->input('refund_request_id', ''));
        if (preg_match('/^[a-f0-9]{32}$/D', $requestId) !== 1) {
            $this->redirectWith(
                '/admin/payments/' . $id,
                'error',
                '退款請求識別字遺失或無效，未送出。請重新整理頁面後再操作（避免重複退款）。'
            );
        }

        $result = $this->paymentService->refund(
            (string) $payment['payment_no'],
            $this->currentUserId(),
            $amount,
            $requestId
        );

        if (!empty($result['ok'])) {
            $this->redirectWith('/admin/payments/' . $id, 'success', (string) $result['message']);
        }

        // indeterminate 與 rejected_terminal 都導回明細頁，讓操作者看得到當前狀態，
        // 而不是停在一個只有錯誤訊息、看不到上下文的畫面。
        $this->redirectWith('/admin/payments/' . $id, 'error', (string) $result['message']);
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
            $this->redirectWith('/admin/payments', 'error', '您沒有執行此操作的權限。');
        }
    }

    private function can(string $code): bool
    {
        return $this->roleService->hasPermission($this->currentUserId(), $code);
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
            'pending'            => '待付款',
            'paid'               => '已付款',
            'failed'             => '失敗',
            'cancelled'          => '已取消',
            'partially_refunded' => '部分退款',
            'refunded'           => '已退款',
        ];
    }

    /**
     * 金流商代碼 → 中文標籤。
     *
     * @return array<string, string>
     */
    public static function providerLabels(): array
    {
        return [
            'sandbox'  => '沙盒測試',
            'payuni'   => 'PayUni 統一金',
            'shopline' => 'SHOPLINE Payments',
        ];
    }
}
