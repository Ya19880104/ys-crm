<?php

declare(strict_types=1);

namespace YangSheep\CRM\Console;

use YangSheep\CRM\AuditLog\AuditLogService;
use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\RequestOrigin;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Setting\SettingService;

/**
 * 排程管理（後台）：顯示設定指令、產生觸發網址、手動執行、檢視執行紀錄。
 *
 * 【為什麼這一頁存在】週期帳單停在 2026-07-05 沒有產生，原因是容器內沒有
 * crontab、主機面板的排程也從沒設定 —— 而後台完全看不出「排程有沒有在跑」。
 * 這一頁要能直接回答：該怎麼設、現在有沒有在跑、上次跑是什麼時候、跑出什麼。
 *
 * 掛在 /admin/cron，需 system.settings 權限 + step-up 再認證（與系統設定同級）。
 */
final class CronAdminController extends Controller
{
    private SettingService $settings;
    private CronRunner $runner;
    private AuditLogService $auditLog;

    public function __construct(
        Request $request,
        Response $response,
        ?SettingService $settings = null,
        ?AuditLogService $auditLog = null,
        ?CronRunner $runner = null
    ) {
        parent::__construct($request, $response);
        $this->settings = $settings ?? new SettingService();
        $this->runner   = $runner ?? new CronRunner();
        $this->auditLog = $auditLog ?? new AuditLogService();
    }

    public function index(): void
    {
        $token = trim((string) ($this->settings->get('cron', 'cron_token') ?? ''));

        $scheduler = new CronScheduler($this->runner, $this->settings);

        $this->render('admin/cron/index', [
            'title'        => '排程設定',
            'commands'     => $this->commandMeta(),
            'lastSuccess'  => $this->runner->lastSuccessByCommand(),
            'runs'         => $this->runner->recentRuns(20),
            'token'        => $token,
            'triggerBase'  => RequestOrigin::absoluteUrl('/cron/run'),
            'appPath'      => BASE_PATH,
            // 每日工作的時刻現在由本站決定（不再寫在 crontab 裡），所以要顯示得出來、改得動。
            'dailyAt'      => $scheduler->dailyAt(),
            'dailyDue'     => $scheduler->isDailyDue(),
            'timezone'     => date_default_timezone_get(),
            'breadcrumb'   => [['label' => '排程設定']],
        ]);
    }

