<?php

declare(strict_types=1);

namespace YangSheep\CRM\EInvoice;

use YangSheep\CRM\AuditLog\AuditLogService;
use YangSheep\CRM\Core\Cache;
use YangSheep\CRM\Core\Encryption;
use YangSheep\CRM\Core\HttpClient;
use YangSheep\CRM\Payment\PaymentRepository;

/**
 * 電子發票核心編排服務。
 *
 * 移植自 ys-enhance-hosting YSEInvoiceService 的開立流程，保留其全部防護機制。
 *
 * 【開立一張發票要對抗的四種現實】
 *   1. 兩個 worker 同時想開同一筆付款的發票 → GET_LOCK（跨 process）+ CAS（同列）
 *   2. 送出後回應遺失（timeout/5xx），發票其實已經開出來 → indeterminate 補查
 *   3. 之前某次送出成功但我方沒記錄到 → 送出前先依訂單號查詢（reconcile-first）
 *   4. 對方系統異常，我方持續重送把失敗累積成人工債 → 限流 + 熔斷
 *
 * 這四層缺一不可，且順序不可調換。查詢失敗時一律 **不送出**（fail-closed）：
 * 寧可延後開立，也不能冒重複開立的風險——重複發票要跑國稅局作廢流程，成本遠高於延遲。
 */
class InvoiceService
{
    private InvoiceRepository $invoices;
    private PaymentRepository $payments;
    private InvoiceSettings $settings;
    private InvoiceProfileRepository $profiles;
    private AuditLogService $auditLog;
    private Cache $cache;
    private ?InvoiceClientInterface $client;
    private HttpClient $http;

    public function __construct(
        ?InvoiceSettings $settings = null,
        ?InvoiceClientInterface $client = null,
        ?InvoiceRepository $invoices = null,
        ?PaymentRepository $payments = null,
        ?InvoiceProfileRepository $profiles = null,
        ?Cache $cache = null,
        ?HttpClient $http = null
    ) {
        $this->settings = $settings ?? new InvoiceSettings();
        $this->client   = $client;
        $this->invoices = $invoices ?? new InvoiceRepository();
        $this->payments = $payments ?? new PaymentRepository();
        $this->profiles = $profiles ?? new InvoiceProfileRepository();
        $this->auditLog = new AuditLogService();
        $this->cache    = $cache ?? new Cache();
        $this->http     = $http ?? new HttpClient();
    }

    /**
     * 取得 API client（延遲建立，讓設定變更即時生效）。
     */
    private function client(): InvoiceClientInterface
    {
        if ($this->client !== null) {
            return $this->client;
        }

        return new PayNowRestClient(
            $this->settings->baseUrl(),
            $this->settings->token(),
            $this->settings->environment()
        );
    }

    // ───────────────────────── 主流程 ─────────────────────────

    /**
     * 為某筆付款開立發票。
     *
     * @param string $actor auto（付款成功自動觸發）/ manual（後台）/ retry（cron）
     * @return array{success: bool, message: string, invoice_id?: int}
     */
    public function issueForPayment(int $paymentId, string $actor = 'auto', int $actorUserId = 0): array
    {
        if (!$this->settings->enabled()) {
            return ['success' => false, 'message' => '電子發票功能未啟用。'];
        }

        $payment = $this->payments->findById($paymentId);
        if ($payment === null) {
            return ['success' => false, 'message' => '找不到付款紀錄。'];
        }

        // 憑證未設定：**仍要建立待開立的發票列**。
        //
        // 原本在這裡直接 return，結果是「啟用了發票但還沒填 Token」期間的付款
        // 完全不留痕跡；日後補上 Token，cron 掃 status='failed' 也找不到這些付款，
        // 那批發票就永遠不會被開出來，且沒有任何地方看得出漏了。
        // 改為先建列並標記失敗原因，補好 Token 後由重試流程自然接手。
        if (!$this->settings->isConfigured()) {
            // 未配置分支也必須拿 payment-scoped lock：正常 configured 路徑會在鎖內
            // find/create；若這裡繞過，同一筆付款的兩個 maintenance process 可各建一列。
            if (!$this->invoices->acquireLock((int) $payment['id'])) {
                return ['success' => false, 'message' => '另一個程序正在處理這筆付款的發票，請稍候。'];
            }

            try {
                if ($actor === 'recovery' && $this->invoices->getMissingIntentPaymentIds(1, $paymentId) === []) {
                    return ['success' => false, 'message' => '待恢復的發票意圖已變更，未建立新發票。'];
                }
                // 設定可能在等鎖期間補好；再確認一次，避免把可送出的付款寫回 blocked。
                if (!$this->settings->isConfigured()) {
                    $this->ensureFailedRowForRetry($payment, 'PayNow 憑證尚未設定，待設定後由排程自動重試。');
                    return ['success' => false, 'message' => 'PayNow 憑證尚未設定。'];
                }
            } finally {
                $this->invoices->releaseLock((int) $payment['id']);
            }

            // 設定已在等鎖期間補齊，落回下方正常流程並重新取得一次完整工作鎖。
        }
        if (($payment['status'] ?? '') !== 'paid') {
            return ['success' => false, 'message' => '付款尚未入帳，不可開立發票。'];
        }
        $amount = (int) round((float) ($payment['amount'] ?? 0));
        if ($amount < 1) {
            return ['success' => false, 'message' => '零元付款不開立發票。'];
        }
        if (strtoupper((string) ($payment['currency'] ?? 'TWD')) !== 'TWD') {
            return ['success' => false, 'message' => 'PayNow 電子發票目前僅支援台幣訂單。'];
        }

        // 第 1 層：跨 process 互斥。取不到鎖代表別人正在處理同一筆付款。
        if (!$this->invoices->acquireLock($paymentId)) {
            return ['success' => false, 'message' => '另一個程序正在處理這筆付款的發票，請稍候。'];
        }

        try {
            // The scan is only a candidate list. A cancelled pending attempt
            // must not be recreated if its intent changed while awaiting lock.
            if ($actor === 'recovery' && $this->invoices->getMissingIntentPaymentIds(1, $paymentId) === []) {
                return ['success' => false, 'message' => '待恢復的發票意圖已變更，未建立新發票。'];
            }
            return $this->issueLocked($payment, $actor === 'recovery' ? 'auto' : $actor, $actorUserId);
        } finally {
            $this->invoices->releaseLock($paymentId);
        }
    }

