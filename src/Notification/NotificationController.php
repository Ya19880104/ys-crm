<?php

declare(strict_types=1);

namespace YangSheep\CRM\Notification;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\AuditLog\AuditLogService;
use YangSheep\CRM\Role\RoleService;

/**
 * 通知記錄後台控制器（對應架構設計 §7.10）。
 *
 * 架構與 PaymentController 一致：CSRF + 細粒度權限 + AuditLog + redirectWith/backWithError。
 * 顯示站內通知 + 寄信佇列；可「重送」失敗信、標記通知已讀。
 *
 * 權限：群組進入需 notification.view（routes 設定）。重送失敗信於本層再檢查 notification.view
 *（重送屬於同一檢視權限的維運動作；如需更嚴格可另設 notification.manage）。
 */
class NotificationController extends Controller
{
    private NotificationRepository $notifications;
    private EmailQueueRepository $emailQueue;
    private AuditLogService $auditLog;
    private RoleService $roleService;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->notifications = new NotificationRepository();
        $this->emailQueue    = new EmailQueueRepository();
        $this->auditLog      = new AuditLogService();
        $this->roleService   = new RoleService();
    }

    /**
     * 通知記錄列表（站內通知 + 寄信佇列，雙分頁以 tab 切換）。
     */
    public function index(): void
    {
        $tab = $this->request->query('tab', 'notifications') === 'emails' ? 'emails' : 'notifications';
        $page = max(1, (int) $this->request->query('page', '1'));
        $perPage = 20;

        if ($tab === 'emails') {
            $emailFilters = ['status' => (string) $this->request->query('status', '')];
            $emails = $this->emailQueue->findAll($page, $perPage, $emailFilters);
            $notifResult = ['items' => [], 'total' => 0];
            $total = $emails['total'];
            $notifFilters = ['type' => '', 'is_read' => '', 'recipient_type' => ''];
        } else {
            $notifFilters = [
                'type'           => (string) $this->request->query('type', ''),
                'is_read'        => (string) $this->request->query('is_read', ''),
                'recipient_type' => (string) $this->request->query('recipient_type', ''),
            ];
            $notifResult = $this->notifications->findAll($page, $perPage, $notifFilters);
            $emails = ['items' => [], 'total' => 0];
            $total = $notifResult['total'];
            $emailFilters = ['status' => ''];
        }

        $this->render('admin/notifications/index', [
            'title'         => '通知記錄',
            'tab'           => $tab,
            'notifications' => $notifResult['items'],
            'emails'        => $emails['items'],
            'total'         => $total,
            'page'          => $page,
            'perPage'       => $perPage,
            'totalPages'    => (int) ceil($total / $perPage),
            'notifFilters'  => $notifFilters,
            'emailFilters'  => $emailFilters,
            'typeLabels'    => self::typeLabels(),
            'emailStatusLabels' => self::emailStatusLabels(),
        ]);
    }

    /**
     * 標記單筆通知為已讀（POST + CSRF）。
     */
    public function markRead(): void
    {
        $this->guardCsrf();
        $id = (int) $this->request->param('id');
        $this->notifications->markRead($id);
        $this->redirectWith('/admin/notifications', 'success', '已標記為已讀。');
    }

    /**
     * 重送失敗信（POST + CSRF）。僅 failed 可重送（EmailQueueRepository::requeue 把關）。
     * 重送只是把信重置回 queued，實際寄送仍由 cron mail 處理。
     */
    public function resendEmail(): void
    {
        $this->guardCsrf();
        $id = (int) $this->request->param('id');

        $mail = $this->emailQueue->findById($id);
        if ($mail === null) {
            $this->redirectWith('/admin/notifications?tab=emails', 'error', '找不到該封信。');
        }

        $ok = $this->emailQueue->requeue($id);
        if ($ok) {
            $this->auditLog->log(
                $this->currentUserId(),
                'email_requeued',
                'email_queue',
                $id,
                ['to_email' => $mail['to_email'] ?? ''],
                $this->request->ip()
            );
            $this->redirectWith('/admin/notifications?tab=emails', 'success', '已重新排入寄送佇列，將於下次排程寄出。');
        } else {
            $this->redirectWith('/admin/notifications?tab=emails', 'error', '僅「失敗」狀態的信件可重送。');
        }
    }

    // ───────────────────────── 內部輔助 ─────────────────────────

    private function guardCsrf(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }
    }

    private function currentUserId(): int
    {
        $user = Session::get('user');
        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }

    /**
     * 通知 type → 中文標籤。
     *
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            'quote.pending_sign'          => '報價待簽',
            'quote.signed'                => '報價已簽署',
            'payment.received'            => '款項已收',
            'payment.due'                 => '款項待收',
            'hosting.expiring'            => '主機到期',
            'website.expiring'            => '網站到期',
            'recurring.generated'         => '週期單已產生',
            'recurring.autocharge_manual' => '週期單待人工扣款',
        ];
    }

    /**
     * 寄信狀態 → 中文標籤。
     *
     * @return array<string, string>
     */
    public static function emailStatusLabels(): array
    {
        return [
            'queued'  => '待寄送',
            'sending' => '寄送中',
            'indeterminate' => '需人工確認',
            'sent'    => '已寄送',
            'failed'  => '失敗',
        ];
    }
}
