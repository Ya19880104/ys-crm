<?php

declare(strict_types=1);

namespace YangSheep\CRM\Console;

use YangSheep\CRM\Setting\SettingService;

/**
 * 單一入口排程（tick）：主機只掛一條 cron，由應用層決定「現在該做什麼」。
 *
 * 【為什麼不是多條 crontab】
 * 原本建議掛兩條（每 5 分鐘 mail、每日 08:00 run），有三個獨立的問題：
 *
 *   1. **兩條會在 08:00 那一格同時觸發**，搶同一把全域鎖。誰先拿到是隨機的，
 *      mail 先拿到時當天的 run 就被略過 —— 而 busy 不寫執行紀錄，
 *      後台只是「今天沒有這筆」，沒有任何錯誤，下一次機會是 24 小時後。
 *
 *   2. **crontab 的時間用的是主機時區，不是本站的時區**。這台主機是 UTC，
 *      所以 `0 8 * * *` 實際上是台北時間 16:00 —— 設定的人以為是早上八點。
 *      主機時區不歸我們管，也不會有人在改主機時區時想到來改這裡。
 *
 *   3. **錯過就是錯過**。主機重開、部署、鎖被佔住 —— 那一天的到期掃描與
 *      週期帳單就是沒跑，而且要等 24 小時。
 *
 * tick 把這三件事一起解掉：cron 只負責「每 5 分鐘叫我一次」（與時區無關），
 * 剩下的判斷都在應用層 ——
 *
 *   - 每日工作的時間讀本站設定，用本站時區（config/app.php 的 timezone）計算
 *   - **補觸發**：不是「08:00 那一刻有沒有被叫到」，而是「今天過了 08:00 之後
 *     到底有沒有成功跑過」。沒有就補跑，所以錯過的那次會在下一個 tick 補上，
 *     最多晚 5 分鐘，而不是晚一天
 *   - 因為會補，取不到鎖時直接略過即可，不需要排隊等
 *
 * 【時鐘】這裡刻意不拿 PHP 的時間去和 DB 的時間比較。
 * 本站的 PHP 是 Asia/Taipei，MySQL 連線沒有設 time_zone、跟隨主機的 UTC，
 * 兩者差 8 小時 —— 直接比會得到完全錯誤的結果，而且畫面上看不出異常。
 * 改成算出「距離今天的執行時刻過了幾秒」這個**時間長度**再交給 DB，
 * 長度沒有時區，DB 就只在自己的時鐘裡比較。
 */
final class CronScheduler
{
    /** 未設定時的每日執行時刻（本站時區）。 */
    public const DEFAULT_DAILY_AT = '08:00';

    private CronRunnerInterface $runner;
    private SettingService $settings;

    public function __construct(?CronRunnerInterface $runner = null, ?SettingService $settings = null)
    {
        $this->runner   = $runner ?? new CronRunner();
        $this->settings = $settings ?? new SettingService();
    }

    /**
     * 每日工作的執行時刻，格式 HH:MM（本站時區）。
     *
     * 讀不到或格式不對一律退回預設值 —— 這個值決定「今天要不要出帳」，
     * 不能因為設定被寫壞就變成「整天都算已經跑過」或「每 5 分鐘跑一次」。
     */
    public function dailyAt(): string
    {
        $raw = trim((string) ($this->settings->get('cron', 'cron_daily_at') ?? ''));

        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $raw) === 1
            ? $raw
            : self::DEFAULT_DAILY_AT;
    }

    /**
     * 今天那個時刻的 Unix timestamp（以本站時區解讀 HH:MM）。
     *
     * 用 Unix timestamp 而不是字串：它是絕對時刻，不帶時區，
     * 後續的相減才會是真正的「過了幾秒」。
     */
    public function dailyBoundary(?int $now = null): int
    {
        $now ??= time();
        [$h, $m] = array_map('intval', explode(':', $this->dailyAt()));

        return (new \DateTimeImmutable('@' . $now))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->setTime($h, $m, 0)
            ->getTimestamp();
    }

    /**
     * 今天的每日工作是否還沒跑（＝該補跑了）。
     *
     * 判準刻意不是「現在是不是 08:00」而是「今天過了 08:00 之後有沒有成功過」——
     * 前者錯過一次就沒了，後者會一直補到成功為止。
     */
    public function isDailyDue(?int $now = null): bool
    {
        $now ??= time();
        $boundary = $this->dailyBoundary($now);

        if ($now < $boundary) {
            return false;   // 今天還沒到時間
        }

        // 只傳「長度」給 DB，不傳時刻 —— 見類別註解的【時鐘】。
        return !$this->runner->hasSucceededWithin('run', $now - $boundary);
    }

    /**
     * 主機排程每次叫到的入口。
     *
     * @return array{daily: bool, command: 'run'|'maintenance', result: array<string, mixed>}
     */
    public function tick(string $source = 'cli', ?int $actorUserId = null): array
    {
        // 每日工作到期時跑完整批次（其中已含 invoice + mail）；否則只跑高頻
        // maintenance（invoice + mail），不可重跑每日的 expiry/recurring/reminders。
        $command = $this->isDailyDue() ? 'run' : 'maintenance';

        // 取不到鎖時直接略過：下一個 tick（5 分鐘後）仍會判定為到期而補跑，
        // 所以不需要排隊等 —— 補觸發本身就是重試機制。
        $result = $this->runner->run($command, $source, $actorUserId, null, 0);

        return ['daily' => $command === 'run', 'command' => $command, 'result' => $result];
    }
}
