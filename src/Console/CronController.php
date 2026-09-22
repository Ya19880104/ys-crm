<?php

declare(strict_types=1);

namespace YangSheep\CRM\Console;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Setting\SettingService;
use YangSheep\CRM\AuditLog\AuditLogService;

/**
 * Token 保護的排程 URL 觸發端點（對應架構設計 §6 金流安全/cron 端點 token 保護、§7.10）。
 *
 * CLI（cli/cron.php）為主要觸發方式；本端點供「主機僅能以 URL 觸發 cron」時的替代方案。
 *
 * Zero Trust：
 *   - 不掛 Auth/CSRF（外部排程器無 session/token）；改以「設定中的 cron token」constant-time 比對。
 *   - fail-closed：未設定 cron token（settings group=cron, key=cron_token）一律拒絕（404），
 *     避免無 token 時端點全裸。
 *   - 比對用 hash_equals（constant-time），杜絕 timing oracle。
 *   - 與 webhook 同列「CSRF 群組之外」的公開路由。
 *
 * 路由：GET /cron/run?command=tick|run|maintenance|expiry|recurring|reminders|invoice|mail&token=...
 *   （token 亦可改用 X-Cron-Token header 帶入，避免密鑰進入 access log。）
 *
 * 🔴 建議只掛 `command=tick`、每 5 分鐘一次。tick 會自己判斷這一輪該做什麼，
 * 外部排程器不必知道任何時刻，也就不會受主機時區影響（見 CronScheduler）。
 *
 * 併發鎖與執行紀錄由 CronRunner 負責 —— 後台「排程設定」頁即以那些紀錄
 * 回答「排程到底有沒有在跑」。
 */
class CronController extends Controller
{
    private SettingService $settings;
    private AuditLogService $auditLog;
    private CronRunnerInterface $runner;

    public function __construct(
        Request $request,
        Response $response,
        ?SettingService $settings = null,
        ?AuditLogService $auditLog = null,
        ?CronRunnerInterface $runner = null
    ) {
        parent::__construct($request, $response);
        $this->settings = $settings ?? new SettingService();
        $this->auditLog = $auditLog ?? new AuditLogService();
        $this->runner = $runner ?? new CronRunner();
    }

    /**
     * 執行排程（token 驗證後委派 Kernel）。
     */
    public function run(): void
    {
        if (!$this->verifyToken()) {
            // 統一回 404，且所有失敗情況（未設定 token / token 錯 / 未帶 token）一致。
            //
            // 【為何從 403 改成 404】403 的語意是「這裡有東西，但你不能存取」——
            // 對掃描者而言那是一個確認：這個站有排程觸發端點。404 什麼也沒透露。
            // 原本的寫法已經做到「不區分是哪一種失敗」，這裡再往前一步，
            // 連「端點存在」本身都不透露。
            $this->json(['ok' => false, 'error' => 'Not Found'], 404);
            return;
        }

        // 相容兩種參數名：舊版用 cmd，後台產生的網址與文件用 command。
        $cmd = trim((string) $this->request->query('command', (string) $this->request->query('cmd', 'run')));

        // 🔴 未知的子命令必須明確拒絕，不可 fallback 到 runAll()。
        // 舊版的 match 沒有 'invoice' 分支且 default 是 runAll —— 也就是說
        // 要求「只跑發票維護」會安靜地把**整批**跑掉（含產生週期帳單）。
        // 「我沒看懂你要什麼，那就全部做一遍」在帳務端是最壞的預設值。
        if ($cmd !== 'tick' && !CronRunner::isValidCommand($cmd)) {
            $this->auditLog->log(0, 'cron_url_rejected', 'cron', null,
                ['cmd' => $cmd, 'ip' => $this->request->ip()], $this->request->ip());
            $this->json(['ok' => false, 'error' => 'Unknown command'], 400);
            return;
        }

        // 🔴 today 不再從公開端點接受。
        // 那個參數控制的是「產生哪一期的帳單」，等於讓持有 token 的呼叫端
        // 決定出帳期間；補跑請走後台「排程設定」頁，那裡有權限、step-up 與稽核。
        // tick 走排程器（它會自己決定跑 maintenance 還是完整批次），其餘直接委派。
        $result = $cmd === 'tick'
            ? (new CronScheduler($this->runner, $this->settings))->tick('http', null)['result']
            : $this->runner->run($cmd, 'http', null, null);

        $this->auditRunBestEffort($cmd, $result);

        // busy 回 409：外部排程服務可據此分辨「這次沒跑」與「跑失敗」。
        $httpStatus = match ($result['status']) {
            'success' => 200,
            'busy'    => 409,
            default   => 500,
        };

        $this->json([
            'ok'      => $result['ok'],
            'command' => $cmd,
            'status'  => $result['status'],
            'message' => $result['message'],
            'output'  => $result['output'],
        ], $httpStatus);
    }

    /**
     * Runner outcome 已經成立後，audit sink 不再有權把它改寫成未結構化 500。
     *
     * 外部 scheduler 依 structured status 決定是否重試；若 audit insert 的例外
     * 逃出去，既會丟失真正的工作結果，也可能讓已完成的工作被誤判為沒執行而重送。
     * audit 故障仍寫進 PHP error log，供維運另外告警與補查。
     *
     * @param array{ok: bool, status: string, message: string, output: string, run_id: int|null} $result
     */
    private function auditRunBestEffort(string $cmd, array $result): void
    {
        $ip = $this->request->ip();

        try {
            $this->auditLog->log(0, 'cron_url_run', 'cron', $result['run_id'],
                ['cmd' => $cmd, 'status' => $result['status'], 'ip' => $ip], $ip);
        } catch (\Throwable $e) {
            $message = preg_replace('/[\r\n]+/', ' ', $e->getMessage()) ?? 'unknown audit failure';
            try {
                error_log('[cron][url][audit] ' . $message);
            } catch (\Throwable) {
                // Logging fallback must not overwrite the authoritative runner outcome either.
            }
        }
    }

    /**
     * Constant-time 驗證 cron token。
     * fail-closed：未設定 token → 一律失敗。
     */
    private function verifyToken(): bool
    {
        $configured = (string) ($this->settings->get('cron', 'cron_token') ?? '');
        if ($configured === '') {
            return false; // 未設定 → 拒絕（不開裸端點）。
        }

        // 支援由 query 或 X-Cron-Token header 帶入。
        $provided = (string) $this->request->query('token', '');
        if ($provided === '') {
            $provided = (string) ($this->request->header('X-Cron-Token') ?? '');
        }
        if ($provided === '') {
            return false;
        }

        return hash_equals($configured, $provided);
    }
}
