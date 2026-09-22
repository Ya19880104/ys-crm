<?php

declare(strict_types=1);

namespace YangSheep\CRM\Recurring;

use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Quote\QuoteRepository;
use YangSheep\CRM\Quote\QuoteItemRepository;
use YangSheep\CRM\Quote\QuoteService;
use YangSheep\CRM\Payment\PaymentRepository;
use YangSheep\CRM\Portal\CustomerPaymentMethodRepository;
use YangSheep\CRM\Notification\NotificationService;
use YangSheep\CRM\Setting\SettingService;
use YangSheep\CRM\AuditLog\AuditLogService;

/**
 * 週期帳務領域服務（對應架構設計 §7.8 週期單、§7.9 付款系統、§5.6 recurring_schedules）。
 *
 * 三個核心動作（皆由 cron recurring 子命令驅動，亦可後台「立即產生」觸發）：
 *   - createFromQuote：由一張報價建立週期排程。
 *   - generateDue：產生到期帳單（複製來源報價明細 → 新報價 + pending 付款 → 推進 next_run_at）。
 *   - autoChargeDue：對 auto_card 排程的 pending 付款嘗試自動扣款（目前無真實綁卡 → 標待人工 + 提醒）。
 *
 * Zero Trust / 杜絕重複出帳（§6）：
 *   - 每筆排程於交易內 SELECT ... FOR UPDATE 鎖定（findByIdForUpdate）。
 *   - 冪等三重防線：
 *       (a) 期間鍵：以「本次消耗的 next_run_at」為期間識別，付款 idempotency_key =
 *           recurring-{scheduleId}-{period}，PaymentRepository 冪等 insert 不重複建單。
 *       (b) last_generated_at：交易內再檢查，若 >= 期間日則本期已產生，跳過。
 *       (c) 每次 generateDue 對單一排程只產生「一個期間」，避免同日 runaway。
 *   - 金額：複製來源報價明細後由 QuoteService 伺服器端重算（不信任既有 total 欄位被竄改）。
 *   - 全程 audit（actor 0 = cron/系統，後台立即產生時帶管理員 id）。
 */
class RecurringService
{
    private RecurringRepository $schedules;
    private QuoteRepository $quotes;
    private QuoteItemRepository $quoteItems;
    private QuoteService $quoteService;
    private PaymentRepository $payments;
    private CustomerPaymentMethodRepository $paymentMethods;
    private NotificationService $notifications;
    private SettingService $settings;
    private AuditLogService $auditLog;
    private Database $db;

    /** 合法週期單位。 */
    public const INTERVAL_UNITS = ['day', 'month', 'year'];

    /** 合法付款模式。 */
    public const PAYMENT_MODES = ['auto_card', 'manual_atm'];

    /** 全域預設（settings 缺漏時的 fallback；對齊 SettingController billing 群組 help 文字）。 */
    private const DEFAULT_ADVANCE_DAYS = 7;
    private const DEFAULT_AUTOCHARGE_AFTER_DAYS = 3;

    public function __construct(
        ?NotificationService $notifications = null,
        ?SettingService $settings = null
    ) {
        $this->schedules      = new RecurringRepository();
        $this->quotes         = new QuoteRepository();
        $this->quoteItems     = new QuoteItemRepository();
        $this->quoteService   = new QuoteService();
        $this->payments       = new PaymentRepository();
        $this->paymentMethods = new CustomerPaymentMethodRepository();
        $this->settings       = $settings ?? new SettingService();
        $this->notifications = $notifications ?? new NotificationService(null, null, $this->settings);
        $this->auditLog      = new AuditLogService();
        $this->db            = Database::getInstance();
    }

    // ───────────────────────── 查詢 ─────────────────────────

    /**
     * @param array{is_active?: string, customer_id?: int} $filters
     * @return array{items: array, total: int}
     */
    public function findAll(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        return $this->schedules->findAll($page, $perPage, $filters);
    }

    public function findById(int $id): ?array
    {
        return $this->schedules->findById($id);
    }

    // ───────────────────────── 建立排程 ─────────────────────────