    /**
     * 確保這筆付款留有一列「待重試」的發票紀錄。
     *
     * 用於前置條件失敗（例如憑證未設定）時 —— 沒有這一列，cron 的重試掃描
     * 就找不到這筆付款，補齊設定後也不會自動補開。
     *
     * @param array<string,mixed> $payment
     */
    private function ensureFailedRowForRetry(array $payment, string $reason): void
    {
        try {
            $paymentId = (int) $payment['id'];
            $existing  = $this->invoices->findActiveByPayment($paymentId);

            if ($existing !== null) {
                // 已有列：只在它處於非終態時更新原因，不覆蓋已開立/處理中的狀態。
                if (in_array((string) $existing['status'], ['pending', 'failed'], true)) {
                    $this->invoices->markConfigurationBlocked((int) $existing['id'], $reason);
                }
                return;
            }

            $invoiceId = $this->invoices->create([
                'payment_id'   => $paymentId,
                'quote_id'     => $payment['quote_id'] ?? null,
                'customer_id'  => $payment['customer_id'] ?? null,
                'status'       => 'pending',
                'issue_type'   => 'auto',
                'order_no'     => (string) ($payment['payment_no'] ?? ''),
                'total_amount' => (float) ($payment['amount'] ?? 0),
            ]);
            $this->invoices->markConfigurationBlocked($invoiceId, $reason);
        } catch (\Throwable) {
            // 建列失敗不可回頭影響付款流程；這只是恢復路徑的輔助。
        }
    }

    /**
     * 已持有鎖的開立流程。
     *
     * @param array<string,mixed> $payment
     * @return array{success: bool, message: string, invoice_id?: int}
     */
    private function issueLocked(array $payment, string $actor, int $actorUserId): array
    {
        $paymentId = (int) $payment['id'];

        $invoice = $this->invoices->findActiveByPayment($paymentId);
        if ($invoice !== null && ($invoice['status'] ?? '') === 'issued') {
            return [
                'success'    => true,
                'message'    => '此付款已開立發票（' . (string) $invoice['invoice_number'] . '）。',
                'invoice_id' => (int) $invoice['id'],
            ];
        }

        if ($invoice === null) {
            $invoiceId = $this->invoices->create([
                'payment_id'   => $paymentId,
                'quote_id'     => $payment['quote_id'] ?? null,
                'customer_id'  => $payment['customer_id'] ?? null,
                'status'       => 'pending',
                'issue_type'   => $actor === 'auto' ? 'auto' : 'manual',
                'order_no'     => (string) $payment['payment_no'],
                'total_amount' => (float) $payment['amount'],
            ]);
            $invoice = $this->invoices->findById($invoiceId);
            if ($invoice === null) {
                return ['success' => false, 'message' => '無法建立發票紀錄。'];
            }
        }

        $invoiceId = (int) $invoice['id'];

        // 第 2 層：CAS 佔用。搶不到代表狀態不允許（issuing / issued / cancelled）。
        $claimToken = HttpClient::uuid4();
        // 🔴 只有人工介入（後台「重試開立」）能把已放棄（abandoned）的發票撿回來。
        // 自動路徑一律不行 —— 金流商重送付款通知是常態行為，若讓它也能 claim，
        // 重試上限就等於不存在，每次重送都會再打一次 PayNow。
        $manual = $actor === 'manual';

        if (!$this->invoices->claimForIssue($invoiceId, $claimToken, $manual)) {
            return ['success' => false, 'message' => '此發票正在處理中或已完成，未重複送出。'];
        }

        // 熔斷檢查：對方系統連續失敗時暫停送出，避免累積人工債。
        $pause = $this->circuitPauseMessage();
        if ($pause !== '') {
            $this->invoices->markFailed($invoiceId, $claimToken, $pause);
            return ['success' => false, 'message' => $pause, 'invoice_id' => $invoiceId];
        }

        // 出網限流。
        if (!$this->reserveApiSlot()) {
            $message = 'PayNow 發票 API 已達每分鐘呼叫上限，稍後自動重試。';
            $this->invoices->markFailed($invoiceId, $claimToken, $message);
            return ['success' => false, 'message' => $message, 'invoice_id' => $invoiceId];
        }

        // Once an attempt has been persisted, mutable customer/settings data
        // cannot describe what may already have been issued externally.
        $savedSnapshot = (string) ($invoice['payload_snapshot'] ?? '');
        $newAttempt = null;
        try {
            if ($savedSnapshot !== '') {
                $attempt = json_decode((new Encryption())->decrypt($savedSnapshot), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($attempt) || !is_string($attempt['order_no'] ?? null)
                    || $attempt['order_no'] === '' || !is_numeric($attempt['total_amount'] ?? null)) {
                    throw new \InvalidArgumentException('既有發票開立快照無法確認。');
                }
                $orderNo = $attempt['order_no'];
                $expectedAmount = (int) $attempt['total_amount'];
                // Legacy snapshots did not retain every request field. They
                // may be reconciled, but never rebuilt from today's profile.
                $payload = is_array($attempt['request_payload'] ?? null) ? $attempt['request_payload'] : null;
                if ($payload !== null && (($payload['order_no'] ?? null) !== $orderNo
                    || ($payload['total_amount'] ?? null) !== $expectedAmount)) {
                    throw new \InvalidArgumentException('既有發票請求與快照不符。');
                }
            } else {
                $orderNo = $this->orderNoForIssue($invoiceId, $paymentId, (string) $payment['payment_no']);
                $built = InvoicePayloadBuilder::buildForPayment(
                    $payment, $this->resolveSnapshot($payment), $this->settings->payloadSettings(), $orderNo
                );
                $payload = $built['payload'];
                $expectedAmount = (int) $payload['total_amount'];
                $newAttempt = $built['snapshot'] + ['request_payload' => $payload];
            }
        } catch (\Throwable $e) {
            if ($savedSnapshot !== '') {
                return $this->issueIndeterminate($invoiceId, $actorUserId, $claimToken, 'saved_snapshot_unreadable');
            }
            if (!$e instanceof \InvalidArgumentException) {
                throw $e;
            }
            $this->invoices->markFailed($invoiceId, $claimToken, $e->getMessage(), '', false);
            $this->audit($actorUserId, 'invoice_payload_invalid', $invoiceId, ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage(), 'invoice_id' => $invoiceId];
        }

        return $this->sendIssue(
            $invoiceId,
            $claimToken,
            $paymentId,
            $orderNo,
            $payload,
            $expectedAmount,
            $actor,
            $actorUserId,
            $newAttempt
        );
    }

