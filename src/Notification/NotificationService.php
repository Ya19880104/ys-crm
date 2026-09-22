<?php

declare(strict_types=1);

namespace YangSheep\CRM\Notification;

use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Setting\SettingService;

/**
 * 通知領域服務（對應架構設計 §7.10 統一通知/到期引擎）。
 *
 * 統一入口 notify()：寫一筆 {prefix}notifications；channel 含 email 時，同時 enqueue 一筆
 * {prefix}email_queue（實際寄送走 cron mail 子命令，與本服務解耦）。
 *
 * 提供語意化包裝（quotePendingSign / paymentDue / hostingExpiring / recurringUpcoming …），
 * 各自以簡潔繁體中文模板組標題與內文（含金額/編號/到期日），並內建合理的「冪等去重視窗」，
 * 讓 cron 每日掃描同一筆（如同一張即將到期主機）時，同一週期內只通知一次、不重複洗版。
 *
 * 冪等去重：以 (type, related_type, related_id, 視窗起始時間) 比對既有通知是否已存在。
 * 視窗由各包裝依語意決定（到期提醒以「提前天數」為窗，週期單以「本期」為窗）。
 */
class NotificationService
{
    private NotificationRepository $notifications;
    private EmailQueueRepository $emailQueue;
    private SettingService $settings;
    private Database $db;

    public function __construct(
        ?NotificationRepository $notifications = null,
        ?EmailQueueRepository $emailQueue = null,
        ?SettingService $settings = null
    ) {
        $this->notifications = $notifications ?? new NotificationRepository();
        $this->emailQueue    = $emailQueue ?? new EmailQueueRepository();
        $this->settings      = $settings ?? new SettingService();
        $this->db            = Database::getInstance();
    }

    /**
     * 寫入一筆通知（統一入口）。channel='email' 時同時 enqueue 一封信。
     *
     * @param string      $type          通知語意分類（如 'quote.pending_sign'）
     * @param string      $recipientType 'admin' | 'customer' | 'system'
     * @param int|null    $recipientId   對象 id（admin=user id / customer=customer id；null/0=系統廣播）
     * @param string      $title         通知標題（繁中）
     * @param string      $body          通知內文（繁中；可含金額/編號/到期日）
     * @param string|null $relatedType   關聯來源類型（quote / payment / hosting / website / recurring）
     * @param int|null    $relatedId     關聯來源 id
     * @param array{
     *     channel?: string,
     *     to_email?: string,
     *     dedup_since?: string,
     *     dedup_window_days?: int
     * } $opts
     *   - channel：'in_app'（預設）| 'email'。
     *   - to_email：email channel 的明確收件者；缺則由 recipient 推導（admin→系統通知信箱）。
     *   - dedup_since：去重視窗起始 DATETIME；提供則同 (type+related) 在此之後已有通知就跳過。
     *   - dedup_window_days：以「現在往前 N 天」作為去重視窗（與 dedup_since 擇一；後者優先）。
     *
     * @return int|null 新通知 id；因去重而跳過時回 null。
     */
    public function notify(
        string $type,
        string $recipientType,
        ?int $recipientId,
        string $title,
        string $body,
        ?string $relatedType = null,
        ?int $relatedId = null,
        array $opts = []
    ): ?int {
        $recipientType = in_array($recipientType, ['admin', 'customer', 'system'], true) ? $recipientType : 'admin';
        $emailRequested = ($opts['channel'] ?? 'in_app') === 'email';
        $toEmail = $emailRequested ? $this->resolveRecipientEmail($recipientType, $opts) : '';
        // 沒有合法收件者就明確降為站內通知，不能留下「已走 email」的假紀錄。
        $channel = $emailRequested && $toEmail !== '' ? 'email' : 'in_app';

        $since = $this->resolveDedupSince($opts);
        return $this->db->transaction(function () use (
            $type,
            $recipientType,
            $recipientId,
            $title,
            $body,
            $channel,
            $toEmail,
            $relatedType,
            $relatedId,
            $since
        ): ?int {
            // 去重、站內記錄與 email queue 必須共享同一 commit。否則 queue insert
            // 失敗後，既有 notification 會讓下一輪 dedup 永久吞掉補寄。
            if ($since !== null
                && $this->notifications->existsSince($type, $relatedType, $relatedId, $since)) {
                return null;
            }

            $id = $this->notifications->insert([
                'recipient_type' => $recipientType,
                'recipient_id'   => (int) ($recipientId ?? 0),
                'type'           => $type,
                'title'          => $title,
                'body'           => $body,
                'channel'        => $channel,
                'related_type'   => $relatedType,
                'related_id'     => $relatedId,
                'scheduled_at'   => date('Y-m-d H:i:s'),
            ]);

            if ($channel === 'email') {
                $this->emailQueue->enqueue($toEmail, $title, $this->wrapEmailBody($title, $body));
            }

            return $id;
        });
    }

