<?php

declare(strict_types=1);

namespace YangSheep\CRM\Console;

use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Website\WebsiteRepository;
use YangSheep\CRM\Hosting\HostingRepository;
use YangSheep\CRM\Recurring\RecurringService;
use YangSheep\CRM\Notification\NotificationService;
use YangSheep\CRM\Notification\EmailQueueRepository;
use YangSheep\CRM\Notification\Mailer;
use YangSheep\CRM\Setting\SettingService;
use YangSheep\CRM\AuditLog\AuditLogService;

/**
 * 排程任務核心（對應架構設計 §7.10 統一通知/到期引擎、§7.8/§7.9 週期帳務）。
 *
 * 單一真相：CLI 入口（cli/cron.php）與 token 保護的 URL 觸發（CronController）皆呼叫本 Kernel，
 * 確保兩種觸發方式行為完全一致。所有子命令皆「冪等、可重複跑不出錯」，並回傳處理筆數。
 *
 * 子命令：
 *   expiry    掃 hosting/website：已到期翻 status='expired'；到期前 N 天 queue 到期提醒。
 *   recurring 週期帳單：generateDue（產生到期帳單）+ autoChargeDue（自動扣款/待人工提醒）。
 *   reminders 報價待簽（送出逾期未簽）、款項待收（pending 付款逾期）→ queue 提醒。
 *   mail      處理 email_queue：claim → durable delivery-start → Mailer → owner-CAS finalize。
 *
 * 全部狀態變更寫 audit_logs（actor 0 = cron/系統）。
 */
class Kernel
{
    private SettingService $settings;
    private NotificationService $notifications;
    private RecurringService $recurring;
    private EmailQueueRepository $emailQueue;
    private Mailer $mailer;
    private AuditLogService $auditLog;
    private Database $db;

    /** 到期提醒預設提前天數（無設定時）。 */
    private const DEFAULT_EXPIRY_NOTICE_DAYS = 14;

    /** 報價待簽提醒門檻（送出後幾天未簽 → 提醒）。 */
    private const DEFAULT_QUOTE_PENDING_DAYS = 3;

    /** 款項待收提醒門檻（pending 付款幾天未收 → 提醒）。 */
    private const DEFAULT_PAYMENT_DUE_DAYS = 3;

    /** 寄信批次與重試上限。 */
    private const MAIL_BATCH = 30;
    private const MAIL_MAX_ATTEMPTS = 3;
    private const MAIL_STALE_CLAIM_SECONDS = 600;

    public function __construct(
        ?SettingService $settings = null,
        ?NotificationService $notifications = null,
        ?RecurringService $recurring = null,
        ?Mailer $mailer = null,
        ?EmailQueueRepository $emailQueue = null
    ) {
        $this->settings      = $settings ?? new SettingService();
        $this->notifications = $notifications ?? new NotificationService(null, null, $this->settings);
        $this->recurring     = $recurring ?? new RecurringService($this->notifications, $this->settings);
        $this->emailQueue    = $emailQueue ?? new EmailQueueRepository();
        $this->mailer        = $mailer ?? new Mailer($this->settings);
        $this->auditLog      = new AuditLogService();
        $this->db            = Database::getInstance();
    }

    /**
     * 執行全部子命令（cron run）。
     *
     * @param string|null $today 今日（YYYY-MM-DD）；測試/補跑可指定，否則用系統日。
     * @return array<string, array<string, int>> 各子命令統計
     */
    public function runAll(?string $today = null): array
    {
        $stats = [];
        $failures = [];

        // 每個 idempotent task 都有一次獨立執行機會。不能在第一個錯誤後提早
        // throw，否則持續壞掉的業務 job 會讓 mail 或 retention 永久飢餓。
        $jobs = [
            'expiry'    => fn(): array => $this->expiry($today),
            'recurring' => fn(): array => $this->recurring($today),
            'reminders' => fn(): array => $this->reminders($today),
            'invoice'   => fn(): array => $this->invoice(),
            'mail'      => fn(): array => $this->mail(),
            // 保留期維護仍排最後：它不該擋在寄信與出帳之前。
            'retention' => fn(): array => $this->retention(),
        ];
        foreach ($jobs as $task => $job) {
            try {
                $stats[$task] = $job();
            } catch (\Throwable $e) {
                $failures[] = ['task' => $task, 'error' => $e];
            }
        }

        if ($failures !== []) {
            $this->throwRunAllFailures($failures, $stats);
        }

        return $stats;
    }