    /**
     * 送出開立請求（含 reconcile-first 與 timeout 補查）。
     *
     * @param array<string,mixed> $payload
     * @return array{success: bool, message: string, invoice_id?: int}
     */
    private function sendIssue(
        int $invoiceId,
        string $claimToken,
        int $paymentId,
        string $orderNo,
        ?array $payload,
        int $expectedAmount,
        string $actor,
        int $actorUserId,
        ?array $newAttempt = null
    ): array {
        $client  = $this->client();
        $context = [
            'correlation_id'          => HttpClient::uuid4(),
            'invoice_id'              => $invoiceId,
            'payment_id'              => $paymentId,
            'expected_total_amount'   => $expectedAmount,
            // 排除本地已作廢號碼：作廢重開時絕不能把剛作廢那張認成有效發票。
            'exclude_invoice_numbers' => $this->invoices->getCancelledNumbersByPayment($paymentId),
        ];

        // 第 3 層：送出前先查詢。查詢失敗一律不送出（fail-closed）。
        $existing = $client->queryByOrder($orderNo, $context);
        if (empty($existing['success'])) {
            if ($payload === null) {
                return $this->issueIndeterminate($invoiceId, $actorUserId, $claimToken, 'legacy_snapshot_query_failed');
            }
            $message = '開立前查詢 PayNow 失敗，為避免重複發票，本次未送出：'
                . (string) ($existing['message'] ?? '未知錯誤');
            $this->invoices->markFailed(
                $invoiceId,
                $claimToken,
                $message,
                $this->encodeResponse($existing['raw'] ?? [])
            );
            return ['success' => false, 'message' => $message, 'invoice_id' => $invoiceId];
        }
        if (!empty($existing['found']) && is_array($existing['invoice'] ?? null)) {
            $this->recordApiSuccess();
            return $this->completeIssue(
                $invoiceId,
                $claimToken,
                $existing['invoice'],
                $existing['raw'] ?? [],
                $actor . '-reconciled',
                $actorUserId
            );
        }

        if ($payload === null) {
            return $this->issueIndeterminate($invoiceId, $actorUserId, $claimToken, 'legacy_snapshot_query_not_found');
        }
        // Save a complete encrypted request only after reconciliation found no
        // existing invoice, and before the first irreversible POST.
        if ($newAttempt !== null) {
            $this->invoices->update($invoiceId, [
                'order_no' => $orderNo,
                'tax_amount' => (float) $payload['tax_amount'],
                'payload_snapshot' => $this->encryptSnapshot($newAttempt),
            ]);
        }
        $result = $client->issueInvoice($payload, $context);
        if (!empty($result['success'])) {
            $this->recordApiSuccess();
            return $this->completeIssue(
                $invoiceId,
                $claimToken,
                (array) ($result['invoice'] ?? []),
                $result['raw'] ?? [],
                $actor,
                $actorUserId
            );
        }

        // timeout / 5xx：發票可能已開出但回應遺失 → 補查後再判定。
        if (!empty($result['indeterminate'])) {
            $reconciled = $client->queryByOrder($orderNo, $context);
            if (!empty($reconciled['success']) && !empty($reconciled['found']) && is_array($reconciled['invoice'] ?? null)) {
                $this->recordApiSuccess();
                return $this->completeIssue(
                    $invoiceId,
                    $claimToken,
                    $reconciled['invoice'],
                    $reconciled['raw'] ?? [],
                    $actor . '-post-timeout-reconciled',
                    $actorUserId
                );
            }
            if (empty($reconciled['success'])) {
                $result['message'] = (string) ($result['message'] ?? '開立結果不確定。')
                    . ' 補查也失敗；系統保留失敗狀態，下次重送前會再次依訂單號查詢。';
            }
        }

        $this->recordApiFailure();
        $message = (string) ($result['message'] ?? '發票開立失敗。');
        $this->invoices->markFailed(
            $invoiceId,
            $claimToken,
            $message,
            $this->encodeResponse($result['raw'] ?? [])
        );
        $this->audit($actorUserId, 'invoice_issue_failed', $invoiceId, ['error' => $message]);

        return ['success' => false, 'message' => $message, 'invoice_id' => $invoiceId];
    }

