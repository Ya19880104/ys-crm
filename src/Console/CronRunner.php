<?php

declare(strict_types=1);

namespace YangSheep\CRM\Console;

use YangSheep\CRM\Core\Database;

/**
 * 排程執行的統一入口：加上鎖、逾時回收與執行紀錄，再委派給 Kernel。
 *
 * 三種觸發來源都走這裡（cli / http / admin），才能保證：
 *   - 併發不會互相踩（以 DB 具名鎖全域序列化，同時只跑一份）
 *   - 每一次執行都留下紀錄（誰、什麼時候、跑出什麼）
 *   - 後台看得到「上次到底有沒有跑」—— 這正是週期帳單停擺兩個月沒被發現的原因
 */
final class CronRunner implements CronRunnerInterface
{
    /** 可執行的子命令（同時是白名單，外部傳入的值一律比對這個清單） */
    public const COMMANDS = ['run', 'maintenance', 'expiry', 'recurring', 'reminders', 'invoice', 'mail'];

    /**
     * 一次執行最長視為多久 —— 超過就把那筆**紀錄**標成 failed。
     *
     * 🔴 這不是鎖的一部分。互斥由 DB 的具名鎖負責，連線中斷時它會自動釋放，
     * 不需要靠時間猜測。這個常數只影響後台的執行紀錄不要永遠停在「執行中」。
     */
    private const STALE_AFTER_SECONDS = 1800;

    /**
     * 取不到鎖時，各子命令願意等多久（秒）。
     *
     * 🔴🔴 【這張表刻意不對稱，不對稱本身就是修正】
     * 建議排程是每 5 分鐘只跑 tick，由 CronScheduler 在同一個 process 選擇
     * maintenance 或 run；這張表仍需保護直接 CLI 與舊部署的分離命令。
     *
     * 若獨立的短命令與 run 撞在一起且兩者都用 0 秒，短命令先拿到時 run 會被略過：
     * 到期掃描、週期帳單、提醒、發票重試、保留清理在那次觸發都沒跑；
     * tick scheduler 會在下一輪補跑，但舊式「每日單獨 run」排程可能延後整天。
     * 而且 busy 不寫執行紀錄，直接命令的後台歷史只會呈現缺了一筆。
     *
     * 兩者的代價差三個數量級：
     *   - 略過一次 maintenance/mail → 下一輪 tick 很快補上
     *   - 略過一次 run              → 每日帳務必須等 scheduler 下一輪重試
     *
     * 所以非互動來源的 run 願意排隊等前面那個短命令跑完，
     * 其餘維持 0：它們的「下一次」都很近，排隊沒有意義。
     */
    private const LOCK_WAIT_SECONDS = ['run' => 120];

    /** 手動觸發不等待：管理員按下去要馬上得到回應，而且他可以自己再按一次。 */
    private const INTERACTIVE_SOURCES = ['admin', 'http'];

    private Database $db;
    private Kernel $kernel;
    private CronLock $lock;

    public function __construct(?Kernel $kernel = null, ?CronLock $lock = null)
    {
        $this->db     = Database::getInstance();
        $this->kernel = $kernel ?? new Kernel();
        // 可注入：真正的互斥只有多連線的 MySQL 才成立，測試需要能模擬「搶不到」。
        $this->lock   = $lock ?? new CronLock($this->db);
    }

    public static function isValidCommand(string $command): bool
    {
        return in_array($command, self::COMMANDS, true);
    }