    /**
     * 由一張報價建立週期排程。
     *
     * @param int $quoteId 來源報價 id（其明細將被複製產生後續帳單）
     * @param array{
     *     interval_unit?: string,
     *     interval_value?: int,
     *     first_run_at?: string,
     *     advance_generate_days?: int|null,
     *     auto_charge_after_days?: int|null,
     *     payment_mode?: string,
     *     payment_method_id?: int|null,
     *     actor_id?: int
     * } $params
     * @return int 新排程 id
     * @throws \RuntimeException 報價不存在
     */
    public function createFromQuote(int $quoteId, array $params): int
    {
        // 🔴🔴 整段包進交易並鎖 quote 列，杜絕同一 quote 建出多個 active schedule。
        // 不鎖的後果：雙擊、audit 寫入失敗重送、或並行 API 呼叫各自 INSERT，
        // 留下兩個 active schedule → 各自產生週期帳單、pending payment 與通知。
        return $this->db->transaction(function () use ($quoteId, $params): int {
            // FOR UPDATE 鎖 quote 列，序列化同一 quote 的排程建立
            $quote = $this->db->fetch(
                "SELECT * FROM {prefix}quotes WHERE id = :id FOR UPDATE",
                ['id' => $quoteId]
            );
            if ($quote === null) {
                throw new \RuntimeException('來源報價單不存在。');
            }

            // 冪等：已有 active schedule → 回傳既有 id，不重複建立
            $existingId = $this->db->fetchColumn(
                "SELECT id FROM {prefix}recurring_schedules WHERE quote_id = :qid AND is_active = 1 LIMIT 1",
                ['qid' => $quoteId]
            );
            if ($existingId !== null && $existingId !== false && $existingId !== '') {
                return (int) $existingId;
            }

            $unit  = in_array($params['interval_unit'] ?? '', self::INTERVAL_UNITS, true)
                        ? $params['interval_unit'] : 'month';
            $value = max(1, (int) ($params['interval_value'] ?? 1));
            $mode  = in_array($params['payment_mode'] ?? '', self::PAYMENT_MODES, true)
                        ? $params['payment_mode'] : 'manual_atm';

            $firstRun = $this->normalizeDate($params['first_run_at'] ?? null) ?? date('Y-m-d');

            $methodId = isset($params['payment_method_id']) && (int) $params['payment_method_id'] > 0
                        ? (int) $params['payment_method_id'] : null;

            $advance = $this->nullableNonNegInt($params['advance_generate_days'] ?? null);
            $charge  = $this->nullableNonNegInt($params['auto_charge_after_days'] ?? null);

            $scheduleId = $this->schedules->insert([
                'quote_id'               => $quoteId,
                'customer_id'            => $quote['customer_id'] ?? null,
                'interval_unit'          => $unit,
                'interval_value'         => $value,
                'next_run_at'            => $firstRun,
                // 使用者指定的首次出帳日就是永久錨點（月底 31 之後才不會漂移成 28）。
                'billing_anchor_day'     => self::anchorDayFromDate($firstRun),
                'advance_generate_days'  => $advance,
                'auto_charge_after_days' => $charge,
                'payment_mode'           => $mode,
                'payment_method_id'      => $methodId,
                'is_active'              => 1,
            ]);

            $this->quotes->update($quoteId, [
                'is_recurring'          => 1,
                'recurring_schedule_id' => $scheduleId,
            ]);

            $this->auditLog->log(
                (int) ($params['actor_id'] ?? 0),
                'recurring_schedule_created',
                'recurring',
                $scheduleId,
                ['quote_id' => $quoteId, 'interval' => "{$value} {$unit}", 'payment_mode' => $mode],
                null
            );

            return $scheduleId;
        });
    }

    // ───────────────────────── 產生到期帳單 ─────────────────────────