    /**
     * 標記開立成功。
     *
     * @param array<string,mixed> $invoiceData
     * @param array<string,mixed> $response
     * @return array{success: bool, message: string, invoice_id: int}
     */
    private function completeIssue(
        int $invoiceId,
        string $claimToken,
        array $invoiceData,
        array $response,
        string $actor,
        int $actorUserId
    ): array {
        $number = trim((string) ($invoiceData['invoice_number'] ?? ''));
        if ($number === '') {
            $message = 'PayNow 回應缺少發票號碼，無法標記為已開立。';
            $this->invoices->markFailed(
                $invoiceId,
                $claimToken,
                $message,
                $this->encodeResponse($response)
            );
            return ['success' => false, 'message' => $message, 'invoice_id' => $invoiceId];
        }

        try {
            $persisted = $this->invoices->markIssued(
                $invoiceId,
                $claimToken,
                $number,
                (string) ($invoiceData['invoice_date'] ?? ''),
                (string) ($invoiceData['random_number'] ?? ''),
                $this->encodeResponse($response)
            );
        } catch (\Throwable $error) {
            return $this->issueIndeterminate($invoiceId, $actorUserId, $claimToken, 'mark_issued_failed', [
                'invoice_number' => $number, 'provider_outcome' => 'success', 'error_class' => get_class($error),
            ]);
        }

        if (!$persisted) {
            $detail = [
                'invoice_number' => $number,
                'actor' => $actor,
            ];
            try {
                $this->audit($actorUserId, 'invoice_issue_orphaned', $invoiceId, $detail);
            } catch (\Throwable $auditError) {
                try {
                    error_log('[invoice][issue][audit] orphaned audit failed: '
                        . str_replace(["\r", "\n"], ' ', $auditError->getMessage()));
                } catch (\Throwable) {
                    // Audit/logger 都是旁路；不得再覆寫 provider 已成功但本地 CAS miss 的 outcome。
                }
            }

            return [
                'success' => false,
                'outcome' => 'indeterminate',
                'message' => '開立商已回報發票開立成功，但本地 claim 或狀態已在處理期間改變，'
                    . '因此未覆寫較新的資料。請重試；系統會先依訂單號查詢並完成對帳，不會直接重送開立。',
                'invoice_id' => $invoiceId,
            ];
        }

        $this->audit($actorUserId, 'invoice_issued', $invoiceId, [
            'invoice_number' => $number,
            'actor'          => $actor,
        ]);

        return ['success' => true, 'message' => '發票開立成功：' . $number, 'invoice_id' => $invoiceId];
    }

    // ───────────────────────── 作廢 / 重開 ─────────────────────────

    /** Preserve ownership and attempt evidence when issuance cannot be finalized. */
    private function issueIndeterminate(int $invoiceId, int $actorUserId, string $claimToken, string $reason, array $detail = []): array
    {
        $this->auditBestEffort($actorUserId, 'invoice_issue_indeterminate', $invoiceId, $detail + [
            'reason' => $reason, 'claim_token' => $claimToken,
        ]);
        return [
            'success' => false, 'outcome' => 'indeterminate', 'invoice_id' => $invoiceId,
            'message' => '發票開立結果或既有快照無法確認；已保留處理紀錄，請人工查核，勿直接重送開立。',
        ];
    }

    /**
     * 作廢已開立的發票。
     *
     * @return array{success: bool, message: string}
     */
    public function cancelInvoice(int $invoiceId, int $actorUserId = 0): array
    {
        $invoice = $this->invoices->findById($invoiceId);
        if ($invoice === null) {
            return ['success' => false, 'message' => '找不到發票紀錄。'];
        }
        $paymentId = (int) ($invoice['payment_id'] ?? 0);
        if ($paymentId <= 0) {
            return ['success' => false, 'message' => '發票缺少付款關聯，無法安全取得作廢鎖。'];
        }

        // 與開立/重開共用 payment-scoped MySQL lock：作廢尚未完成時，另一個
        // request 不得同時送出第二次作廢或建立重開列。
        if (!$this->invoices->acquireLock($paymentId)) {
            return ['success' => false, 'message' => '另一個程序正在處理這筆付款的發票，請稍候。'];
        }

        try {
            return $this->cancelInvoiceLocked($invoiceId, $actorUserId);
        } finally {
            $this->invoices->releaseLock($paymentId);
        }
    }