    // ───────────────────────── 語意化包裝 ─────────────────────────

    /**
     * 報價待簽（送出後逾期未簽 → 提醒我方跟進）。
     */
    public function quotePendingSign(int $quoteId, string $quoteNumber, ?string $customerName, int $daysPending): ?int
    {
        $who = $customerName !== null && $customerName !== '' ? "（{$customerName}）" : '';
        return $this->notify(
            'quote.pending_sign',
            'admin',
            0,
            "報價單待簽提醒：{$quoteNumber}",
            "報價單 {$quoteNumber}{$who} 已送出 {$daysPending} 天仍未簽署，建議主動跟進。",
            'quote',
            $quoteId,
            ['channel' => 'email', 'dedup_window_days' => max(1, $daysPending)]
        );
    }

    /**
     * 報價已簽署（事件通知我方）。
     */
    public function quoteSigned(int $quoteId, string $quoteNumber, string $signerName): ?int
    {
        return $this->notify(
            'quote.signed',
            'admin',
            0,
            "報價單已簽署：{$quoteNumber}",
            "報價單 {$quoteNumber} 已由 {$signerName} 完成線上簽署。",
            'quote',
            $quoteId,
            ['channel' => 'email', 'dedup_window_days' => 1]
        );
    }

    /**
     * 款項已收（付款入帳事件）。
     */
    public function paymentReceived(int $paymentId, string $paymentNo, float $amount, string $currency = 'TWD'): ?int
    {
        $amt = $this->money($amount, $currency);
        return $this->notify(
            'payment.received',
            'admin',
            0,
            "款項已收：{$paymentNo}",
            "付款 {$paymentNo} 已成功入帳，金額 {$amt}。",
            'payment',
            $paymentId,
            ['channel' => 'email', 'dedup_window_days' => 1]
        );
    }

    /**
     * 款項待收（pending 付款逾期未付 → 催款提醒）。
     */
    public function paymentDue(int $paymentId, string $paymentNo, float $amount, string $currency, int $daysPending): ?int
    {
        $amt = $this->money($amount, $currency);
        return $this->notify(
            'payment.due',
            'admin',
            0,
            "款項待收提醒：{$paymentNo}",
            "付款 {$paymentNo}（{$amt}）已建立 {$daysPending} 天仍未收款，建議跟進或催款。",
            'payment',
            $paymentId,
            ['channel' => 'email', 'dedup_window_days' => max(1, $daysPending)]
        );
    }

    /**
     * 主機即將/已到期。
     *
     * @param string $endDate end_date（YYYY-MM-DD）
     * @param int    $daysLeft 距到期天數（<=0 表示已到期）
     */
    public function hostingExpiring(int $hostingId, ?string $customerName, string $endDate, int $daysLeft): ?int
    {
        $who = $customerName !== null && $customerName !== '' ? "{$customerName} 的" : '';
        if ($daysLeft <= 0) {
            $title = '主機已到期';
            $body  = "{$who}主機已於 {$endDate} 到期，請確認續約或處理。";
        } else {
            $title = '主機即將到期';
            $body  = "{$who}主機將於 {$endDate} 到期（剩 {$daysLeft} 天），請提前處理續約。";
        }
        return $this->notify(
            'hosting.expiring',
            'admin',
            0,
            $title,
            $body,
            'hosting',
            $hostingId,
            ['channel' => 'email', 'dedup_window_days' => 30]
        );
    }

    /**
     * 網站合約即將/已到期。
     *
     * @param string $contractEnd contract_end（YYYY-MM-DD）
     * @param int    $daysLeft 距到期天數（<=0 表示已到期）
     */
    public function websiteExpiring(int $websiteId, ?string $customerName, string $url, string $contractEnd, int $daysLeft): ?int
    {
        $who = $customerName !== null && $customerName !== '' ? "{$customerName} 的" : '';
        $site = $url !== '' ? "（{$url}）" : '';
        if ($daysLeft <= 0) {
            $title = '網站合約已到期';
            $body  = "{$who}網站{$site}合約已於 {$contractEnd} 到期，請確認續約。";
        } else {
            $title = '網站合約即將到期';
            $body  = "{$who}網站{$site}合約將於 {$contractEnd} 到期（剩 {$daysLeft} 天），請提前處理。";
        }
        return $this->notify(
            'website.expiring',
            'admin',
            0,
            $title,
            $body,
            'website',
            $websiteId,
            ['channel' => 'email', 'dedup_window_days' => 30]
        );
    }