    /**
     * 儲存每日工作的執行時刻。
     *
     * 【為什麼這個值搬到設定裡】改用單一入口（tick）之後，crontab 只寫「每 5 分鐘」，
     * 不再帶任何時刻 —— 時刻必須由本站保存，才能用本站的時區解讀。
     * 原本寫在 crontab 的 `0 8` 用的是主機時區，這台主機是 UTC，
     * 也就是設定的人以為的早上八點其實是台北時間下午四點。
     */
    public function saveDailyAt(): void
    {
        $this->guardCsrf();
        $this->guardPermission('system.settings', '/admin/cron');

        $value = trim((string) $this->request->input('cron_daily_at'));

        // fail-closed：格式不對就不寫。這個值決定「今天要不要出帳」，
        // 寫進一個解讀不了的值等於讓每日工作行為未定義。
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value) !== 1) {
            $this->redirectWith('/admin/cron', 'error', '時刻格式須為 HH:MM（24 小時制），未變更。');
            return;
        }

        $this->settings->set('cron', 'cron_daily_at', $value, false);

        $this->auditLog->log(
            $this->currentUserId(),
            'cron_daily_at_changed',
            'cron',
            null,
            ['value' => $value],
            $this->request->ip()
        );

        $this->redirectWith('/admin/cron', 'success', "每日工作時刻已設為 {$value}（" . date_default_timezone_get() . '）。');
    }

    /**
     * 產生（或重新產生）URL 觸發用的 token。
     *
     * 重新產生會讓舊網址立刻失效 —— 這是刻意的，那也是「疑似外洩時」的處置方式。
     */
    public function regenerateToken(): void
    {
        $this->guardCsrf();
        $this->guardPermission('system.settings', '/admin/cron');

        $token = bin2hex(random_bytes(32));
        $this->settings->set('cron', 'cron_token', $token, true);

        $this->auditLog->log(
            $this->currentUserId(),
            'cron_token_regenerated',
            'cron',
            null,
            [],
            $this->request->ip()
        );

        $this->redirectWith('/admin/cron', 'success', '已產生新的觸發網址，舊網址即刻失效。');
    }

    /** 停用 URL 觸發（清空 token → 端點回 404）。 */
    public function revokeToken(): void
    {
        $this->guardCsrf();
        $this->guardPermission('system.settings', '/admin/cron');

        $this->settings->set('cron', 'cron_token', '', true);

        $this->auditLog->log(
            $this->currentUserId(),
            'cron_token_revoked',
            'cron',
            null,
            [],
            $this->request->ip()
        );

        $this->redirectWith('/admin/cron', 'success', 'URL 觸發已停用。');
    }

    /**
     * 後台手動執行一次。
     *
     * ⚠️ `recurring` 會**實際產生帳單與待付款**。這是帳務操作，因此：
     *   - 需要 system.settings 權限與 step-up 再認證（路由層已強制）
     *   - 前端有二次確認
     *   - 一律寫入稽核紀錄（誰、什麼時候、跑了哪一個）
     */
    public function trigger(): void
    {
        $this->guardCsrf();
        $this->guardPermission('system.settings', '/admin/cron');

        $command = trim((string) $this->request->input('command', ''));
        if ($command !== 'tick' && !CronRunner::isValidCommand($command)) {
            $this->redirectWith('/admin/cron', 'error', '未知的排程項目。');
        }

        // tick 是排程器不是子命令：它自己判斷這一輪要跑 maintenance 還是完整批次，
        // 再委派給 CronRunner。手動按下去等於「模擬一次主機排程」。
        if ($command === 'tick') {
            $tick   = (new CronScheduler($this->runner, $this->settings))
                ->tick('admin', $this->currentUserId());
            $result = $tick['result'];
            $result['message'] = ($tick['daily']
                ? '每日工作到期，已執行完整批次：'
                : '每日工作今天已完成，本輪已處理電子發票維護與寄信佇列：')
                . $result['message'];
        } else {
            $result = $this->runner->run($command, 'admin', $this->currentUserId(), null);
        }

        $this->auditTriggerBestEffort($command, $result);

        $meta  = $this->commandMeta()[$command] ?? ['label' => $command];
        $label = (string) ($meta['label'] ?? $command);

        if ($result['ok']) {
            $this->redirectWith('/admin/cron', 'success', "「{$label}」執行完成：" . $result['message']);
        }

        $this->redirectWith('/admin/cron', 'error', "「{$label}」" . $result['message']);
    }

    /**
     * 手動工作結束後的 audit 是旁路；故障不得遮蔽完成結果而誘發管理員重按。
     *
     * @param array{ok: bool, status: string, message: string, output: string, run_id: int|null} $result
     */
    private function auditTriggerBestEffort(string $command, array $result): void
    {
        try {
            $this->auditLog->log(
                $this->currentUserId(),
                'cron_triggered',
                'cron',
                $result['run_id'],
                ['source' => 'admin', 'command' => $command, 'status' => $result['status']],
                $this->request->ip()
            );
        } catch (\Throwable $e) {
            $message = preg_replace('/[\r\n]+/', ' ', $e->getMessage()) ?? 'unknown audit failure';
            try {
                error_log('[cron][admin][audit] ' . $message);
            } catch (\Throwable) {
                // Logging fallback must not overwrite the authoritative runner outcome either.
            }
        }
    }

    /**
     * 子命令的說明與風險標示。
     *
     * @return array<string, array{label: string, desc: string, writes_money: bool}>
     */
    private function commandMeta(): array
    {
        return [
            'tick' => [
                'label' => '排程檢查',
                'desc'  => '主機排程入口：每輪處理電子發票重試與寄信；若今天的每日工作還沒成功跑過則改跑完整批次。',
                'writes_money' => true,
            ],
            'run' => [
                'label' => '全部',
                'desc'  => '依序執行下列所有項目。改用單一入口後，平常由「排程檢查」自動叫，這裡供手動補跑。',
                'writes_money' => true,
            ],
            'maintenance' => [
                'label' => '高頻維護',
                'desc'  => '先回收／重試電子發票，再處理寄信佇列；不重跑每日帳單與到期掃描。',
                'writes_money' => true,
            ],
            'expiry' => [
                'label' => '到期掃描',
                'desc'  => '報價逾期翻狀態、到期前提醒。',
                'writes_money' => false,
            ],
            'recurring' => [
                'label' => '週期帳單',
                'desc'  => '產生到期的週期報價與待付款。一次只推進一個期間（避免補跑時一次產生多期）。',
                'writes_money' => true,
            ],
            'reminders' => [
                'label' => '提醒',
                'desc'  => '報價待簽、款項待收的提醒信件排入佇列。',
                'writes_money' => false,
            ],
            'invoice' => [
                'label' => '電子發票維護',
                'desc'  => '回收殭屍 claim、重試開立失敗的發票。',
                'writes_money' => true,
            ],
            'mail' => [
                'label' => '寄信佇列',
                'desc'  => '處理待寄送的信件。建議每 5 分鐘一次。',
                'writes_money' => false,
            ],
        ];
    }

    private function guardCsrf(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }
    }

    private function currentUserId(): int
    {
        $user = \YangSheep\CRM\Core\Session::get('user');
        return (int) ($user['id'] ?? 0);
    }
}