    /**
     * 執行一個子命令。
     *
     * @param string      $source        cli | http | admin
     * @param int|null    $actorUserId   admin 來源時的操作者
     * @param string|null $today         指定「今日」（補跑用）
     * @param int|null    $lockWaitSeconds 覆寫取鎖等待秒數；null = 依 LOCK_WAIT_SECONDS 判斷。
     *        CronScheduler 會明確傳 0：它有補觸發，取不到鎖下一輪自然會再試，排隊沒有意義。
     * @return array{ok: bool, status: string, message: string, output: string, run_id: int|null}
     */
    public function run(
        string $command,
        string $source = 'cli',
        ?int $actorUserId = null,
        ?string $today = null,
        ?int $lockWaitSeconds = null
    ): array {
        if (!self::isValidCommand($command)) {
            return [
                'ok' => false, 'status' => 'rejected', 'run_id' => null, 'output' => '',
                'message' => "未知的子命令：{$command}",
            ];
        }

        $this->reclaimStale();

        // 🔴 【併發鎖用 DB 的具名鎖，不是「先查再插」】
        // 原本的做法是 isRunning()（SELECT COUNT）之後才 begin()（INSERT），
        // 中間沒有交易、沒有列鎖，cron_runs 也沒有任何 UNIQUE 約束。
        // 兩個請求在同一毫秒抵達時（外部排程服務逾時重送、或管理員連按兩下），
        // 兩邊都會看到 count 0、都插入 status='running'、都執行 —— 而這個方法
        // 的回傳訊息卻寫著「避免重複出帳」。對 recurring 而言那就是同一期
        // 產生兩套週期報價與待付款。
        //
        // 改用 DB 具名鎖（CronLock）：取得與否是原子的，連線中斷時由 DB 自動釋放，
        // 不需要靠「超過 30 分鐘就視為死掉」這種猜測。詳細理由見 CronLock 的類別註解。
        //
        // 一律用同一把全域鎖：這些子命令共用 payments / quotes / email_queue，
        // 而 'run' 本來就設計成與任何子命令互斥。序列化是最安全也最好理解的語意。
        //
        // 🔴 但「略過」的代價因命令而異，所以等待時間不對稱 —— 見 LOCK_WAIT_SECONDS。
        // 原本兩邊都不等，等於每天 08:00 擲一次硬幣決定當天的帳出不出。
        if (!$this->lock->acquire($lockWaitSeconds ?? $this->lockWaitSeconds($command, $source))) {
            return [
                'ok' => false, 'status' => 'busy', 'run_id' => null, 'output' => '',
                'message' => '另一個排程正在執行中，本次略過（這是刻意的，避免重複出帳）。',
            ];
        }

        $started = microtime(true);

        try {
            try {
                $runId = $this->begin($command, $source, $actorUserId);
            } catch (\Throwable $beginError) {
                $msg = $this->oneLineError($beginError);
                return [
                    'ok' => false, 'status' => 'failed', 'run_id' => null,
                    'output' => $msg, 'message' => '排程紀錄建立失敗：' . $msg,
                ];
            }

            $workSucceeded = false;
            try {
                $stats = match ($command) {
                    'run'       => $this->kernel->runAll($today),
                    'maintenance' => ['maintenance' => $this->kernel->maintenance()],
                    'expiry'    => ['expiry' => $this->kernel->expiry($today)],
                    'recurring' => ['recurring' => $this->kernel->recurring($today)],
                    'reminders' => ['reminders' => $this->kernel->reminders($today)],
                    'invoice'   => ['invoice' => $this->kernel->invoice()],
                    'mail'      => ['mail' => $this->kernel->mail()],
                };
                $output = $this->formatStats($stats);
                $workSucceeded = true;
            } catch (\Throwable $workError) {
                // 訊息可能含堆疊或內部路徑 —— 只留第一行，避免寫入可讀取的紀錄。
                $output = $this->oneLineError($workError);
            }

            $elapsed = (int) round((microtime(true) - $started) * 1000);
            try {
                $this->finish($runId, $workSucceeded ? 'success' : 'failed', $output, $elapsed);
            } catch (\Throwable $finishError) {
                $finishMsg = $this->oneLineError($finishError);
                if ($workSucceeded) {
                    // 工作已經完成，但無法證明 run record 成功；回 failed 讓 scheduler
                    // 下一 tick 以各 job 的冪等契約補跑，同時明示工作本身不是失敗點。
                    $combined = '工作已完成；cron run record 無法確認：' . $finishMsg;
                    return [
                        'ok' => false, 'status' => 'failed', 'run_id' => $runId,
                        'output' => $combined,
                        'message' => '排程工作已完成，但執行紀錄更新失敗：' . $finishMsg,
                    ];
                }

                // 工作與 persistence 都失敗時，工作錯誤仍放在最前面；finish 只能附記，
                // 不得像舊流程一樣以第二個例外覆寫第一個 authoritative outcome。
                $combined = $output . '；cron run record 更新亦失敗：' . $finishMsg;
                return [
                    'ok' => false, 'status' => 'failed', 'run_id' => $runId,
                    'output' => $combined,
                    'message' => '排程執行失敗：' . $combined,
                ];
            }

            return $workSucceeded
                ? [
                    'ok' => true, 'status' => 'success', 'run_id' => $runId,
                    'output' => $output, 'message' => "完成（{$elapsed} ms）",
                ]
                : [
                    'ok' => false, 'status' => 'failed', 'run_id' => $runId,
                    'output' => $output, 'message' => '排程執行失敗：' . $output,
                ];
        } finally {
            // acquire 成功後的所有路徑（含 begin/finish 例外）都必須走到 release。
            // CronLock 自己已 best-effort；再隔離替身或未來實作的例外，避免覆寫工作結果。
            try {
                $this->lock->release();
            } catch (\Throwable $releaseError) {
                try {
                    error_log('[cron][lock] release failed: ' . $this->oneLineError($releaseError));
                } catch (\Throwable) {
                    // Logger 亦不可覆寫 authoritative outcome。
                }
            }
        }
    }