    /**
     * High-frequency maintenance: service invoice recovery/retry, then mail.
     *
     * Kept separate from runAll() so the five-minute scheduler does not repeat
     * expiry, recurring, reminders, or retention jobs. A single CronRunner call
     * holds one cron lock and emits one run record for the whole maintenance pass.
     *
     * @return array{invoice: array{released: int, retried: int, succeeded: int, logs_purged: int}, mail: array{claimed: int, sent: int, failed: int}}
     */
    public function maintenance(): array
    {
        $stats = [];
        $deferredFailure = null;

        try {
            $stats['invoice'] = $this->invoice();
        } catch (\Throwable $e) {
            $deferredFailure = ['task' => 'invoice', 'error' => $e];
        }

        $stats['mail'] = $this->mailBeforeRethrow($deferredFailure);

        return $stats;
    }

    // ───────────────────────── invoice ─────────────────────────

    /**
     * 電子發票維護：回收殭屍 claim + 重試到期的失敗發票。
     *
     * 兩者順序不可顛倒：先把「卡在 issuing 的殭屍列」釋放回 failed，
     * 它們才會被接下來的重試掃描撿到。反過來做的話，每次 cron 都會漏掉一輪。
     *
     * @return array{released: int, retried: int, succeeded: int, logs_purged: int}
     */
    public function invoice(): array
    {
        $settings = new \YangSheep\CRM\EInvoice\InvoiceSettings();

        // 🔴 log 清理要在「模組啟用與否」的判斷之外。
        // API log 存的是完整的 request/response JSON —— 沒人清就會無限成長。
        // 而「模組被停用」正是最需要清的情境之一：停用之後不會再有新 log，
        // 但停用前累積的那些如果綁在 enabled() 底下，就永遠不會被清掉。
        // 🔴 【為何要 try/catch】這段被刻意放在 enabled() 之前（見上），
        // 但那讓它變成無條件的 DB 存取：某台部署若尚未套用 047 的建表 migration
        //（發票模組從未啟用，所以沒人補跑），這個 DELETE 會丟 PDOException。
        // 上層會保證 mail 與其他 daily job 仍執行並把本輪標為 failed；這裡仍需局部
        // 隔離，否則 invoice() 會在清 log 處中止，stale claim 回收與 due retry 每輪都跳過。
        //
        // log 清理是維護工作，不是業務流程。它失敗不該連累寄信與出帳。
        $purged = 0;
        try {
            $purged = (new \YangSheep\CRM\EInvoice\InvoiceApiLogRepository())
                ->purgeOlderThan($settings->logRetentionDays());
        } catch (\Throwable $e) {
            error_log('[cron][invoice] API log 清理失敗（不影響其餘排程）：' . $e->getMessage());
        }

        if (!$settings->enabled()) {
            return ['released' => 0, 'retried' => 0, 'succeeded' => 0, 'logs_purged' => $purged];
        }

        $service = new \YangSheep\CRM\EInvoice\InvoiceService($settings);

        $released = $service->releaseStaleClaims();
        $missing  = $service->recoverMissingIntents();
        $retry    = $service->retryDue();

        return [
            'released'    => $released,
            'retried'     => $missing['processed'] + $retry['processed'],
            'succeeded'   => $missing['succeeded'] + $retry['succeeded'],
            'logs_purged' => $purged,
        ];
    }