    /**
     * 產生所有到期排程的帳單。
     *
     * 找 is_active=1 且 next_run_at <= today + advance 的排程；逐筆於交易內冪等產生
     * 一張新報價（複製來源明細，走 QuoteService 重算金額）+ 一筆 pending 付款，
     * 推進 next_run_at，更新 last_generated_at，並 queue「週期單已產生」通知。
     *
     * @param string $today  今日（YYYY-MM-DD）；測試/補跑可指定
     * @param int    $actorId 觸發者（cron=0；後台立即產生帶管理員 id）
     * @return array{processed: int, generated: int, skipped: int, errors: int, failed_schedule_ids: list<int>} 統計
     */
    public function generateDue(string $today, int $actorId = 0): array
    {
        $today = $this->normalizeDate($today) ?? date('Y-m-d');
        $defaultAdvance = $this->settingInt('billing', 'recurring_advance_days', self::DEFAULT_ADVANCE_DAYS);

        $ids = $this->schedules->findDueIds($today, $defaultAdvance);

        $generated = 0;
        $skipped   = 0;
        $errors    = 0;
        $failedScheduleIds = [];
        foreach ($ids as $scheduleId) {
            // 🔴🔴 per-schedule 例外隔離：一筆排程的失敗不得餓死後續排程。
            // 沒有這道 try/catch，第一筆的通知/enqueue 持續拋錯會 rollback 整個
            // transaction，例外傳播出 foreach → 後續排程永遠沒有執行機會。
            try {
                $result = $this->generateOne($scheduleId, $today, $actorId);
                if ($result === 'generated') {
                    $generated++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $errors++;
                $failedScheduleIds[] = $scheduleId;
                try {
                    $this->auditLog->log(
                        $actorId,
                        'recurring_generate_error',
                        'recurring',
                        $scheduleId,
                        ['error_class' => get_class($e)],
                        null
                    );
                } catch (\Throwable) {
                    // Error reporting must not replace the business failure or
                    // prevent the remaining schedules from getting their turn.
                    try {
                        error_log(sprintf('[recurring] schedule_id=%d generation_failed error_class=%s audit_failed=1', $scheduleId, get_class($e)));
                    } catch (\Throwable) {
                        // The returned aggregate remains authoritative.
                    }
                }
            }
        }

        return ['processed' => count($ids), 'generated' => $generated, 'skipped' => $skipped, 'errors' => $errors, 'failed_schedule_ids' => $failedScheduleIds];
    }

    /**
     * 對單一排程冪等產生一期帳單。於交易內完成（FOR UPDATE 鎖排程列）。
     *
     * @return string 'generated' | 'skipped'
     */
    public function generateOne(int $scheduleId, string $today, int $actorId = 0): string
    {
        $today = $this->normalizeDate($today) ?? date('Y-m-d');

        // 在同一交易內鎖列、建單、推進排程並持久化通知意圖。
        // NotificationService 會以 nested transaction/savepoint 寫 notification + email queue；
        // 任一步失敗都必須讓本期 business state 一併 rollback，下一輪才能安全重試。
        $outcome = $this->db->transaction(function () use ($scheduleId, $today, $actorId): array {
            $schedule = $this->schedules->findByIdForUpdate($scheduleId);
            if ($schedule === null || (int) ($schedule['is_active'] ?? 0) !== 1) {
                return ['status' => 'skipped'];
            }

            $quoteId = (int) ($schedule['quote_id'] ?? 0);
            if ($quoteId <= 0) {
                return ['status' => 'skipped']; // 來源報價已刪除。
            }

            // 本期識別 = 本次消耗的 next_run_at。
            $period = (string) $schedule['next_run_at'];

            // 冪等 (b)：last_generated_at 已 >= 本期日 → 本期已產生，跳過。
            $lastGen = (string) ($schedule['last_generated_at'] ?? '');
            if ($lastGen !== '' && $lastGen >= $period) {
                return ['status' => 'skipped'];
            }

            // 到期把關 (c)：本期（next_run_at）尚未到產生時點（含提前產生天數）→ 跳過。
            // 與 findDueIds 用相同的 row override / 全域 fallback，確保 cron 選中的排程必能通過（不會餓死），
            // 同時讓「立即產生」手動鈕不會連點預支未來多期（honor「若本期已產生則略過」）。
            $advance = $schedule['advance_generate_days'] !== null
                ? max(0, (int) $schedule['advance_generate_days'])
                : $this->settingInt('billing', 'recurring_advance_days', self::DEFAULT_ADVANCE_DAYS);
            $dueThreshold = date('Y-m-d', (int) strtotime($today . ' +' . $advance . ' days'));
            if ($period > $dueThreshold) {
                return ['status' => 'skipped'];
            }

            $sourceQuote = $this->quotes->findById($quoteId);
            if ($sourceQuote === null) {
                return ['status' => 'skipped'];
            }
            $sourceItems = $this->quoteItems->findByQuote($quoteId);

            // 建立新報價（複製明細，QuoteService 伺服器端重算金額）。
            $newQuoteId = $this->createRecurringQuote($schedule, $sourceQuote, $sourceItems, $period, $actorId);

            // 建立 pending 付款（冪等鍵 = recurring-{scheduleId}-{period}）。
            $idempotencyKey = sprintf('recurring-%d-%s', $scheduleId, $period);
            $newQuote = $this->quotes->findById($newQuoteId);
            $amount = (int) round((float) ($newQuote['total'] ?? 0));
            $paymentNo = null;
            if ($amount > 0) {
                $paymentNo = $this->createPendingPayment(
                    $newQuoteId,
                    (int) ($schedule['customer_id'] ?? 0) ?: null,
                    $amount,
                    (string) ($newQuote['currency'] ?? 'TWD'),
                    $idempotencyKey
                );
            }

            // 推進 next_run_at（依 interval）+ 更新 last_generated_at。
            // 錨點必須跨期恆定：傳 null（歷史資料未設）才退回以本期日期為準的舊行為。
            //
            // ⚠️ 「欄位不存在」（migration 058 未執行）與「欄位為 NULL」（歷史資料未回填）
            // 在這裡都會得到 null，兩者都退回舊行為。前者是部署缺失、後者是預期狀態，
            // 但都**不能**讓出帳中斷 —— 排程停擺的損害大於日期漂移。故此處僅記錄，不阻斷。
            $anchorMissing = !array_key_exists('billing_anchor_day', $schedule);
            $anchorDay = !$anchorMissing && $schedule['billing_anchor_day'] !== null
                            ? (int) $schedule['billing_anchor_day'] : null;
            if ($anchorMissing) {
                // 沒有這一行，058 未執行時整個 F07 修正會靜默失效而沒有任何跡象。
                $this->auditLog->log(0, 'recurring_anchor_column_missing', 'recurring', $scheduleId, [
                    'hint' => 'migration 058 尚未執行，出帳日仍會逐期漂移',
                ], null);
            }
            $nextRun = $this->advanceDate($period, (string) $schedule['interval_unit'], (int) $schedule['interval_value'], $anchorDay);
            $this->schedules->update($scheduleId, [
                'next_run_at'       => $nextRun,
                'last_generated_at' => $period,
            ]);

            $this->auditLog->log(
                $actorId,
                'recurring_generated',
                'recurring',
                $scheduleId,
                [
                    'period'        => $period,
                    'new_quote_id'  => $newQuoteId,
                    'payment_no'    => $paymentNo,
                    'amount'        => $amount,
                    'next_run_at'   => $nextRun,
                ],
                null
            );

            $detail = sprintf('（%s，%s %s）',
                (string) ($newQuote['quote_number'] ?? ''),
                (string) ($newQuote['currency'] ?? 'TWD'),
                number_format((float) $amount, 0)
            );
            $this->notifications->recurringUpcoming(
                $scheduleId,
                $this->scheduleCustomerName($scheduleId),
                $detail,
                'generated'
            );

            return [
                'status'        => 'generated',
            ];
        });

        if (($outcome['status'] ?? '') !== 'generated') {
            return 'skipped';
        }

        return 'generated';
    }

    // ───────────────────────── 自動扣款 ─────────────────────────

    /**
     * 對到達自動扣款時點的 auto_card 排程之 pending 付款嘗試扣款。
     *
     * 目前無真實綁卡（payment_method_id 恆 null，卡片 token 屬 P4-2 portal）：
     *   → 不真正扣款，標記「待人工/無法自動扣款」並 queue 純提醒通知，不阻斷流程。
     * 接點：portal 卡片管理上線後，於此呼叫 provider->chargeWithToken 真正扣款。
     *
     * 「到達自動扣款時點」判斷：該排程最近一筆 pending 付款的建立日 + auto_charge_after_days <= today。
     *
     * @param string $today
     * @param int    $actorId
     * @return array{processed: int, reminded: int, charged: int} 統計
     */
    public function autoChargeDue(string $today, int $actorId = 0): array
    {
        $today = $this->normalizeDate($today) ?? date('Y-m-d');
        $defaultAfter = $this->settingInt('billing', 'recurring_autocharge_after_days', self::DEFAULT_AUTOCHARGE_AFTER_DAYS);

        $ids = $this->schedules->findAutoChargeScheduleIds();

        $reminded = 0;
        $charged  = 0;
        foreach ($ids as $scheduleId) {
            $schedule = $this->schedules->findById($scheduleId);
            if ($schedule === null) {
                continue;
            }
            $quoteId = (int) ($schedule['quote_id'] ?? 0);
            if ($quoteId <= 0) {
                continue;
            }

            // 找該排程來源報價最新一筆 pending 付款。
            // 注意：週期單產生的新報價各自有 payment；以「最新 pending 付款」代表待扣款的當期帳單。
            $payment = $this->latestPendingPaymentForSchedule($scheduleId);
            if ($payment === null) {
                continue; // 無待扣款帳單。
            }

            // 是否到達自動扣款時點：付款建立日 + after_days <= today。
            $afterDays = $schedule['auto_charge_after_days'] !== null
                        ? (int) $schedule['auto_charge_after_days'] : $defaultAfter;
            $createdDate = substr((string) ($payment['created_at'] ?? ''), 0, 10);
            if ($createdDate === '') {
                continue;
            }
            $chargeDate = $this->advanceDate($createdDate, 'day', $afterDays);
            if ($chargeDate > $today) {
                continue; // 還沒到扣款時點。
            }

            // 解析可用的卡片 token 來源（P4-2 客戶卡片）：
            //   1) 排程明確指定的 payment_method_id（優先）。
            //   2) 否則退而取「該排程客戶」的預設有效卡（customer_payment_methods）。
            // 兩者皆無 → 視為無綁卡，走待人工提醒。
            $paymentMethodId = (int) ($schedule['payment_method_id'] ?? 0);
            $scheduleCustomerId = (int) ($schedule['customer_id'] ?? 0);
            if ($paymentMethodId <= 0 && $scheduleCustomerId > 0) {
                // 退而取客戶預設卡（customer_payment_methods）。防呆：表不存在/查詢失敗時
                // 一律視為無卡（degrade gracefully，不阻斷出帳提醒流程）。
                try {
                    $defaultCard = $this->paymentMethods->findDefaultByCustomer($scheduleCustomerId);
                    if ($defaultCard !== null) {
                        $paymentMethodId = (int) $defaultCard['id'];
                    }
                } catch (\Throwable) {
                    // 卡片表不可用 → 視為無卡。
                }
            }
            $hasCard = $paymentMethodId > 0;

            if (!$hasCard) {
                // 無綁卡 → 待人工 + 提醒（冪等：recurringUpcoming 內建 dedup_window 1 天）。
                $detail = sprintf('（%s）', (string) ($payment['payment_no'] ?? ''));
                $this->notifications->recurringUpcoming(
                    $scheduleId,
                    $schedule['customer_name'] ?? null,
                    $detail,
                    'autocharge_manual'
                );
                $this->auditLog->log(
                    $actorId,
                    'recurring_autocharge_manual',
                    'recurring',
                    $scheduleId,
                    ['payment_no' => $payment['payment_no'] ?? '', 'reason' => 'no_payment_method'],
                    null
                );
                $reminded++;
                continue;
            }

            // 有綁卡（排程指定或客戶預設卡）：真正自動扣款留接點（待金流 vault tokenize）。
            // 目前一律走待人工，避免假裝扣款；卡片 token 已可由下列接點取回：
            //   $token = (new CustomerPaymentMethodService())->decryptToken($paymentMethodId, $scheduleCustomerId);
            //   $provider = (new PaymentProviderRegistry())->get($payment['provider']);
            //   $result = $provider->chargeWithToken($payment, $token);
            //   if ($result['ok']) { $paymentService->confirmPaid(...); $charged++; }
            $this->auditLog->log(
                $actorId,
                'recurring_autocharge_pending_integration',
                'recurring',
                $scheduleId,
                [
                    'payment_no'        => $payment['payment_no'] ?? '',
                    'payment_method_id' => $paymentMethodId,
                    'note'              => '綁卡自動扣款待金流 vault tokenize 整合（卡片來源：客戶儲存卡片）',
                ],
                null
            );
            $reminded++;
        }

        return ['processed' => count($ids), 'reminded' => $reminded, 'charged' => $charged];
    }

    // ───────────────────────── 內部輔助 ─────────────────────────

    /**
     * 建立週期帳單的新報價（複製來源報價主檔關鍵欄位 + 明細），標記 is_recurring。
     * 走 QuoteService::createQuote（伺服器端重算金額、產生新編號/token）。
     *
     * @return int 新報價 id
     */
    private function createRecurringQuote(array $schedule, array $sourceQuote, array $sourceItems, string $period, int $actorId): int
    {
        // 組明細為 createQuote 期望的格式（name/qty/unit/unit_price/description）。
        $items = [];
        foreach ($sourceItems as $it) {
            $items[] = [
                'name'        => (string) ($it['name'] ?? ''),
                'description' => (string) ($it['description'] ?? ''),
                'qty'         => (float) ($it['qty'] ?? 0),
                'unit'        => (string) ($it['unit'] ?? ''),
                'unit_price'  => (float) ($it['unit_price'] ?? 0),
            ];
        }

        $title = (string) ($sourceQuote['title'] ?? '週期帳單') . '（週期 ' . $period . '）';

        $data = [
            'customer_id'     => $sourceQuote['customer_id'] ?? null,
            'title'           => mb_substr($title, 0, 255),
            'visibility'      => 'customer_only', // 週期帳單預設限客戶可見（保守）
            'tax_rate'        => (float) ($sourceQuote['tax_rate'] ?? 0),
            'valid_until'     => null,
            'terms'           => (string) ($sourceQuote['terms'] ?? ''),
            'notes'           => (string) ($sourceQuote['notes'] ?? ''),
            'payment_enabled' => 1, // 週期帳單啟用付款
            'send_now'        => true, // 直接視為已送出（待客戶付款）
            'created_by'      => $actorId > 0 ? $actorId : null,
        ];

        $newQuoteId = $this->quoteService->createQuote($data, $items);

        // 標記為週期單 + 關聯排程。
        $this->quotes->update($newQuoteId, [
            'is_recurring'          => 1,
            'recurring_schedule_id' => (int) $schedule['id'],
        ]);

        return $newQuoteId;
    }

    /**
     * 建立一筆 pending 付款（冪等）。不走 checkout（週期單階段僅建單，付款導引/扣款另行）。
     * 須於交易內呼叫（payment_no 產生需 FOR UPDATE 鎖當年序號）。
     *
     * @return string payment_no
     */
    private function createPendingPayment(int $quoteId, ?int $customerId, int $amount, string $currency, string $idempotencyKey): string
    {
        // 已存在同冪等鍵 → 回既有 payment_no（冪等，不重複建單/不重複出帳）。
        $existing = $this->payments->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return (string) $existing['payment_no'];
        }

        $year = (int) date('Y');
        $next = $this->payments->maxSequenceForYearForUpdate($year) + 1;
        $paymentNo = sprintf('PAY-%04d-%04d', $year, $next);

        $payment = $this->payments->insertIdempotent([
            'payment_no'      => $paymentNo,
            'quote_id'        => $quoteId,
            'customer_id'     => $customerId,
            'provider'        => 'sandbox', // 待付款；實際 provider 於發動付款/扣款時決定
            'amount'          => $amount,
            'currency'        => $currency,
            'status'          => 'pending',
            'idempotency_key' => $idempotencyKey,
        ]);

        return (string) $payment['payment_no'];
    }

