<?php

declare(strict_types=1);

namespace YangSheep\CRM\Console;

/**
 * CronScheduler 對執行器的最小需求。
 *
 * 【為什麼要這個介面】CronScheduler 的判斷邏輯（今天到期了沒、該跑哪一個）
 * 是這次改動裡最需要被測到的部分 —— 它決定「今天的帳出不出」。
 * 但 CronRunner 是 final，而且它會真的連 DB、真的執行 Kernel，
 * 測試不可能拿真品去驗「錯過 08:00 之後會不會補跑」這種情境。
 *
 * 把 scheduler 需要的兩件事寫成介面，測試就能注入替身去驗判斷本身，
 * 而 CronRunner 仍然維持 final（不開放被繼承改寫執行流程）。
 */
interface CronRunnerInterface
{
    /**
     * 執行一個子命令。
     *
     * @return array{ok: bool, status: string, message: string, output: string, run_id: int|null}
     */
    public function run(
        string $command,
        string $source = 'cli',
        ?int $actorUserId = null,
        ?string $today = null,
        ?int $lockWaitSeconds = null
    ): array;

    /** 這個子命令在最近 N 秒內有沒有**成功**執行過。 */
    public function hasSucceededWithin(string $command, int $seconds): bool;
}