    /**
     * 保留期維護：清掉超過保留期的執行紀錄與登入嘗試。
     *
     * 🔴 【為何需要這個方法】CronRunner::purgeOlderThan() 與
     * LoginAttemptService::purgeOlderThan() 的 docblock 都寫著「供 cron」，
     * 但全 repo 沒有任何呼叫端 —— 兩張表因此無上限成長。
     * cron_runs 每次觸發插一列並帶 output（1 分鐘一次約 50 萬列／年）；
     * login_attempts 更是每次登入、2FA 挑戰、step-up 再認證都會被掃描的熱表。
     *
     * 「註解說有、實作沒有」在本專案已經出現過數次，這是其中兩例。
     *
     * 每一項都獨立嘗試：單一 purge 失敗不會讓另一項飢餓；全部嘗試後仍會
     * aggregate throw，讓外層 CronRunner 將本輪標成 failed，而非永久假綠。
     *
     * @return array{cron_runs: int, login_attempts: int}
     */
    public function retention(): array
    {
        $out = ['cron_runs' => 0, 'login_attempts' => 0];
        $failures = [];

        try {
            $out['cron_runs'] = (new CronRunner())->purgeOlderThan(30);
        } catch (\Throwable $e) {
            $failures['cron_runs'] = $e;
            error_log('[cron][retention] cron_runs 清理失敗：' . $e->getMessage());
        }

        try {
            $out['login_attempts'] = (new \YangSheep\CRM\Auth\LoginAttemptService())->purgeAllOlderThan(90);
        } catch (\Throwable $e) {
            $failures['login_attempts'] = $e;
            error_log('[cron][retention] login_attempts 清理失敗：' . $e->getMessage());
        }

        if ($failures !== []) {
            $parts = [];
            foreach ($failures as $task => $error) {
                $parts[] = $task . ': ' . $this->oneLineError($error);
            }
            throw new \RuntimeException(
                'retention 清理失敗：' . implode('; ', $parts),
                0,
                reset($failures) ?: null
            );
        }

        return $out;
    }

    // ───────────────────────── expiry ─────────────────────────

    /**
     * 到期掃描：翻轉已到期 status + 到期前提醒。
     *
     * @return array{hosting_expired: int, website_expired: int, hosting_notified: int, website_notified: int}
     */
    public function expiry(?string $today = null): array
    {
        $noticeDays = $this->settingInt('billing', 'recurring_advance_days', self::DEFAULT_EXPIRY_NOTICE_DAYS);
        // 到期提醒採較長視窗（與週期提前產生天數可不同；此處沿用設定的 advance_days 作提前提醒天數，
        // 缺則用 DEFAULT_EXPIRY_NOTICE_DAYS）。
        if ($noticeDays <= 0) {
            $noticeDays = self::DEFAULT_EXPIRY_NOTICE_DAYS;
        }

        $hostingRepo = new HostingRepository();
        $websiteRepo = new WebsiteRepository();

        // 1) 已到期翻 status（以伺服器端 CURDATE 比對；冪等：只翻 active→expired）。
        $hostingExpired = $hostingRepo->markExpired();
        $websiteExpired = $websiteRepo->markExpired();

        if ($hostingExpired > 0) {
            $this->auditLog->log(0, 'cron_hosting_expired', 'hosting', null, ['count' => $hostingExpired], null);
        }
        if ($websiteExpired > 0) {
            $this->auditLog->log(0, 'cron_website_expired', 'website', null, ['count' => $websiteExpired], null);
        }

        // 2) 到期前提醒（即將到期，尚未翻 expired 者）。NotificationService 內建 dedup（30 天視窗）防重發。
        $hostingNotified = 0;
        foreach ($hostingRepo->findExpiringSoon($noticeDays) as $h) {
            $endDate = substr((string) ($h['end_date'] ?? ''), 0, 10);
            $daysLeft = $this->daysBetween($this->today($today), $endDate);
            $id = $this->notifications->hostingExpiring(
                (int) $h['id'],
                $h['customer_name'] ?? null,
                $endDate,
                $daysLeft
            );
            if ($id !== null) {
                $hostingNotified++;
            }
        }

        $websiteNotified = 0;
        foreach ($websiteRepo->findExpiringSoon($noticeDays) as $w) {
            $contractEnd = substr((string) ($w['contract_end'] ?? ''), 0, 10);
            $daysLeft = $this->daysBetween($this->today($today), $contractEnd);
            $id = $this->notifications->websiteExpiring(
                (int) $w['id'],
                $w['customer_name'] ?? null,
                (string) ($w['url'] ?? ''),
                $contractEnd,
                $daysLeft
            );
            if ($id !== null) {
                $websiteNotified++;
            }
        }

        return [
            'hosting_expired'  => $hostingExpired,
            'website_expired'  => $websiteExpired,
            'hosting_notified' => $hostingNotified,
            'website_notified' => $websiteNotified,
        ];
    }