    /**
     * 取得排程的客戶名（交易外查詢，供通知文案）。
     */
    private function scheduleCustomerName(int $scheduleId): ?string
    {
        $row = $this->schedules->findById($scheduleId);
        $name = $row['customer_name'] ?? null;
        return ($name !== null && $name !== '') ? (string) $name : null;
    }

    /**
     * 取得某排程「最新一筆 pending 付款」（autoChargeDue 用）。
     * 以排程關聯的所有週期報價（recurring_schedule_id = scheduleId）之付款為範圍。
     */
    private function latestPendingPaymentForSchedule(int $scheduleId): ?array
    {
        return $this->db->fetch(
            "SELECT p.*
             FROM {prefix}payments p
             INNER JOIN {prefix}quotes q ON p.quote_id = q.id
             WHERE q.recurring_schedule_id = :sid
               AND p.status = 'pending'
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT 1",
            ['sid' => $scheduleId]
        );
    }

    /**
     * 依 interval 推進日期。
     *
     * @param string   $date      YYYY-MM-DD
     * @param string   $unit      day|month|year
     * @param int      $value     正整數
     * @param int|null $anchorDay 永久出帳日錨點（1–31）。null＝未知，退回以當次日期為準的舊行為。
     * @return string 推進後 YYYY-MM-DD
     */
    public function advanceDate(string $date, string $unit, int $value, ?int $anchorDay = null): string
    {
        $value = max(1, $value);
        $unit  = in_array($unit, self::INTERVAL_UNITS, true) ? $unit : 'month';

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date) ?: new \DateTimeImmutable($date);
        // 正規化到當日 00:00（避免 createFromFormat 帶入當下時刻造成月加減誤差）。
        $dt = $dt->setTime(0, 0, 0);