    /**
     * 週期單即將產生 / 已產生 / 即將扣款 提醒。
     *
     * @param string $phase 'generated'（已產生新帳單）| 'autocharge_manual'（需人工/無法自動扣款）
     */
    public function recurringUpcoming(int $scheduleId, ?string $customerName, string $detail, string $phase = 'generated'): ?int
    {
        $who = $customerName !== null && $customerName !== '' ? "{$customerName} 的" : '';
        if ($phase === 'autocharge_manual') {
            $title = '週期單待人工扣款';
            $body  = "{$who}週期帳單{$detail}已達自動扣款時點，但目前無有效綁卡，請改以人工方式收款。";
            $type  = 'recurring.autocharge_manual';
        } else {
            $title = '週期單已產生';
            $body  = "{$who}週期帳單{$detail}已自動產生，請留意後續收款。";
            $type  = 'recurring.generated';
        }
        return $this->notify(
            $type,
            'admin',
            0,
            $title,
            $body,
            'recurring',
            $scheduleId,
            ['channel' => 'email', 'dedup_window_days' => 1]
        );
    }

    // ───────────────────────── 內部輔助 ─────────────────────────

    /**
     * 解析去重視窗起始時間：dedup_since 優先；否則 dedup_window_days → 現在往前 N 天；皆無 → null（不去重）。
     */
    private function resolveDedupSince(array $opts): ?string
    {
        if (isset($opts['dedup_since']) && $opts['dedup_since'] !== '') {
            return (string) $opts['dedup_since'];
        }
        if (isset($opts['dedup_window_days'])) {
            $days = max(0, (int) $opts['dedup_window_days']);
            return date('Y-m-d H:i:s', strtotime("-{$days} days"));
        }
        return null;
    }

    /**
     * 推導 email channel 的收件者信箱。
     * opts['to_email'] 優先；admin/system → 系統通知信箱（notification.notify_email
     * 或 company.company_email）；customer 無明確 to_email 時回空字串（站內通知仍會寫入）。
     */
    private function resolveRecipientEmail(string $recipientType, array $opts): string
    {
        $explicit = trim((string) ($opts['to_email'] ?? ''));
        if ($explicit !== '' && filter_var($explicit, FILTER_VALIDATE_EMAIL)) {
            return $explicit;
        }

        if ($recipientType === 'admin' || $recipientType === 'system') {
            $notify = trim((string) ($this->settings->get('notification', 'notify_email') ?? ''));
            if ($notify !== '' && filter_var($notify, FILTER_VALIDATE_EMAIL)) {
                return $notify;
            }
            $company = trim((string) ($this->settings->get('company', 'company_email') ?? ''));
            if ($company !== '' && filter_var($company, FILTER_VALIDATE_EMAIL)) {
                return $company;
            }
        }

        return '';
    }

    /**
     * 將純通知內文包成簡潔 HTML email（品牌深藍、無綠色）。
     */
    private function wrapEmailBody(string $title, string $body): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeBody  = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');

        return '<div style="font-family:-apple-system,\'Segoe UI\',sans-serif;max-width:560px;margin:0 auto;'
             . 'border:1px solid #E2E8F0;border-radius:12px;overflow:hidden;">'
             . '<div style="background:#1E40AF;color:#FFFFFF;padding:16px 20px;font-size:16px;font-weight:bold;">'
             . $safeTitle . '</div>'
             . '<div style="padding:20px;color:#1E293B;font-size:14px;line-height:1.7;">' . $safeBody . '</div>'
             . '<div style="padding:12px 20px;background:#F8FAFC;color:#64748B;font-size:12px;border-top:1px solid #E2E8F0;">'
             . 'YANGSHEEP DESIGN · YS CRM 系統通知</div>'
             . '</div>';
    }

    /**
     * 金額格式（與報價/付款顯示一致：幣別 + 千分位整數）。
     */
    private function money(float $amount, string $currency): string
    {
        return $currency . ' ' . number_format($amount, 0);
    }
}