    /** @return array{success:bool,message:string,outcome?:string} */
    private function cancelInvoiceLocked(int $invoiceId, int $actorUserId): array
    {
        // Waiting for the named lock can change every precondition; always re-read.
        $invoice = $this->invoices->findById($invoiceId);
        if ($invoice === null) {
            return ['success' => false, 'message' => '找不到發票紀錄。'];
        }
        if (($invoice['status'] ?? '') !== 'issued') {
            return ['success' => false, 'message' => '只有已開立的發票可以作廢。'];
        }
        $number = trim((string) ($invoice['invoice_number'] ?? ''));
        if ($number === '') {
            return ['success' => false, 'message' => '發票缺少號碼，無法作廢。'];
        }

        $existingClaim = trim((string) ($invoice['claim_token'] ?? ''));
        if ($existingClaim !== '') {
            return $this->reconcileCancellation($invoice, $existingClaim, $actorUserId);
        }

        $claimToken = bin2hex(random_bytes(32));
        if (!$this->invoices->claimForCancellation($invoiceId, $number, $claimToken)) {
            return ['success' => false, 'message' => '另一個程序已取得這張發票的作廢權，請重新整理。'];
        }

        try {
            $result = $this->client()->cancelInvoice($number, [
                'correlation_id' => HttpClient::uuid4(),
                'invoice_id'     => $invoiceId,
                'payment_id'     => (int) ($invoice['payment_id'] ?? 0),
            ]);
        } catch (\Throwable $error) {
            return $this->cancellationIndeterminate(
                $invoiceId,
                $actorUserId,
                $number,
                '作廢請求中斷，無法確認 PayNow 是否已接受；已保留 claim，禁止直接重送。',
                $error->getMessage()
            );
        }

        if (empty($result['success'])) {
            $message = (string) ($result['message'] ?? '作廢失敗。');
            if (!empty($result['indeterminate'])) {
                return $this->cancellationIndeterminate(
                    $invoiceId,
                    $actorUserId,
                    $number,
                    '作廢結果不確定；已保留 claim，重新操作只會先查核，不會再次送出。',
                    $message
                );
            }

            try {
                $released = $this->invoices->releaseCancellationClaim(
                    $invoiceId,
                    $number,
                    $claimToken,
                    $message
                );
            } catch (\Throwable $error) {
                $released = false;
                $message .= '；本地 claim 釋放失敗：' . $error->getMessage();
            }
            if (!$released) {
                return $this->cancellationIndeterminate(
                    $invoiceId,
                    $actorUserId,
                    $number,
                    'PayNow 拒絕作廢，但本地 claim 無法安全釋放，請人工查核。',
                    $message
                );
            }

            $this->auditBestEffort($actorUserId, 'invoice_cancel_failed', $invoiceId, ['error' => $message]);
            return ['success' => false, 'outcome' => 'rejected_terminal', 'message' => $message];
        }

        $providerNumber = trim((string) ($result['invoice_number'] ?? ''));
        if ($providerNumber === '' || !hash_equals($number, $providerNumber)) {
            return $this->cancellationIndeterminate(
                $invoiceId,
                $actorUserId,
                $number,
                'PayNow 回報作廢成功，但回傳號碼無法與本地發票吻合；已保留 claim。',
                'provider_invoice_number=' . $providerNumber
            );
        }

        try {
            $persisted = $this->invoices->finalizeCancellation(
                $invoiceId,
                $number,
                $claimToken,
                $this->encodeResponse($result['raw'] ?? [])
            );
        } catch (\Throwable $error) {
            $persisted = false;
            $persistError = $error->getMessage();
        }
        if (!$persisted) {
            return $this->cancellationIndeterminate(
                $invoiceId,
                $actorUserId,
                $number,
                'PayNow 已回報作廢成功，但本地 owner CAS 未完成；已保留 claim，禁止直接重送。',
                $persistError ?? 'owner CAS missed'
            );
        }

        $this->auditBestEffort($actorUserId, 'invoice_cancelled', $invoiceId, ['invoice_number' => $number]);

        return ['success' => true, 'outcome' => 'success', 'message' => '發票已作廢：' . $number];
    }

    /**
     * A prior worker may have reached PayNow before dying. Reconcile the durable
     * claim first; never turn an inconclusive query into another cancellation POST.
     *
     * @param array<string,mixed> $invoice
     * @return array{success:bool,message:string,outcome:string}
     */
    private function reconcileCancellation(array $invoice, string $claimToken, int $actorUserId): array
    {
        $invoiceId = (int) $invoice['id'];
        $number = trim((string) ($invoice['invoice_number'] ?? ''));
        try {
            $query = $this->client()->queryByNumber($number, [
                'correlation_id' => HttpClient::uuid4(),
                'invoice_id' => $invoiceId,
                'payment_id' => (int) ($invoice['payment_id'] ?? 0),
            ]);
        } catch (\Throwable $error) {
            return $this->cancellationIndeterminate(
                $invoiceId,
                $actorUserId,
                $number,
                '既有作廢 claim 查核失敗；未重新送出作廢。',
                $error->getMessage()
            );
        }

        $providerInvoice = is_array($query['invoice'] ?? null) ? $query['invoice'] : [];
        $providerStatus = strtolower(trim((string) ($providerInvoice['status'] ?? '')));
        $providerNumber = trim((string) ($providerInvoice['invoice_number'] ?? ''));
        $providerProvesCancelled = !empty($query['success'])
            && !empty($query['found'])
            && $providerNumber !== ''
            && hash_equals($number, $providerNumber)
            && in_array($providerStatus, ['cancel', 'cancelled', 'void', 'voided'], true);

        if ($providerProvesCancelled) {
            try {
                $persisted = $this->invoices->finalizeCancellation(
                    $invoiceId,
                    $number,
                    $claimToken,
                    $this->encodeResponse($query['raw'] ?? [])
                );
            } catch (\Throwable) {
                $persisted = false;
            }
            if ($persisted) {
                $this->auditBestEffort($actorUserId, 'invoice_cancel_reconciled', $invoiceId, [
                    'invoice_number' => $number,
                ]);
                return [
                    'success' => true,
                    'outcome' => 'success',
                    'message' => '已從 PayNow 查核並完成作廢：' . $number,
                ];
            }
        }

        return $this->cancellationIndeterminate(
            $invoiceId,
            $actorUserId,
            $number,
            '既有作廢結果仍無法確認；未重新送出作廢，請人工查核。',
            (string) ($query['message'] ?? 'provider state inconclusive')
        );
    }