        if ($unit === 'day') {
            return $dt->add(new \DateInterval("P{$value}D"))->format('Y-m-d');
        }

        // 🔴 截斷要以「永久錨點」為準，不能以上一次截斷後的日期為準，
        // 否則 1/31 → 2/28 之後 31 就永久遺失（1/31 → 2/28 → 3/28）。
        // 有錨點時：1/31 → 2/28 → 3/31 → 4/30 → 5/31，每期各自獨立截斷。
        $anchor = ($anchorDay !== null && $anchorDay >= 1 && $anchorDay <= 31)
                    ? $anchorDay
                    : (int) $dt->format('d');

        // 先退到當月 1 號再加月份，PHP 才不會把 2/31 溢位成 3/3。
        $first = $dt->setDate((int) $dt->format('Y'), (int) $dt->format('m'), 1);
        $target = $first->add(new \DateInterval($unit === 'year' ? "P{$value}Y" : "P{$value}M"));
        return $target->setDate(
            (int) $target->format('Y'),
            (int) $target->format('m'),
            min($anchor, (int) $target->format('t'))
        )->format('Y-m-d');
    }

    /**
     * 管理員送出的新日期是否構成「改期」（＝新的出帳意圖，錨點應重設）。
     *
     * 🔴 編輯表單一律預填目前的 next_run_at，所以只改其他欄位也會把目前值原樣回送。
     * 若無條件重設錨點，1/31 的排程推進成 2/28 之後，任何一次無關的編輯都會把錨點
     * 改寫成 28 —— F07 漂移就這樣被靜默且永久地復活。
     *
     * 抽成純函式是為了可測：這個判斷若只存在於 controller 裡，就只能靠原始碼字串
     * 斷言去釘，那不是行為證明。
     */
    public static function isBillingDateChanged(string $newDate, ?string $currentDate): bool
    {
        return $newDate !== substr((string) $currentDate, 0, 10);
    }

    /**
     * 由日期取出出帳日錨點（1–31）；無法解析時回 null（呼叫端退回舊行為）。
     */
    public static function anchorDayFromDate(?string $date): ?int
    {
        $raw = trim((string) $date);
        $dt  = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);

        // 🔴 createFromFormat 是寬鬆的：'2026-02-31' 會滾成 3/03（回 3）、
        // '2026-04-31' 回 1、'2026-13-01' 回 1。少了 round-trip 檢查，
        // docblock 宣稱的「無法解析回 null」是假的，而錯誤的日反而會被當成有效錨點。
        // 目前兩個呼叫端都自己驗過，但這是 public static —— 契約要在函式裡成立。
        if ($dt === false || $dt->format('Y-m-d') !== $raw) {
            return null;
        }

        return (int) $dt->format('d');
    }

    /**
     * 正規化日期輸入：合法 YYYY-MM-DD 回傳之，否則 null。
     */
    private function normalizeDate(mixed $date): ?string
    {
        $s = trim((string) ($date ?? ''));
        if ($s === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $s);
        if ($dt === false) {
            return null;
        }
        // 確認格式回放一致（拒絕 2026-13-40 之類）。
        return $dt->format('Y-m-d') === $s ? $s : null;
    }

    /**
     * 可空非負整數：null/空 → null；否則取非負整數。
     */
    private function nullableNonNegInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return max(0, (int) $value);
    }

    /**
     * 讀整數設定（缺漏/非數字 → fallback）。
     */
    private function settingInt(string $group, string $key, int $fallback): int
    {
        $raw = $this->settings->get($group, $key);
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return $fallback;
        }
        return max(0, (int) $raw);
    }
}