    // ───────────────────────── recurring ─────────────────────────

    /**
     * 週期帳單：產生到期帳單 + 自動扣款（待人工）。
     *
     * @return array{generated: int, gen_processed: int, gen_skipped: int, charge_reminded: int, charge_processed: int}
     */
    public function recurring(?string $today = null): array
    {
        $day = $this->today($today);
        $gen    = $this->recurring->generateDue($day, 0);
        $charge = $this->recurring->autoChargeDue($day, 0);

        if ((int) ($gen['errors'] ?? 0) > 0) {
            // CronRunner and runAll already convert exceptions into failed run
            // records. Keep counts/identities visible so the next tick retries.
            throw new \RuntimeException(sprintf(
                'recurring errors=%d generated=%d processed=%d failed_schedule_ids=%s',
                $gen['errors'], $gen['generated'], $gen['processed'],
                implode(',', $gen['failed_schedule_ids'] ?? [])
            ));
        }

        return [
            'generated'        => $gen['generated'],
            'gen_processed'    => $gen['processed'],
            'gen_skipped'      => $gen['skipped'],
            'charge_reminded'  => $charge['reminded'],
            'charge_processed' => $charge['processed'],
        ];
    }

    // ───────────────────────── reminders ─────────────────────────

    /**
     * 提醒掃描：報價待簽 + 款項待收。
     *
     * @return array{quote_pending: int, payment_due: int}
     */
    public function reminders(?string $today = null): array
    {
        $day = $this->today($today);

        // 報價待簽：status in (sent, viewed) 且 sent_at <= today - N 天（送出後逾期未簽）。
        $quotePendingDays = self::DEFAULT_QUOTE_PENDING_DAYS;
        $quotePending = 0;
        $cutoffQuote = date('Y-m-d', strtotime("{$day} -{$quotePendingDays} days"));
        $quotes = $this->db->fetchAll(
            "SELECT q.id, q.quote_number, q.sent_at,
                    c.display_name AS customer_name
             FROM {prefix}quotes q
             LEFT JOIN {prefix}customers c ON q.customer_id = c.id
             WHERE q.status IN ('sent', 'viewed')
               AND q.sent_at IS NOT NULL
               AND DATE(q.sent_at) <= :cutoff
             ORDER BY q.sent_at ASC, q.id ASC",
            ['cutoff' => $cutoffQuote]
        );
        foreach ($quotes as $q) {
            $daysPending = $this->daysBetween(substr((string) $q['sent_at'], 0, 10), $day);
            $id = $this->notifications->quotePendingSign(
                (int) $q['id'],
                (string) $q['quote_number'],
                $q['customer_name'] ?? null,
                max(1, $daysPending)
            );
            if ($id !== null) {
                $quotePending++;
            }
        }

        // 款項待收：status='pending' 且 created_at <= today - N 天。
        $paymentDueDays = self::DEFAULT_PAYMENT_DUE_DAYS;
        $paymentDue = 0;
        $cutoffPay = date('Y-m-d', strtotime("{$day} -{$paymentDueDays} days"));
        $payments = $this->db->fetchAll(
            "SELECT p.id, p.payment_no, p.amount, p.currency, p.created_at
             FROM {prefix}payments p
             WHERE p.status = 'pending'
               AND DATE(p.created_at) <= :cutoff
             ORDER BY p.created_at ASC, p.id ASC",
            ['cutoff' => $cutoffPay]
        );
        foreach ($payments as $p) {
            $daysPending = $this->daysBetween(substr((string) $p['created_at'], 0, 10), $day);
            $id = $this->notifications->paymentDue(
                (int) $p['id'],
                (string) $p['payment_no'],
                (float) ($p['amount'] ?? 0),
                (string) ($p['currency'] ?? 'TWD'),
                max(1, $daysPending)
            );
            if ($id !== null) {
                $paymentDue++;
            }
        }

        return ['quote_pending' => $quotePending, 'payment_due' => $paymentDue];
    }