    /** @return array{success:false,message:string,outcome:string} */
    private function cancellationIndeterminate(
        int $invoiceId,
        int $actorUserId,
        string $number,
        string $message,
        string $detail
    ): array {
        $this->auditBestEffort($actorUserId, 'invoice_cancel_indeterminate', $invoiceId, [
            'invoice_number' => $number,
            'detail' => $detail,
        ]);
        return ['success' => false, 'outcome' => 'indeterminate', 'message' => $message];
    }

    /**
     * 作廢後重開：對同一筆付款建立新的發票列。
     *
     * @return array{success: bool, message: string, invoice_id?: int}
     */
    public function reissueForPayment(int $paymentId, int $actorUserId = 0): array
    {
        $latest = $this->invoices->findLatestByPayment($paymentId);
        if ($latest !== null && ($latest['status'] ?? '') === 'issued') {
            return ['success' => false, 'message' => '尚有已開立的發票，請先作廢再重開。'];
        }

        $this->audit($actorUserId, 'invoice_reissue_requested', (int) ($latest['id'] ?? 0), ['payment_id' => $paymentId]);
        return $this->issueForPayment($paymentId, 'manual', $actorUserId);
    }

    /**
     * 計算本次送給 PayNow 的訂單號。
     *
     * PayNow 實測（request_id b533d31c）：OrderNo 一經開立即永久佔用，作廢後同號重開
     * 回 HTTP 400「OrderNo has already been issued」。故重開改送「原單號-R{發票 row id}」——
     * 對同一 row 恆定（補查與 POST 必須共用同值），跨 row 唯一。
     *
     * 超過 30 字一律拋錯，**絕不靜默截斷**：截斷會讓補查與 POST 用到不同訂單號，
     * 補查查不到就會重複開立，正是本模組要防的事。
     */
    private function orderNoForIssue(int $invoiceId, int $paymentId, string $paymentNo): string
    {
        if ($this->invoices->getCancelledNumbersByPayment($paymentId) === []) {
            return $paymentNo;
        }

        $reissueNo = $paymentNo . '-R' . $invoiceId;
        if (strlen($reissueNo) > 30) {
            throw new \InvalidArgumentException('重開發票的 PayNow 訂單編號超過 30 字上限，請調整付款編號策略。');
        }

        return $reissueNo;
    }

    // ───────────────────────── 排程任務 ─────────────────────────

    /**
     * 重試到期的失敗發票（cron）。
     *
     * @return array{processed: int, succeeded: int}
     */
    public function retryDue(int $limit = 20): array
    {
        $processed = 0;
        $succeeded = 0;

        foreach ($this->invoices->getDueRetryIds($limit) as $invoiceId) {
            $invoice   = $this->invoices->findById($invoiceId);
            $paymentId = (int) ($invoice['payment_id'] ?? 0);
            if ($paymentId <= 0) {
                continue;
            }
            $processed++;
            $result = $this->issueForPayment($paymentId, 'retry');
            if (!empty($result['success'])) {
                $succeeded++;
            }
        }

        return ['processed' => $processed, 'succeeded' => $succeeded];
    }

    /**
     * Recover the narrow post-payment crash window where the durable auto-issue
     * marker exists but invoice-row creation never completed.
     *
     * @return array{processed:int,succeeded:int}
     */
    public function recoverMissingIntents(int $limit = 20): array
    {
        if (!$this->settings->autoIssueEnabled()) {
            return ['processed' => 0, 'succeeded' => 0];
        }

        $processed = 0;
        $succeeded = 0;
        foreach ($this->invoices->getMissingIntentPaymentIds($limit) as $paymentId) {
            $processed++;
            $result = $this->issueForPayment($paymentId, 'recovery');
            if (!empty($result['success'])) {
                $succeeded++;
            }
        }

        return ['processed' => $processed, 'succeeded' => $succeeded];
    }

    /**
     * 回收殭屍 claim（cron）：worker 中斷後卡在 issuing 的列。
     */
    public function releaseStaleClaims(int $ageSeconds = 1800, int $limit = 20): int
    {
        $released = 0;
        foreach ($this->invoices->getStaleIssuingClaims($ageSeconds, $limit) as $claim) {
            $invoiceId = $claim['id'];
            if ($this->invoices->releaseStaleClaim(
                $invoiceId,
                $claim['claim_token'],
                $claim['claimed_at']
            )) {
                $released++;
                $this->audit(0, 'invoice_stale_claim_released', $invoiceId, []);
            }
        }

        return $released;
    }

    // ───────────────────── 官方發票列印 URL ─────────────────────