    /**
     * 最近的執行紀錄。
     *
     * @return list<array<string, mixed>>
     */
    public function recentRuns(int $limit = 20): array
    {
        return $this->db->fetchAll(
            "SELECT r.id, r.command, r.source, r.status, r.started_at, r.finished_at,
                    r.duration_ms, r.output, u.display_name AS actor_name
             FROM {prefix}cron_runs r
             LEFT JOIN {prefix}users u ON r.actor_user_id = u.id
             ORDER BY r.started_at DESC, r.id DESC
             LIMIT :limit",
            ['limit' => max(1, min(100, $limit))]
        );
    }

    /**
     * 各子命令的最近一次成功時間（供設定頁一眼看出「排程有沒有在跑」）。
     *
     * @return array<string, string|null>
     */
    public function lastSuccessByCommand(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT command, MAX(finished_at) AS last_success
             FROM {prefix}cron_runs
             WHERE status = 'success'
             GROUP BY command"
        );

        $out = array_fill_keys(self::COMMANDS, null);
        foreach ($rows as $r) {
            $out[(string) $r['command']] = $r['last_success'] !== null ? (string) $r['last_success'] : null;
        }

        return $out;
    }

    /** 保留期之外的執行紀錄清除（供 cron 自身維護）。 */
    public function purgeOlderThan(int $days = 30): int
    {
        return $this->db->execute(
            "DELETE FROM {prefix}cron_runs WHERE started_at < DATE_SUB(NOW(), INTERVAL :days DAY)",
            ['days' => max(1, $days)]
        );
    }

    // ───────────────────────── 內部 ─────────────────────────

    /**
     * 這個子命令在「最近 N 秒內」有沒有成功執行過。
     *
     * 🔴 【為什麼參數是「幾秒」而不是「哪個時刻」】呼叫端（CronScheduler）用的是
     * 本站時區的 PHP 時鐘，cron_runs.started_at 寫的是 DB 的 NOW()。這台主機上
     * 兩者差 8 小時（PHP 為 Asia/Taipei，MySQL 連線跟隨主機的 UTC），
     * 直接把 PHP 算出來的時刻丟進 SQL 比較，結果會整整錯 8 小時而且畫面上看不出來。
     *
     * 時間**長度**沒有時區。傳長度進來，DB 只在自己的時鐘裡做 NOW() 減法，
     * 兩邊的時區設定都不再影響結果。
     */
    public function hasSucceededWithin(string $command, int $seconds): bool
    {
        $count = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}cron_runs
              WHERE command = :cmd
                AND status = 'success'
                AND started_at >= DATE_SUB(NOW(), INTERVAL :secs SECOND)",
            ['cmd' => $command, 'secs' => max(0, $seconds)]
        );

        return $count > 0;
    }

    /**
     * 這次取鎖願意等多久。
     *
     * 手動觸發一律 0：讓後台立刻回「正在執行中」，比讓瀏覽器轉兩分鐘好。
     * 排程觸發才看 LOCK_WAIT_SECONDS —— 那裡才有「下一次機會多久後才來」的差別。
     */
    private function lockWaitSeconds(string $command, string $source): int
    {
        if (in_array($source, self::INTERACTIVE_SOURCES, true)) {
            return 0;
        }

        return self::LOCK_WAIT_SECONDS[$command] ?? 0;
    }

    /**
     * 把卡在 running 的紀錄標成 failed。
     *
     * 🔴 【這只是清理紀錄，不再是釋放鎖】改用 GET_LOCK 之後，真正的互斥由 DB
     * 負責，連線中斷時它會自動釋放 —— 不需要（也不該）靠「超過 N 分鐘就當作死了」
     * 來猜。原本那個做法會偷走一個還活著的鎖：一次合法跑超過 30 分鐘的 run
     *（大量寄信佇列）會被 5 分鐘一次的 mail 排程標成 failed，於是下一次觸發
     * 就與它並行執行 recurring。
     *
     * 現在它的唯一作用是讓後台的執行紀錄不要永遠停在「執行中」。
     */
    private function reclaimStale(): void
    {
        $this->db->execute(
            "UPDATE {prefix}cron_runs
                SET status = 'failed',
                    finished_at = NOW(),
                    -- 刻意不用 CONCAT()：MySQL 有、SQLite 沒有（SQLite 是 ||）。
                    -- 這一列本來就沒跑完、output 幾乎必為 NULL，直接覆寫即可，
                    -- 不值得為此在測試層再加一條方言轉換（少一條規則就少一個盲區）。
                    output = '[逾時未結束，由後續觸發回收]'
              WHERE status = 'running'
                AND started_at < DATE_SUB(NOW(), INTERVAL :secs SECOND)",
            ['secs' => self::STALE_AFTER_SECONDS]
        );
    }

    private function begin(string $command, string $source, ?int $actorUserId): int
    {
        $affected = $this->db->execute(
            "INSERT INTO {prefix}cron_runs (command, source, actor_user_id, status, started_at)
             VALUES (:cmd, :src, :actor, 'running', NOW())",
            [
                'cmd'   => $command,
                'src'   => in_array($source, ['cli', 'http', 'admin'], true) ? $source : 'cli',
                'actor' => $actorUserId !== null && $actorUserId > 0 ? $actorUserId : null,
            ]
        );

        if ($affected !== 1) {
            throw new \RuntimeException("cron run record begin affected={$affected}, expected=1");
        }

        $runId = (int) $this->db->lastInsertId();
        if ($runId <= 0) {
            throw new \RuntimeException('cron run record begin did not return a valid id');
        }

        return $runId;
    }

    private function finish(int $runId, string $status, string $output, int $elapsedMs): void
    {
        $affected = $this->db->execute(
            "UPDATE {prefix}cron_runs
                SET status = :status, finished_at = NOW(), duration_ms = :ms, output = :out
              WHERE id = :id",
            [
                'status' => $status,
                'ms'     => $elapsedMs,
                'out'    => mb_substr($output, 0, 4000),
                'id'     => $runId,
            ]
        );

        if ($affected !== 1) {
            throw new \RuntimeException("cron run record finish affected={$affected}, expected=1");
        }
    }

    private function oneLineError(\Throwable $error): string
    {
        $message = trim(explode("\n", $error->getMessage())[0]);
        return $message !== '' ? $message : $error::class;
    }

    /**
     * @param array<string, array<string, int|string>> $stats
     */
    private function formatStats(array $stats): string
    {
        $lines = [];
        foreach ($stats as $name => $detail) {
            $pairs = [];
            foreach ((array) $detail as $k => $v) {
                $pairs[] = $k . '=' . (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE));
            }
            $lines[] = '[' . $name . '] ' . ($pairs === [] ? '(無)' : implode(' ', $pairs));
        }

        return implode("\n", $lines);
    }
}