    // ───────────────────────── mail ─────────────────────────

    /**
     * 處理寄信佇列：回收 SMTP 前 stale claim → 認領 → durable delivery-start → 寄送。
     *
     * sending 只存在於 SMTP 前，因此 crash 可安全回收。呼叫 Mailer 前先轉 indeterminate；
     * SMTP 可能已接受或 finalize 衝突時保留該狀態，下一輪不自動重寄。
     *
     * @return array{claimed: int, sent: int, failed: int, recovered: int, indeterminate: int}
     */
    public function mail(): array
    {
        $recovered = $this->emailQueue->recoverStaleClaims(
            self::MAIL_STALE_CLAIM_SECONDS,
            self::MAIL_MAX_ATTEMPTS
        );
        $batch = $this->emailQueue->claimBatch(self::MAIL_BATCH, self::MAIL_MAX_ATTEMPTS);

        $sent = 0;
        $failed = 0;
        $indeterminate = 0;
        foreach ($batch as $mail) {
            $id = (int) $mail['id'];
            $claimToken = (string) ($mail['claim_token'] ?? '');
            if (!$this->emailQueue->markDeliveryStarted($id, $claimToken)) {
                $indeterminate++;
                continue;
            }

            try {
                $result = $this->mailer->send(
                    (string) $mail['to_email'],
                    (string) $mail['subject'],
                    (string) ($mail['body_html'] ?? '')
                );
            } catch (\Throwable $e) {
                $this->emailQueue->markIndeterminate(
                    $id,
                    $claimToken,
                    'SMTP 呼叫非預期中斷：' . $this->oneLineError($e)
                );
                $indeterminate++;
                continue;
            }

            if (($result['ok'] ?? false) === true) {
                if ($this->emailQueue->markSent($id, $claimToken)) {
                    $sent++;
                } else {
                    $indeterminate++;
                }
                continue;
            }

            $error = (string) ($result['error'] ?? '未知錯誤');
            if (($result['outcome'] ?? 'indeterminate') === 'retryable') {
                if ($this->emailQueue->markFailed($id, $claimToken, $error, self::MAIL_MAX_ATTEMPTS)) {
                    $failed++;
                } else {
                    $indeterminate++;
                }
            } else {
                $this->emailQueue->markIndeterminate($id, $claimToken, $error);
                $indeterminate++;
            }
        }

        if ($sent > 0 || $failed > 0 || $recovered > 0 || $indeterminate > 0) {
            $this->auditLog->log(0, 'cron_mail_processed', 'email_queue', null,
                [
                    'claimed' => count($batch),
                    'sent' => $sent,
                    'failed' => $failed,
                    'recovered' => $recovered,
                    'indeterminate' => $indeterminate,
                ], null);
        }

        return [
            'claimed' => count($batch),
            'sent' => $sent,
            'failed' => $failed,
            'recovered' => $recovered,
            'indeterminate' => $indeterminate,
        ];
    }