    /**
     * 取得 PayNow 官方發票頁 URL（SOAP Get_InvoiceURL_I）。
     * 結果加密快取 24 小時。
     */
    public function getOfficialInvoiceUrl(int $invoiceId): array
    {
        $invoice = $this->invoices->findById($invoiceId);
        if ($invoice === null) {
            return ['success' => false, 'message' => '找不到發票紀錄。'];
        }
        if (($invoice['status'] ?? '') !== 'issued' || empty($invoice['invoice_number'])) {
            return ['success' => false, 'message' => '電子發票尚未開立。'];
        }

        // 24h 加密快取
        if (!empty($invoice['pdf_url']) && !empty($invoice['pdf_url_expires_at'])
            && strtotime($invoice['pdf_url_expires_at']) > time()
        ) {
            try {
                $cached = (new Encryption())->decrypt($invoice['pdf_url']);
                if ($cached && self::isAllowedPaynowUrl($cached)) {
                    return ['success' => true, 'url' => $cached, 'source' => 'cache'];
                }
            } catch (\Throwable) { /* A damaged optional cache is a miss. */ }
        }

        $memCid = $this->settings->sellerTaxId();
        if ($memCid === '') {
            return ['success' => false, 'message' => '尚未設定賣方統一編號，無法取得官方發票頁。'];
        }

        if (!$this->reserveApiSlot()) {
            return ['success' => false, 'message' => '查詢過於頻繁，請稍後再試。'];
        }

        $endpoint = $this->settings->environment() === 'production'
            ? 'https://invoice.paynow.com.tw/PayNowEInvoice.asmx'
            : 'https://testinvoice.paynow.com.tw/PayNowEInvoice.asmx';

        $invoiceNo = htmlspecialchars($invoice['invoice_number'], ENT_XML1, 'UTF-8');
        $memCidXml = htmlspecialchars($memCid, ENT_XML1, 'UTF-8');
        $envelope  = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body><Get_InvoiceURL_I xmlns="https://invoice.PayNow.com.tw/">'
            . '<mem_cid>' . $memCidXml . '</mem_cid>'
            . '<InvoiceNo>' . $invoiceNo . '</InvoiceNo>'
            . '</Get_InvoiceURL_I></soap:Body></soap:Envelope>';

        $response = $this->http->request('POST', $endpoint, [
            'headers' => [
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction'   => '"https://invoice.PayNow.com.tw/Get_InvoiceURL_I"',
            ],
            'body'    => $envelope,
            'timeout' => 20,
        ]);

        $url = '';
        if ($response['status'] >= 200 && $response['status'] < 300
            && preg_match('#<Get_InvoiceURL_IResult>(.*?)</Get_InvoiceURL_IResult>#s', $response['body'], $m)
        ) {
            $candidate = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (self::isAllowedPaynowUrl($candidate)) {
                $url = $candidate;
            }
        }

        // API log
        InvoiceApiLogRepository::record([
            'invoice_id'       => $invoiceId,
            'payment_id'       => (int) ($invoice['payment_id'] ?? 0),
            'correlation_id'   => bin2hex(random_bytes(16)),
            'operation'        => 'print_url',
            'api_driver'       => 'paynow_web',
            'environment'      => $this->settings->environment(),
            'http_method'      => 'POST',
            'endpoint'         => $endpoint,
            'http_status'      => $response['status'],
            'success'          => $url !== '' ? 1 : 0,
            'request_id'       => '',
            'duration_ms'      => $response['duration_ms'],
            'request_payload'  => json_encode(['soap_action' => 'Get_InvoiceURL_I', 'mem_cid' => $memCid, 'invoice_number' => $invoice['invoice_number']]),
            'response_payload' => json_encode(['body' => mb_substr($response['body'], 0, 2000)]),
            'error_message'    => $url !== '' ? '' : ($response['error'] ?? '無法取得官方發票頁網址。'),
        ]);

        if ($url === '') {
            return ['success' => false, 'message' => '目前無法取得 PayNow 官方發票頁，請稍後再試。'];
        }

        // 寫入加密快取（24h）
        $cached = false;
        try {
            $encrypted = (new Encryption())->encrypt($url);
            $cached = $this->invoices->update($invoiceId, [
                'pdf_url' => $encrypted,
                'pdf_url_expires_at' => date('Y-m-d H:i:s', time() + 86400),
            ]);
        } catch (\Throwable) {
            // The optional cache cannot mask a usable provider result.
        }

        return ['success' => true, 'url' => $url, 'source' => 'paynow', 'cached' => $cached];
    }

