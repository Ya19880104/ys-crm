<?php

declare(strict_types=1);

namespace YangSheep\CRM\Payment;

use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Quote\QuoteRepository;
use YangSheep\CRM\Quote\QuoteService;
use YangSheep\CRM\AuditLog\AuditLogService;
use YangSheep\CRM\EInvoice\InvoiceSettings;

/**
 * 付款領域服務層（對應架構設計 §5.6、§7.9）。系統核心，與 QuoteService 同等重要。
 *
 * Zero Trust 落實：
 *   - 金額一律伺服器端從 quote.total 重算（initiateForQuote），絕不信任前端傳入金額。
 *   - 冪等：idempotency_key 唯一 + insertIdempotent 先查 → 同 key 不重複建單。
 *   - 防重放/重複入帳：confirmPaid 於交易內 FOR UPDATE 鎖 payment 列 + 已 paid 短路 +
 *     provider_txn_id 唯一索引 + 入帳前比對 verified.amount 與 payment.amount 一致。
 *   - 狀態機：pending → paid / failed / cancelled / refunded；pending→paid 為主路徑。
 *   - 全狀態變更寫 audit_logs（actor 0 = 金流回呼/系統）。
 *
 * 與報價同步：入帳成功時 quote.payment_status='paid' 並呼叫 QuoteService 狀態機把報價轉 paid
 * （不繞過狀態機；若報價當下狀態不可轉 paid 則僅更新 payment_status 並記錄，不拋錯阻斷入帳）。
 */
class PaymentService
{
    private PaymentRepository $payments;
    private QuoteRepository $quotes;
    private QuoteService $quoteService;
    private PaymentProviderRegistry $registry;
    private AuditLogService $auditLog;
    private Database $db;
    private InvoiceSettings $invoiceSettings;
    private PaymentRefundRequestRepository $refundRequests;

    /**
     * 合法狀態值 —— **全站唯一來源**。
     *
     * 篩選白名單（PaymentRepository::buildWhere）與中文標籤
     * （PaymentController::statusLabels）都由此推導。
     *
     * 【為何要收成一份】migration 052 加了 partially_refunded，但那時只改了狀態機，
     * 這個常數、篩選白名單、標籤表三處都沒跟上：部分退款後那筆付款在列表篩選中
     * 選不到、狀態欄顯示原始代碼。同一份清單散在四個檔案裡，漏掉任何一個都不會報錯。
     */
    public const STATUSES = [
        'pending',
        'paid',
        'failed',
        'cancelled',
        'partially_refunded',
        'refunded',
    ];

    public function __construct(
        ?PaymentProviderRegistry $registry = null,
        ?InvoiceSettings $invoiceSettings = null
    )
    {
        $this->payments     = new PaymentRepository();
        $this->quotes       = new QuoteRepository();
        $this->quoteService = new QuoteService();
        $this->registry     = $registry ?? new PaymentProviderRegistry();
        $this->auditLog     = new AuditLogService();
        $this->db           = Database::getInstance();
        $this->invoiceSettings = $invoiceSettings ?? new InvoiceSettings();
        $this->refundRequests  = new PaymentRefundRequestRepository();
    }

    // ───────────────────────── 查詢 ─────────────────────────

    /**
     * 分頁查詢付款列表。
     *
     * @param array{provider?: string, status?: string, customer_id?: int, keyword?: string} $filters
     * @return array{items: array, total: int}
     */
    public function findAll(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        return $this->payments->findAll($page, $perPage, $filters);
    }

    public function findById(int $id): ?array
    {
        return $this->payments->findById($id);
    }

    public function findByNo(string $paymentNo): ?array
    {
        return $this->payments->findByNo($paymentNo);
    }

    public function findLatestByQuote(int $quoteId): ?array
    {
        return $this->payments->findLatestByQuote($quoteId);
    }

    // ───────────────────────── 發動付款 ─────────────────────────