    // ───────────────────────── 內部輔助 ─────────────────────────

    /**
     * 執行 mail；若前置任務失敗，mail 完成後才重拋並把兩邊結果合併到單行訊息。
     *
     * @param null|array{task: string, error: \Throwable} $deferredFailure
     * @return array{claimed: int, sent: int, failed: int, recovered: int, indeterminate: int}
     */
    private function mailBeforeRethrow(?array $deferredFailure): array
    {
        try {
            $mail = $this->mail();
        } catch (\Throwable $mailError) {
            if ($deferredFailure !== null) {
                throw new \RuntimeException(sprintf(
                    '%s 失敗：%s；mail 亦失敗：%s',
                    $deferredFailure['task'],
                    $this->oneLineError($deferredFailure['error']),
                    $this->oneLineError($mailError)
                ), 0, $deferredFailure['error']);
            }

            throw $mailError;
        }

        if ($deferredFailure !== null) {
            throw new \RuntimeException(sprintf(
                '%s 失敗：%s；mail 已執行（claimed=%d sent=%d failed=%d）',
                $deferredFailure['task'],
                $this->oneLineError($deferredFailure['error']),
                (int) ($mail['claimed'] ?? 0),
                (int) ($mail['sent'] ?? 0),
                (int) ($mail['failed'] ?? 0)
            ), 0, $deferredFailure['error']);
        }

        return $mail;
    }

    /**
     * 所有 daily jobs 都已各執行一次後，才以第一個錯誤作 authoritative cause。
     *
     * @param list<array{task: string, error: \Throwable}> $failures
     * @param array<string, array<string, int>> $stats
     * @return never
     */
    private function throwRunAllFailures(array $failures, array $stats): never
    {
        $first = $failures[0];
        $parts = [sprintf(
            '%s 失敗：%s',
            $first['task'],
            $this->oneLineError($first['error'])
        )];

        foreach (array_slice($failures, 1) as $failure) {
            $parts[] = sprintf(
                '%s 亦失敗：%s',
                $failure['task'],
                $this->oneLineError($failure['error'])
            );
        }

        $failedTasks = array_column($failures, 'task');
        if (isset($stats['mail']) && !in_array('mail', $failedTasks, true)) {
            $mail = $stats['mail'];
            $parts[] = sprintf(
                'mail 已執行（claimed=%d sent=%d failed=%d）',
                (int) ($mail['claimed'] ?? 0),
                (int) ($mail['sent'] ?? 0),
                (int) ($mail['failed'] ?? 0)
            );
        }
        if (isset($stats['retention']) && !in_array('retention', $failedTasks, true)) {
            $parts[] = 'retention 已執行';
        }

        throw new \RuntimeException(implode('；', $parts), 0, $first['error']);
    }

    private function oneLineError(\Throwable $error): string
    {
        $message = trim(explode("\n", $error->getMessage())[0]);
        return $message !== '' ? $message : $error::class;
    }

    /**
     * 今日（YYYY-MM-DD）：傳入合法日期則用之，否則系統日。
     */
    private function today(?string $today): string
    {
        $s = trim((string) ($today ?? ''));
        if ($s !== '') {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $s);
            if ($dt !== false && $dt->format('Y-m-d') === $s) {
                return $s;
            }
        }
        return date('Y-m-d');
    }

    /**
     * 兩個日期相差天數（$to - $from，以天計；負值表示 $to 在 $from 之前）。
     *
     * @param string $from YYYY-MM-DD
     * @param string $to   YYYY-MM-DD
     */
    private function daysBetween(string $from, string $to): int
    {
        $f = \DateTimeImmutable::createFromFormat('Y-m-d', $from);
        $t = \DateTimeImmutable::createFromFormat('Y-m-d', $to);
        if ($f === false || $t === false) {
            return 0;
        }
        $f = $f->setTime(0, 0, 0);
        $t = $t->setTime(0, 0, 0);
        return (int) $f->diff($t)->format('%r%a');
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