    private static function isAllowedPaynowUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) return false;
        $parts = parse_url(trim($url));
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        return $host === 'invoice.paynow.com.tw'
            || str_ends_with($host, '.paynow.com.tw');
    }

    // ───────────────────────── 發票資料解析 ─────────────────────────

    /**
     * 解析本次開立要用的發票資料。
     *
     * 順序：報價單上的發票資料 → 客戶預設 profile（**必須 is_default=1**）→ 客戶主檔推定。
     *
     * 「沒有 default 就取第一筆」是危險做法：客戶可能存了多組抬頭，取錯就是開錯統編的發票，
     * 而發票開錯只能作廢重開。故一律要求明確的預設值。
     *
     * @param array<string,mixed> $payment
     * @return array<string,mixed>
     */
    public function resolveSnapshot(array $payment): array
    {
        $customerId = (int) ($payment['customer_id'] ?? 0);
        $snapshot   = [];

        if ($customerId > 0) {
            $profile = $this->profiles->findDefaultByCustomer($customerId);
            if ($profile !== null) {
                $snapshot = [
                    'profile_type'     => (string) $profile['profile_type'],
                    'buyer_name'       => (string) $profile['buyer_name'],
                    'buyer_email'      => (string) $profile['buyer_email'],
                    'buyer_phone'      => (string) $profile['buyer_phone'],
                    'buyer_identifier' => (string) $profile['buyer_identifier'],
                    'carrier_type'     => (string) $profile['carrier_type'],
                    'carrier_id_1'     => (string) $profile['carrier_id_1'],
                    'carrier_id_2'     => (string) $profile['carrier_id_2'],
                    'love_code'        => (string) $profile['love_code'],
                    'country'          => (string) $profile['country'],
                ];
            }
        }

        // 客戶主檔推定（僅補空缺，不覆蓋既有 profile 值）。
        $customer = $customerId > 0 ? $this->profiles->findCustomerBasics($customerId) : null;
        if ($customer !== null) {
            $snapshot['buyer_name']  = self::firstNonEmpty($snapshot['buyer_name']  ?? '', (string) ($customer['display_name'] ?? ''));
            $snapshot['buyer_email'] = self::firstNonEmpty($snapshot['buyer_email'] ?? '', (string) ($customer['email'] ?? ''));
            $snapshot['buyer_phone'] = self::firstNonEmpty($snapshot['buyer_phone'] ?? '', (string) ($customer['phone'] ?? ''));

            // 客戶主檔有統編但沒設過發票 profile → 推定為公司發票。
            $customerTaxId = trim((string) ($customer['tax_id'] ?? ''));
            if (self::firstNonEmpty($snapshot['profile_type'] ?? '', '') === '' && $customerTaxId !== '') {
                $snapshot['profile_type']     = 'b2b';
                $snapshot['buyer_identifier'] = $customerTaxId;
            }
        }

        $snapshot['profile_type'] = self::firstNonEmpty($snapshot['profile_type'] ?? '', 'b2c');
        $snapshot['country']      = self::firstNonEmpty($snapshot['country'] ?? '', 'TW');
        $snapshot['description']  = self::firstNonEmpty(
            (string) ($payment['quote_title'] ?? ''),
            (string) ($payment['payment_no'] ?? '')
        );
        // 注意用 ?? ''：客戶不存在時前面的補值分支不會執行，此鍵可能從未被設定。
        $snapshot['buyer_phone']  = InvoicePayloadBuilder::normalizePhone(
            (string) ($snapshot['buyer_phone'] ?? ''),
            (string) $snapshot['country']
        );

        return $snapshot;
    }

    // ───────────────────── 限流 / 熔斷 ─────────────────────

    private function reserveApiSlot(): bool
    {
        $key   = 'einvoice_rate_' . date('YmdHi');
        $count = $this->cache->increment($key, 120);

        return $count <= $this->settings->rateLimitPerMinute();
    }

    private function recordApiSuccess(): void
    {
        $this->cache->delete('einvoice_fail_streak');
    }

    private function recordApiFailure(): void
    {
        $streak = $this->cache->increment('einvoice_fail_streak', 3600);
        if ($streak >= $this->settings->circuitFailureThreshold()) {
            $this->cache->set(
                'einvoice_circuit_open_until',
                (string) (time() + $this->settings->circuitPauseMinutes() * 60),
                $this->settings->circuitPauseMinutes() * 60 + 60
            );
            $this->cache->delete('einvoice_fail_streak');
        }
    }

    private function circuitPauseMessage(): string
    {
        $until = $this->cache->get('einvoice_circuit_open_until');
        if ($until === null) {
            return '';
        }
        $remaining = (int) $until - time();
        if ($remaining <= 0) {
            $this->cache->delete('einvoice_circuit_open_until');
            return '';
        }

        return sprintf('PayNow 發票 API 連續失敗已暫停送出，約 %d 分鐘後自動恢復。', (int) ceil($remaining / 60));
    }

    // ───────────────────────── 工具 ─────────────────────────

    /**
     * 回傳第一個「去除空白後非空」的值。
     *
     * 刻意不用 `?:`：那會把 ' '（純空白）當成有值，導致發票抬頭變成一個空白字元。
     */
    private static function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function encryptSnapshot(array $snapshot): string
    {
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \InvalidArgumentException('無法安全保存電子發票開立快照，已停止送出。');
        }

        try {
            return (new Encryption())->encrypt($json);
        } catch (\Throwable) {
            // 加密失敗代表 APP_KEY 有問題；快照是稽核依據，不可退回明文儲存。
            throw new \InvalidArgumentException('無法加密電子發票開立快照，已停止送出。');
        }
    }

    /**
     * @param array<string,mixed> $response
     */
    private function encodeResponse(array $response): string
    {
        return json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @param array<string,mixed> $detail
     */
    private function audit(int $userId, string $action, int $invoiceId, array $detail): void
    {
        $this->auditLog->log($userId, $action, 'invoice', $invoiceId, $detail);
    }

    /** External side effects outrank observability: an audit outage must not rewrite their outcome. */
    private function auditBestEffort(int $userId, string $action, int $invoiceId, array $detail): void
    {
        try {
            $this->audit($userId, $action, $invoiceId, $detail);
        } catch (\Throwable $error) {
            try {
                error_log('[invoice][audit] ' . $action . ' failed: '
                    . str_replace(["\r", "\n"], ' ', $error->getMessage()));
            } catch (\Throwable) {
            }
        }
    }
}