    /**
     * 由報價發動付款。
     *
     * 流程：
     *   1) 伺服器端從 quote.total 重算金額（絕不信任前端）。
     *   2) 若報價已付款（payment_status=paid 或 status=paid）→ 拒絕。
     *   3) 冪等建立 pending payment（同 idempotency_key 回既有列，不重複建單）。
     *      - 若既有付款已 paid → 拒絕重複付款。
     *   4) 呼叫設定選用之 provider 的 createCheckout，回傳 checkout（redirect/form）。
     *   5) 將送往 provider 的請求快照寫入 raw_request。
     *
     * @param array<string, mixed> $quote           報價列（須含 id / total / customer_id / payment_enabled / payment_status / status）
     * @param string               $idempotencyKey  冪等鍵（由 Controller 依報價+session 等推導）
     * @param string               $returnUrl       付款後瀏覽器導回網址
     * @param string               $callbackUrl     server-to-server webhook 網址
     * @param callable|null        $accessGuard     於建立／沿用 pending 付款的交易內最先執行；
     *                                              公開報價頁以此鎖列重驗匿名分享授權（拋例外即中止、不建單）。
     *                                              客戶 Portal 已由登入身分授權，不傳。
     * @return array{payment: array<string, mixed>, checkout: array<string, mixed>}
     * @throws \RuntimeException
     */
    public function initiateForQuote(
        array $quote,
        string $idempotencyKey,
        string $returnUrl,
        string $callbackUrl,
        string $method = 'credit',
        ?callable $accessGuard = null
    ): array {
        // 付款方式（信用卡 / 虛擬 ATM）；非法值退回 credit。實際採用與否依 provider 能力，
        // 沙盒僅記錄、PayUni/Shopline 依此決定 UPP 參數。
        $method = in_array($method, ['credit', 'atm'], true) ? $method : 'credit';

        $quoteId = (int) ($quote['id'] ?? 0);
        if ($quoteId <= 0) {
            throw new \RuntimeException('報價單無效。');
        }
        if ((int) ($quote['payment_enabled'] ?? 0) !== 1) {
            throw new \RuntimeException('此報價單未啟用線上付款。');
        }
        // 已付款（報價層）→ 拒絕。
        if (($quote['payment_status'] ?? '') === 'paid' || ($quote['status'] ?? '') === 'paid') {
            throw new \RuntimeException('此報價單已完成付款。');
        }

        // 伺服器端金額（從報價重算來源；total 已於建立報價時由 QuoteService 重算寫入）。
        $amount = $this->serverAmountForQuote($quoteId);
        if ($amount <= 0) {
            throw new \RuntimeException('應付金額無效，無法發動付款。');
        }

        $provider = $this->registry->resolve();

        // 冪等建立 pending payment。
        $payment = $this->db->transaction(function () use ($quote, $quoteId, $amount, $idempotencyKey, $provider, $method, $accessGuard): array {
            if ($accessGuard !== null) {
                $accessGuard();
            }

            // 交易內再查冪等鍵，避免併發雙開。
            $existing = $this->payments->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                // 沿用既有 pending；若使用者改了付款方式，更新既有列的 method
                //（搭配「冪等鍵只綁報價」，確保一張報價恆只有一筆 pending，杜絕重複入帳）。
                if (($existing['status'] ?? '') === 'pending' && (string) ($existing['method'] ?? '') !== $method) {
                    $this->payments->update((int) $existing['id'], ['method' => $method]);
                    $existing['method'] = $method;
                }
                return $existing;
            }

            $paymentNo = $this->generatePaymentNo();

            return $this->payments->insertIdempotent([
                'payment_no'      => $paymentNo,
                'quote_id'        => $quoteId,
                'customer_id'     => $quote['customer_id'] ?? null,
                'provider'        => $provider->key(),
                'method'          => $method,
                'amount'          => $amount,
                'currency'        => (string) ($quote['currency'] ?? 'TWD'),
                'status'          => 'pending',
                'idempotency_key' => $idempotencyKey,
            ]);
        });

        // 既有付款已入帳 → 不可重複付款。
        if (($payment['status'] ?? '') === 'paid') {
            throw new \RuntimeException('此付款已完成，請勿重複付款。');
        }
        // 既有付款已取消/退款 → 不可沿用（須以新冪等鍵重新發動）。
        if (in_array($payment['status'] ?? '', ['cancelled', 'refunded'], true)) {
            throw new \RuntimeException('此付款已結束，請重新發起付款。');
        }

        // 建立 checkout（金鑰缺漏時 provider throw '未設定金流金鑰'）。
        $checkout = $provider->createCheckout($payment, $returnUrl, $callbackUrl);

        // 紀錄請求快照（除錯/稽核）。
        $this->payments->update((int) $payment['id'], [
            'raw_request' => json_encode([
                'provider'     => $provider->key(),
                'return_url'   => $returnUrl,
                'callback_url' => $callbackUrl,
                'checkout'     => $checkout,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $this->auditLog->log(
            0,
            'payment_initiated',
            'payment',
            (int) $payment['id'],
            ['payment_no' => $payment['payment_no'], 'quote_id' => $quoteId, 'amount' => $amount, 'provider' => $provider->key()],
            null
        );

        return ['payment' => $payment, 'checkout' => $checkout];
    }

    // ───────────────────────── 入帳 ─────────────────────────

    /**
     * 確認入帳（由 provider 回呼驗章成功後呼叫）。
     *
     * Zero Trust：
     *   - 交易內 FOR UPDATE 鎖定 payment 列。
     *   - 冪等：已 paid 直接 return（重複回呼安全）。
     *   - 金額比對：verified.amount 必須等於 payment.amount，不符則標 failed 並記錄，拒絕入帳。
     *   - 狀態機：僅允許 pending → paid；非 pending（且非已 paid）→ 拒絕。
     *   - 寫 provider_txn_id（唯一索引防重放）、paid_at、raw_callback。
     *   - 同步報價 payment_status='paid' + 呼叫 QuoteService 狀態機轉 paid（不繞過狀態機）。
     *
     * @param string               $paymentNo
     * @param array{txn_id?: string, status?: string, amount?: int, method?: ?string} $verified
     * @param string|null          $rawCallback 原始回呼內容（存證）
     * @throws \RuntimeException
     */
    public function confirmPaid(string $paymentNo, array $verified, ?string $rawCallback = null): void
    {
        // Snapshot the policy before the accounting transaction. When auto issue
        // is enabled, the durable marker is committed with payment.status=paid;
        // cron can therefore recover even if the process dies before an invoice
        // row is created. A settings-table failure keeps the legacy safe default
        // (do not infer legal-document intent).
        $invoiceRequested = false;
        try {
            $invoiceRequested = $this->invoiceSettings->autoIssueEnabled();
        } catch (\Throwable) {
            $invoiceRequested = false;
        }

        // 交易內回傳結果碼，避免「金額不符」用 throw 中斷交易導致 failed 標記與 audit 一起被 rollback。
        // 'paid' | 'already_paid' | 'provider_failed' | 'amount_mismatch'。
        $outcome = $this->db->transaction(function () use (
            $paymentNo,
            $verified,
            $rawCallback,
            $invoiceRequested
        ): string {
            $payment = $this->payments->findByNoForUpdate($paymentNo);
            if ($payment === null) {
                // 找不到付款列：無任何寫入，可安全 rollback 並對外丟錯。
                throw new \RuntimeException('找不到付款紀錄。');
            }

            $paymentId = (int) $payment['id'];

            // 冪等：已 paid → 直接結束（重複回呼安全）。
            if (($payment['status'] ?? '') === 'paid') {
                return 'already_paid';
            }

            // 可入帳的來源狀態：pending（正常路徑）與 failed（遲到的成功回呼）。
            //
            // 【為何 failed 也要能入帳】稽核（2026-08-16）實證的帳務分裂情境：
            // 金流商先送一個非成功事件（SHOPLINE 會把所有非 trade.succeeded 事件都
            // 映射為 failed），付款被標為 failed；使用者稍後真的完成付款，成功回呼抵達時
            // 若只允許 pending→paid，這筆款項就**永遠不會入帳**——錢進了金流商，
            // CRM 卻顯示失敗。failed 只代表「當時那次嘗試沒成功」，不是終態。
            //
            // 仍然嚴格拒絕的是 refunded / cancelled：那是人為決定的終態，
            // 若要重新入帳必須有人明確處理，不能被一個遲到的回呼默默翻轉。
            $currentStatus = (string) ($payment['status'] ?? '');
            if (!in_array($currentStatus, ['pending', 'failed'], true)) {
                throw new \RuntimeException("付款狀態為「{$currentStatus}」，無法入帳。");
            }

            // provider 回報失敗 → 標記 failed（不入帳）。提交後不丟錯（回呼端回 200，避免無謂重送）。
            if (($verified['status'] ?? '') !== 'paid') {
                $this->payments->update($paymentId, [
                    'status'       => 'failed',
                    'raw_callback' => $rawCallback,
                ]);
                $this->auditLog->log(0, 'payment_failed', 'payment', $paymentId,
                    ['payment_no' => $paymentNo, 'reason' => 'provider_reported_failed'], null);
                return 'provider_failed';
            }

            // 金額比對（伺服器端為準）：不符一律標 failed 並記錄，拒絕入帳。
            // 注意：標 failed + audit 必須隨交易提交（存證），故此處「不」丟錯，改回傳結果碼，
            // 由交易外再丟錯通知呼叫端（回 4xx/422）。
            $expected = (int) round((float) $payment['amount']);
            $got      = (int) ($verified['amount'] ?? -1);
            if ($got !== $expected) {
                $this->payments->update($paymentId, [
                    'status'       => 'failed',
                    'raw_callback' => $rawCallback,
                ]);
                $this->auditLog->log(0, 'payment_amount_mismatch', 'payment', $paymentId,
                    ['payment_no' => $paymentNo, 'expected' => $expected, 'got' => $got], null);
                return 'amount_mismatch';
            }

            // 入帳：pending → paid。
            $paidUpdate = [
                'status'          => 'paid',
                'provider_txn_id' => (string) ($verified['txn_id'] ?? ''),
                'method'          => $verified['method'] ?? ($payment['method'] ?? null),
                'paid_at'         => PaymentTimestamp::DatabaseNow,
                'raw_callback'    => $rawCallback,
            ];
            if ($invoiceRequested) {
                $paidUpdate['invoice_requested_at'] = PaymentTimestamp::DatabaseNow;
            }
            $this->payments->update($paymentId, $paidUpdate);

            // 同步報價（若有關聯報價）。
            $quoteId = (int) ($payment['quote_id'] ?? 0);
            if ($quoteId > 0) {
                $this->syncQuotePaid($quoteId);
            }

            $this->auditLog->log(
                0,
                'payment_paid',
                'payment',
                $paymentId,
                ['payment_no' => $paymentNo, 'amount' => $expected, 'txn_id' => $verified['txn_id'] ?? '', 'quote_id' => $quoteId],
                null
            );

            return 'paid';
        });

        // 交易已提交後再依結果碼對外通知（金額不符的 failed 標記與 audit 已安全落地）。
        if ($outcome === 'amount_mismatch') {
            throw new \RuntimeException('付款金額與應付金額不符，已拒絕入帳。');
        }

        // 入帳成功後自動開立電子發票。
        //
        // 【務必留在交易之外】開立發票是外部 API 呼叫，可能很慢或失敗。若放進上面的
        // 交易裡，一次 PayNow 逾時就會把「已經成功的付款入帳」一起 rollback——
        // 錢收了但系統顯示未付款，是比發票晚開嚴重得多的後果。
        //
        // 同理這裡吞掉所有例外：發票失敗只留下 pending/failed 的發票列等 cron 重試，
        // 絕不影響已成立的付款。
        if ($outcome === 'paid') {
            $this->autoIssueInvoice($paymentNo);
        }
    }

    /**
     * 付款成功後自動開立發票（失敗不影響付款）。
     */
    private function autoIssueInvoice(string $paymentNo): void
    {
        try {
            if (!$this->invoiceSettings->autoIssueEnabled()) {
                return;
            }

            $payment = $this->payments->findByNo($paymentNo);
            if ($payment === null) {
                return;
            }

            (new \YangSheep\CRM\EInvoice\InvoiceService($this->invoiceSettings))
                ->issueForPayment((int) $payment['id'], 'auto');
        } catch (\Throwable $e) {
            // 記錄後即止：發票問題不得回頭影響付款狀態。
            try {
                $this->auditLog->log(0, 'invoice_auto_issue_error', 'payment', 0, [
                    'payment_no' => $paymentNo,
                    'error'      => $e->getMessage(),
                ]);
            } catch (\Throwable) {
                // audit 也失敗就放棄，不讓記錄失敗變成付款流程的失敗。
            }
        }
    }

    /**
     * 標記付款失敗（如使用者取消、provider 回失敗的瀏覽器導回等）。
     * 僅 pending → failed；其他狀態不動（冪等）。
     */
    public function markFailed(string $paymentNo, ?string $reason = null): void
    {
        $this->db->transaction(function () use ($paymentNo, $reason): void {
            $payment = $this->payments->findByNoForUpdate($paymentNo);
            if ($payment === null) {
                return;
            }
            if (($payment['status'] ?? '') !== 'pending') {
                return; // 已入帳/已結束 → 不動。
            }
            $this->payments->update((int) $payment['id'], ['status' => 'failed']);
            $this->auditLog->log(0, 'payment_failed', 'payment', (int) $payment['id'],
                ['payment_no' => $paymentNo, 'reason' => $reason ?? 'marked_failed'], null);
        });
    }

    /**
     * 後台手動標記已付款（離線收款 / 對帳後補登）。
     * 走相同入帳路徑但金額以伺服器端 payment.amount 為準（傳入相符 amount）。
     * actor = 該管理員 ID。
     *
     * @throws \RuntimeException
     */
    public function markPaidManual(string $paymentNo, int $adminUserId): void
    {
        $payment = $this->payments->findByNo($paymentNo);
        if ($payment === null) {
            throw new \RuntimeException('找不到付款紀錄。');
        }
        if (($payment['status'] ?? '') === 'paid') {
            return; // 已付款（冪等）。
        }
        if (($payment['status'] ?? '') !== 'pending') {
            throw new \RuntimeException("付款狀態為「{$payment['status']}」，無法手動標記為已付款。");
        }

        $expected = (int) round((float) $payment['amount']);

        $this->db->transaction(function () use ($payment, $paymentNo, $expected, $adminUserId): void {
            // 交易內鎖列再驗一次（避免與回呼競態）。
            $locked = $this->payments->findByNoForUpdate($paymentNo);
            if ($locked === null || ($locked['status'] ?? '') !== 'pending') {
                return;
            }
            $paymentId = (int) $locked['id'];

            $this->payments->update($paymentId, [
                'status'          => 'paid',
                'method'          => $locked['method'] ?? 'manual',
                'provider_txn_id' => 'MANUAL-' . $paymentNo,
                'paid_at'         => PaymentTimestamp::DatabaseNow,
            ]);

            $quoteId = (int) ($locked['quote_id'] ?? 0);
            if ($quoteId > 0) {
                $this->syncQuotePaid($quoteId);
            }

            $this->auditLog->log(
                $adminUserId,
                'payment_marked_paid_manual',
                'payment',
                $paymentId,
                ['payment_no' => $paymentNo, 'amount' => $expected],
                null
            );
        });
    }

    /**
     * 退款（冪等入口）。
     *
     * $requestId＝呼叫端攜帶的持久請求識別字（後台表單 render 時發放）。
     * 有帶時，同一個 id 只會真正送出一次 provider 退款，之後一律回放既有結果。
     * 不帶時維持既有行為（內部呼叫／CLI），但那條路徑沒有重播保護。
     *
     * 🔴 這一層擋的是「回應遺失後再送一次」；claim token 擋的是「同時兩個請求」。
     * 兩者互不取代，見 PaymentRefundRequestRepository 的說明。
     */
    public function refund(string $paymentNo, int $adminUserId, int $amount = 0, ?string $requestId = null): array
    {
        $requestId = trim((string) $requestId);
        if ($requestId === '') {
            return $this->refundInner($paymentNo, $adminUserId, $amount);
        }

        // 先解析付款以取得 id 供帳列使用；找不到就交給 inner 產生一致的錯誤訊息，
        // 不在這裡先寫一列指向不存在付款的請求。
        $payment = $this->payments->findByNo($paymentNo);
        if ($payment === null) {
            return $this->refundInner($paymentNo, $adminUserId, $amount);
        }

        // 🔴 登記不進去就不能送出。退款是不可逆動作，沒有持久的請求身分就沒有重播保護，
        // 寧可拒絕也不要在無保護狀態下打 provider。
        //
        // 最常見的觸發原因是「程式碼已部署但 migration 059 未執行」：原本這裡會讓
        // PDOException 一路冒到 controller 變成 500，admin 只看到系統壞掉、無從診斷。
        // 現在改為明確拒絕並指出成因（實測：provider 呼叫 0 次、付款未被動到）。
        try {
            $existing = $this->refundRequests->begin(
                $requestId,
                (int) $payment['id'],
                max(0, $amount),
                $adminUserId
            );
        } catch (\Throwable $e) {
            $this->refundAuditBestEffort($adminUserId, 'payment_refund_blocked', (int) $payment['id'], [
                'payment_no' => $paymentNo,
                'amount'     => $amount,
                'reason'     => 'refund_request_ledger_unavailable',
                'error'      => $e->getMessage(),
            ]);

            return [
                'ok'      => false,
                'outcome' => 'rejected_terminal',
                'message' => '無法登記退款請求，為避免重複退款已中止，未向金流商送出。'
                    . '請確認資料庫 migration 已執行完成（payment_refund_requests 資料表）後再試。',
            ];
        }

        if ($existing !== null) {
            // request id 綁定 (付款, 金額)。任一項不符都不能回放既有結果 ——
            // 那會讓操作者看到一個與他這次請求無關的「成功」。
            //
            // 🔴 金額也必須比對：只擋 payment_id 的話，「同 id 但改成退 700」會直接
            // 回放「已退款 100」並回報成功，操作者以為退了 700 而實際上一毛都沒動。
            // 那正是 refundInner 註解裡說最危險的形態（顯示已退款、客戶沒收到錢）。
            $mismatch = (int) ($existing['payment_id'] ?? 0) !== (int) $payment['id']
                || (int) ($existing['amount'] ?? -1) !== max(0, $amount);
            if ($mismatch) {
                return [
                    'ok'      => false,
                    'outcome' => 'rejected_terminal',
                    'message' => '此退款請求識別字已用於另一筆付款或另一個金額，未送出。'
                        . '請重新整理頁面確認目前餘額後再操作。',
                ];
            }
            return $this->replayRefundRequest($existing);
        }

        try {
            $result = $this->refundInner($paymentNo, $adminUserId, $amount);
        } catch (\Throwable $e) {
            // 我方沒能取得結果 → 這個 id 之後只能回放，不得自動重送。
            $this->refundRequestCompleteBestEffort(
                $requestId, 'indeterminate', 'indeterminate',
                '退款執行期間發生未預期例外，結果不確定：' . $e->getMessage()
            );
            throw $e;
        }

        $outcome = (string) ($result['outcome'] ?? 'rejected_terminal');
        // rejected_terminal 也標 failed 而非 success：回放時同樣只回既有結果、不重送。
        $status  = match ($outcome) {
            'success'       => 'success',
            'indeterminate' => 'indeterminate',
            default         => 'failed',
        };
        $this->refundRequestCompleteBestEffort(
            $requestId, $status, $outcome, (string) ($result['message'] ?? '')
        );

        return $result;
    }

    /**
     * 回放既有的退款請求結果 —— 任何情況都不重新呼叫 provider。
     */
    private function replayRefundRequest(array $row): array
    {
        $status  = (string) ($row['status'] ?? 'in_flight');
        $message = (string) ($row['message'] ?? '');

        if ($status === 'success') {
            // 🔴 回放必須看得出是回放。原本優先用既有 message，而成功時它從不為空，
            // 於是「未重複送出」那句是死碼 —— 回放與真正的第一次成功逐字相同，
            // 操作者無法分辨自己這一次到底有沒有真的退款。
            return [
                'ok'      => true,
                'outcome' => 'success',
                'message' => '此退款請求先前已完成，未重複送出。'
                    . ($message !== '' ? '（原結果：' . $message . '）' : ''),
            ];
        }

        if ($status === 'in_flight') {
            // 無法區分「還在跑」與「跑到一半死掉」，一律不重送。
            return [
                'ok'      => false,
                'outcome' => 'indeterminate',
                'message' => '這筆退款請求已在處理中且尚無確定結果，未重複送出。'
                    . '請重新整理頁面確認目前餘額；若狀態長時間未更新，請至金流商後台人工對帳。',
            ];
        }

        return [
            'ok'      => false,
            'outcome' => $status === 'indeterminate' ? 'indeterminate' : 'rejected_terminal',
            'message' => $message !== '' ? $message : '此退款請求先前已處理，未重複送出。',
        ];
    }

    /**
     * 結帳失敗不得反轉已發生的財務結果（同 refundAuditBestEffort 的理由）。
     */
    private function refundRequestCompleteBestEffort(
        string $requestId,
        string $status,
        string $outcome,
        string $message
    ): void {
        try {
            $this->refundRequests->complete($requestId, $status, $outcome, $message);
        } catch (\Throwable) {
            // 帳列寫不進去不能讓已成功的退款變成失敗；留在 in_flight，
            // 重播時會得到保守的 indeterminate（不重送），這正是我們要的方向。
        }
    }

    /**
     * 對已入帳的付款發動退款（實際執行；冪等由外層 refund() 負責）。
     *
     * 【本方法最重要的一條規則】只有金流商**明確回報成功**時才把本地標為 refunded。
     *
     * 退款有三種結果，混為一談就會造成帳務分裂：
     *   success            確定退了 → 更新狀態，報價回復未付款
     *   rejected_terminal  確定沒退（金額超限／狀態不符／驗章失敗）→ 維持 paid，顯示原因
     *   indeterminate      不知道退了沒（逾時／5xx）→ **維持 paid** 並標記待人工查核
     *
     * 最危險的實作是「呼叫完就無條件 return true」：系統顯示已退款、
     * 客戶卻沒收到錢，而且沒有任何人會發現。故此處寧可讓狀態落後於現實
     * （顯示仍為已付款、留下 audit 待查），也不搶先寫入無法保證的結果。
     *
     * @param int $amount 退款金額（元）；0 代表全額
     * @return array{ok: bool, outcome: string, message: string}
     */
    private function refundInner(string $paymentNo, int $adminUserId, int $amount = 0): array
    {
        $payment = $this->payments->findByNo($paymentNo);
        if ($payment === null) {
            return ['ok' => false, 'outcome' => 'rejected_terminal', 'message' => '找不到付款紀錄。'];
        }

        $paymentId = (int) $payment['id'];
        $status    = (string) ($payment['status'] ?? '');

        if ($status === 'refunded') {
            // 冪等：已全額退款直接視為成功，避免重複點擊造成第二次退款。
            return ['ok' => true, 'outcome' => 'success', 'message' => '此筆付款已全額退款。'];
        }
        // partially_refunded 仍可繼續退剩餘金額。
        if (!in_array($status, ['paid', 'partially_refunded'], true)) {
            return [
                'ok'      => false,
                'outcome' => 'rejected_terminal',
                'message' => "付款狀態為「{$status}」，只有已入帳的付款可以退款。",
            ];
        }

        $paidAmount     = (int) round((float) ($payment['amount'] ?? 0));
        $alreadyRefunded = (int) round((float) ($payment['refunded_amount'] ?? 0));
        $refundable      = $paidAmount - $alreadyRefunded;

        if ($refundable <= 0) {
            return [
                'ok'      => false,
                'outcome' => 'rejected_terminal',
                'message' => '此筆付款已無可退餘額。',
            ];
        }

        // 【為何負數要單獨擋，不能併進「<= 0 就是全額」】（複審 2026-08-17）
        // 原本寫成 `$amount > 0 ? $amount : $refundable`，任何非正數都會被當成
        // 「退剩餘全部」。也就是說 amount = -1（或 Controller 轉型後變成負數的輸入）
        // 不會被拒絕，而是**直接觸發全額退款**。之後那句 `$requested < 0` 的檢查
        // 也永遠不會成立，因為 $requested 到那時已經被換成 $refundable 了。
        //
        // 「0 代表全額」是刻意保留的 API 語意；負數則一律是呼叫端有問題，必須拒絕。
        if ($amount < 0) {
            return [
                'ok'      => false,
                'outcome' => 'rejected_terminal',
                'message' => '退款金額不可為負數。',
            ];
        }

        // amount = 0 表示「退剩餘全部」。
        $requested = $amount > 0 ? $amount : $refundable;

        if ($requested > $refundable) {
            return [
                'ok'      => false,
                'outcome' => 'rejected_terminal',
                'message' => sprintf(
                    '退款金額不可超過可退餘額（原付款 %d，已退 %d，可退 %d）。',
                    $paidAmount,
                    $alreadyRefunded,
                    $refundable
                ),
            ];
        }

        $providerKey = (string) ($payment['provider'] ?? '');

        // 先確認 provider 為已知值：registry->get() 對未知 key 會 fail-safe 退回 sandbox，
        // 若不先擋，操作者會收到「不支援退款」而非真正的原因（provider 資料異常）。
        if (!$this->registry->has($providerKey)) {
            return [
                'ok'      => false,
                'outcome' => 'rejected_terminal',
                'message' => "付款紀錄的金流商「{$providerKey}」無法識別，請人工確認後處理。",
            ];
        }

        $provider = $this->registry->get($providerKey);
        if (!$provider instanceof RefundableProviderInterface) {
            return [
                'ok'      => false,
                'outcome' => 'rejected_terminal',
                'message' => '此金流商尚不支援線上退款，請於金流商後台操作後手動調整狀態。',
            ];
        }

        // ── 併發保護：送出外部退款前必須先搶到 claim ──
        //
        // 沒有這道，兩個同時抵達的請求會各自通過上面的前置檢查、各自送出退款，
        // 客戶被退兩次而且兩次都「成功」。
        //
        // 🔴 claim 條件必須帶上「我做前置檢查時看到的 status 與 refunded_amount」。
        // 只鎖「有沒有人在跑」擋不住這種交錯：另一筆退款可能已經完整跑完並釋放了鎖，
        // 此時 token 是 NULL、搶得到，但我手上的 $refundable 已經過期 → 超退。
        // 詳見 PaymentRepository::claimForRefund() 的說明。
        $claimToken = bin2hex(random_bytes(16));
        if (!$this->payments->claimForRefund($paymentId, $claimToken, $status, $alreadyRefunded)) {
            return [
                'ok'      => false,
                'outcome' => 'rejected_terminal',
                'message' => '這筆付款的退款狀態已在您讀取後變動（可能另一筆退款正在處理或剛完成）。'
                    . '請重新整理頁面確認目前餘額後再操作，勿重複送出。',
            ];
        }

        try {
            $result = $provider->refund($payment, $requested);
        } catch (\Throwable $e) {
            // 例外＝我方沒能取得結果，對方可能已經處理，故歸類為不確定。
            // ⚠️ 這裡**不釋放 claim**：見下方 indeterminate 分支的說明。
            $this->refundAuditBestEffort($adminUserId, 'payment_refund_indeterminate', $paymentId, [
                'payment_no'  => $paymentNo,
                'amount'      => $requested,
                'claim_token' => $claimToken,
                'error'       => $e->getMessage(),
            ]);

            return [
                'ok'      => false,
                'outcome' => 'indeterminate',
                'message' => '退款結果不確定（' . $e->getMessage()
                    . '）。狀態維持不變，且已鎖定此筆付款避免重複退款；'
                    . '請至金流商後台確認實際結果後再處理。',
            ];
        }

        $outcome = (string) ($result['outcome'] ?? 'rejected_terminal');

        if ($outcome === 'success') {
            $totalRefunded = $alreadyRefunded + $requested;
            $isFull        = $totalRefunded >= $paidAmount;

            // 🔴 落盤必須驗證 claim 仍在自己手上、且餘額仍是搶 claim 時的基準。
            // 用無條件的 `WHERE id` 寫入，會讓遲到的持有者以舊基準覆寫別人剛寫入的
            // 較新金額（見 PaymentRepository::finalizeRefund()）。
            $persisted = false;
            $persistenceStage = 'finalize';
            try {
                $this->db->transaction(function () use (
                    $paymentId,
                    $claimToken,
                    $status,
                    $alreadyRefunded,
                    $totalRefunded,
                    $isFull,
                    &$persisted,
                    &$persistenceStage
                ): void {
                    $persisted = $this->payments->finalizeRefund(
                        $paymentId,
                        $claimToken,
                        $status,
                        $alreadyRefunded,
                        $totalRefunded,
                        $isFull ? 'refunded' : 'partially_refunded',
                        true
                    );

                    if (!$persisted) {
                        // 一筆都沒改到 → 直接結束（交易提交零變更），由外層轉成 indeterminate。
                        return;
                    }

                    // Only a full refund changes the quote's payment status.
                    if ($isFull) {
                        $persistenceStage = 'quote_sync';
                        $quoteId = (int) ($this->payments->findById($paymentId)['quote_id'] ?? 0);
                        if ($quoteId > 0) {
                            $this->quotes->update($quoteId, ['payment_status' => 'refunded']);
                        }
                    }
                    $persistenceStage = 'commit';
                });

                // The claim remains present even if COMMIT succeeds but its
                // response is lost. Only a confirmed return permits release.
                if ($persisted) {
                    $persistenceStage = 'claim_release';
                    if (!$this->payments->releaseRefundClaim($paymentId, $claimToken)) {
                        throw new \RuntimeException('Refund claim release could not be confirmed');
                    }
                }
            } catch (\Throwable $error) {
                $this->refundAuditBestEffort($adminUserId, 'payment_refund_indeterminate', $paymentId, [
                    'payment_no' => $paymentNo,
                    'amount' => $requested,
                    'claim_token' => $claimToken,
                    'expected_status' => $status,
                    'expected_base' => $alreadyRefunded,
                    'intended_total' => $totalRefunded,
                    'provider' => $providerKey,
                    'provider_outcome' => 'success',
                    'persistence_stage' => $persistenceStage,
                    'error_class' => get_class($error),
                ]);
                return [
                    'ok' => false,
                    'outcome' => 'indeterminate',
                    'message' => '金流商已回報退款成功，但本地記帳或處理鎖的完成狀態無法確認。'
                        . '請勿重送退款；請至金流商後台查詢並人工對帳（payment_refund_indeterminate）。',
                ];
            }

            if (!$persisted) {
                // 錢已經在金流商端退掉了，但本地寫不進去（claim 被外力清除，
                // 或餘額已被其他寫入者改動）。此時**絕對不可**用舊基準硬寫，
                // 那會把別人剛記上的較新金額蓋掉。留給人工對帳是唯一安全解。
                $this->refundAuditBestEffort($adminUserId, 'payment_refund_orphaned', $paymentId, [
                    'payment_no'       => $paymentNo,
                    'amount'           => $requested,
                    'claim_token'      => $claimToken,
                    'expected_base'    => $alreadyRefunded,
                    'intended_total'   => $totalRefunded,
                    'provider_message' => $result['message'] ?? '',
                ]);

                return [
                    'ok'      => false,
                    'outcome' => 'indeterminate',
                    'message' => sprintf(
                        '金流商已回報退款成功（%d 元），但本地紀錄無法更新'
                        . '——此筆付款的退款狀態在處理期間被其他作業改動。'
                        . '款項已實際退出，請立即人工對帳（稽核動作 payment_refund_orphaned）。',
                        $requested
                    ),
                ];
            }

            $this->refundAuditBestEffort($adminUserId, 'payment_refunded', $paymentId, [
                'payment_no'      => $paymentNo,
                'amount'          => $requested,
                'refunded_total'  => $totalRefunded,
                'is_full'         => $isFull,
                'message'         => $result['message'] ?? '',
            ]);

            return [
                'ok'      => true,
                'outcome' => 'success',
                'message' => $isFull
                    ? '已全額退款。'
                    : sprintf('已退款 %d 元，尚餘 %d 元可退。', $requested, $paidAmount - $totalRefunded),
            ];
        }

        if ($outcome === 'indeterminate') {
            // 🔴 **刻意不釋放 claim**：不確定代表金流商可能已經退了。
            // 若此時把鎖放掉讓人重按，那就是實質的雙退。必須由人確認實際結果後
            // 手動清除（清除方式見 docs/GO-LIVE.md）。
            $this->refundAuditBestEffort($adminUserId, 'payment_refund_indeterminate', $paymentId, [
                'payment_no'  => $paymentNo,
                'amount'      => $requested,
                'claim_token' => $claimToken,
                'message'     => $result['message'] ?? '',
            ]);

            return [
                'ok'      => false,
                'outcome' => 'indeterminate',
                'message' => '退款結果不確定：' . (string) ($result['message'] ?? '')
                    . ' 狀態維持不變，且已鎖定此筆付款避免重複退款；'
                    . '請至金流商後台確認實際結果後再處理。',
            ];
        }

        // 明確被拒 → 對方確定沒有動作，可安全釋放 claim 讓人修正後重試。
        $this->payments->releaseRefundClaim($paymentId, $claimToken);

        $this->refundAuditBestEffort($adminUserId, 'payment_refund_failed', $paymentId, [
            'payment_no' => $paymentNo,
            'amount'     => $requested,
            'message'    => $result['message'] ?? '',
        ]);

        return [
            'ok'      => false,
            'outcome' => 'rejected_terminal',
            'message' => (string) ($result['message'] ?? '退款遭金流商拒絕。'),
        ];
    }

    // ───────────────────────── 內部輔助 ─────────────────────────

    /**
     * 退款 audit 是重要的旁路證據，但不能覆寫金流商與 finalize 已確定的結果。
     *
     * 外部退款不是可 rollback 的 DB 操作；audit insert 若在 provider success 後
     * 向上拋出，Controller 會顯示 500，操作者可能誤以為沒有退款而再次送出。
     * 因此退款狀態機的所有結論都採 best-effort audit，故障另寫 server log。
     *
     * @param array<string,mixed> $detail
     */
    private function refundAuditBestEffort(
        int $adminUserId,
        string $action,
        int $paymentId,
        array $detail
    ): void {
        try {
            $this->auditLog->log($adminUserId, $action, 'payment', $paymentId, $detail);
        } catch (\Throwable $e) {
            try {
                // Preserve reconciliation coordinates even if DB audit storage
                // is unavailable. Never put provider messages or SQL text here.
                $safeDetail = array_intersect_key($detail, array_flip([
                    'payment_no', 'amount', 'claim_token', 'expected_status',
                    'expected_base', 'intended_total', 'provider',
                    'provider_outcome', 'persistence_stage', 'error_class',
                ]));
                error_log(sprintf(
                    '[payment][refund][audit] action=%s payment_id=%d audit_error_class=%s evidence=%s',
                    $action,
                    $paymentId,
                    get_class($e),
                    json_encode($safeDetail, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE)
                ));
            } catch (\Throwable) {
                // 即使 server logger 本身失效，也不可改寫已確定的退款 outcome。
            }
        }
    }

    /**
     * 同步報價為已付款：payment_status='paid' + 走 QuoteService 狀態機轉 paid。
     * 狀態機若不允許當下轉 paid（如已 void）則僅更新 payment_status，不拋錯阻斷付款入帳。
     */
    private function syncQuotePaid(int $quoteId): void
    {
        // 報價 payment_status 旗標（獨立於主狀態）。
        $this->quotes->update($quoteId, ['payment_status' => 'paid']);

        // 主狀態走狀態機轉 paid（signed→paid / viewed→paid / sent→paid 皆合法）。
        try {
            $this->quoteService->transitionStatus($quoteId, 'paid');
        } catch (\RuntimeException) {
            // 當下狀態不可轉 paid（如已 void/已 paid）→ 忽略，付款入帳不受影響。
        }
    }

    /**
     * 伺服器端應付金額：直接取報價 total（整數元）。
     * total 於建立/更新報價時已由 QuoteService 依明細伺服器端重算，為可信來源。
     */
    private function serverAmountForQuote(int $quoteId): int
    {
        $quote = $this->quotes->findById($quoteId);
        if ($quote === null) {
            return 0;
        }
        return (int) round((float) ($quote['total'] ?? 0));
    }

    /**
     * 產生 payment_no（PAY-YYYY-NNNN）。
     * 須於交易內呼叫：以 FOR UPDATE 鎖定當年既有列，取最大序號 +1，避免併發重號。
     */
    private function generatePaymentNo(): string
    {
        $year = (int) date('Y');
        $next = $this->payments->maxSequenceForYearForUpdate($year) + 1;
        return sprintf('PAY-%04d-%04d', $year, $next);
    }
}
