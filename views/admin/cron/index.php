<?php
/**
 * 排程設定：怎麼設、現在有沒有在跑、上次跑了什麼。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $commands      command => ['label'=>..,'desc'=>..,'writes_money'=>bool]
 * @var array  $lastSuccess   command => 最近一次成功時間（或 null）
 * @var array  $runs          最近的執行紀錄
 * @var string $token         URL 觸發 token（空＝未啟用）
 * @var string $triggerBase   觸發網址的基底
 * @var string $appPath       主機上的 app 絕對路徑
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

$never = [];
foreach ($commands as $cmd => $meta) {
    if (empty($lastSuccess[$cmd])) {
        $never[] = $meta['label'];
    }
}

$fmtDt = static function (?string $d): string {
    $d = (string) ($d ?? '');
    return $d !== '' ? substr($d, 0, 19) : '從未執行';
};
?>

<div class="space-y-5">

<?php if ($never !== []): ?>
<?php /* 這正是週期帳單停擺兩個月沒被發現的原因：後台看不出排程有沒有在跑。 */ ?>
<div class="bg-amber-50 dark:bg-amber-500/10 border border-amber-300 dark:border-amber-500/30
            text-amber-800 dark:text-amber-200 rounded-xl px-4 py-3 text-sm">
    <p class="font-medium mb-1">有排程從未成功執行過</p>
    <p class="text-xs leading-relaxed">
        <?= e(implode('、', $never)) ?> ——
        表示主機排程尚未設定，或設定的指令沒有真的跑起來。
        週期帳單與提醒信都依賴排程，未設定時不會有任何錯誤訊息，只會安靜地不發生。
    </p>
</div>
<?php endif; ?>

<!-- 各項排程狀態 + 手動執行 -->
<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-200 dark:border-surface-border">
        <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100">排程項目</h3>
        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">所有項目皆為冪等，重複執行不會產生重複資料。</p>
    </div>
    <div class="overflow-x-auto">
    <table class="w-full text-sm whitespace-nowrap">
        <thead class="bg-slate-50 dark:bg-white/5 border-b border-slate-200 dark:border-surface-border">
            <tr>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">項目</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">說明</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">最近一次成功</th>
                <th class="text-right px-4 py-3 font-medium text-slate-500 dark:text-slate-400">手動執行</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
            <?php foreach ($commands as $cmd => $meta): ?>
            <tr class="hover:bg-slate-50 dark:hover:bg-white/5 transition">
                <td class="px-4 py-3">
                    <span class="font-medium text-slate-800 dark:text-slate-100"><?= e($meta['label']) ?></span>
                    <span class="block font-mono text-xs text-slate-400"><?= e($cmd) ?></span>
                </td>
                <td class="px-4 py-3 text-slate-600 dark:text-slate-300 whitespace-normal max-w-md">
                    <?= e($meta['desc']) ?>
                    <?php if (!empty($meta['writes_money'])): ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                 bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200 ml-1">會產生帳務資料</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 font-mono text-xs <?= empty($lastSuccess[$cmd]) ? 'text-amber-700 dark:text-amber-300' : 'text-slate-700 dark:text-slate-200' ?>">
                    <?= e($fmtDt($lastSuccess[$cmd] ?? null)) ?>
                </td>
                <td class="px-4 py-3 text-right">
                    <form method="POST" action="/admin/cron/trigger" class="inline"
                          onsubmit="return confirm(<?= e(json_encode(
                              ($meta['writes_money'] ?? false)
                                  ? "「{$meta['label']}」會實際產生帳務資料（報價／待付款／發票）。確定現在執行嗎？"
                                  : "確定現在執行「{$meta['label']}」？",
                              JSON_UNESCAPED_UNICODE
                          )) ?>)">
                        <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                        <input type="hidden" name="command" value="<?= e($cmd) ?>">
                        <button type="submit"
                                class="px-3 py-1.5 text-xs rounded-lg font-medium transition
                                       <?= ($meta['writes_money'] ?? false)
                                           ? 'bg-amber-100 text-amber-800 hover:bg-amber-200'
                                           : 'bg-blue-600 text-white hover:bg-blue-700' ?>">
                            立即執行
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- 方式一：主機排程（建議） -->
<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
    <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-1">方式一：主機排程（建議）</h3>
    <p class="text-xs text-slate-500 dark:text-slate-400 mb-4 leading-relaxed">
        於主機面板的排程（Cron Jobs）新增，<strong class="text-slate-700 dark:text-slate-200">只要這一條</strong>。
        不經過網路、不受 PHP 執行時間限制。
    </p>

    <?php $line = 'php ' . $appPath . '/cli/cron.php tick'; ?>
    <div x-data="{ copied: false }">
        <p class="text-xs text-slate-500 dark:text-slate-400 mb-1">
            <span class="font-medium text-slate-700 dark:text-slate-200">每 5 分鐘</span>
            —— 排程間隔設為 <code class="font-mono">*/5 * * * *</code>
        </p>
        <div class="flex items-stretch gap-2">
            <code class="flex-1 px-3 py-2 rounded-lg bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-surface-border
                         font-mono text-xs text-slate-800 dark:text-slate-100 break-all"><?= e($line) ?></code>
            <button type="button"
                    @click="navigator.clipboard.writeText(<?= e(json_encode($line, JSON_UNESCAPED_SLASHES)) ?>); copied = true; setTimeout(() => copied = false, 1500)"
                    class="px-3 py-2 text-xs rounded-lg border border-slate-300 dark:border-surface-border
                           text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/5 whitespace-nowrap">
                <span x-text="copied ? '已複製' : '複製'"></span>
            </button>
        </div>
    </div>

    <?php /* 為什麼不是兩條 —— 這三點都是實際踩過的，寫在畫面上而不是只留在程式碼註解。 */ ?>
    <div class="mt-4 pt-4 border-t border-slate-200 dark:border-surface-border">
        <p class="text-xs font-medium text-slate-700 dark:text-slate-200 mb-1.5">為什麼只掛一條</p>
        <ul class="text-xs text-slate-500 dark:text-slate-400 space-y-1 leading-relaxed list-disc pl-4">
            <li>兩條排程若都設在整點，會在同一分鐘同時觸發並搶同一把鎖 ——
                誰先拿到是隨機的，輸的那一邊會被略過而且<strong>不留執行紀錄</strong>。</li>
            <li>crontab 的時刻用的是<strong>主機時區</strong>，本機是
                <code class="font-mono">UTC</code>；寫 <code class="font-mono">0 8</code>
                實際會在台北時間 16:00 執行。改由本站判斷就沒有這個落差。</li>
            <li>錯過就補：判準是「今天過了執行時刻後有沒有成功過」，不是「那一刻有沒有被叫到」。
                主機重開或部署錯過的那次，下一輪（最多 5 分鐘）就會補上，而不是等隔天。</li>
        </ul>
    </div>

    <!-- 每日工作的時刻（改用單一入口後，這個值不在 crontab 裡，由本站保存） -->
    <div class="mt-4 pt-4 border-t border-slate-200 dark:border-surface-border">
        <form method="POST" action="/admin/cron/daily-at" class="ys-toolbar flex flex-wrap items-center gap-2">
            <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
            <label for="cron_daily_at" class="text-xs font-medium text-slate-700 dark:text-slate-200">每日工作執行時刻</label>
            <input type="time" id="cron_daily_at" name="cron_daily_at" value="<?= e($dailyAt ?? '08:00') ?>"
                   class="px-3 rounded-lg border border-slate-300 dark:border-surface-border bg-white dark:bg-surface-card
                          text-sm text-slate-800 dark:text-slate-100">
            <span class="text-xs text-slate-400 dark:text-slate-500 font-mono"><?= e($timezone ?? '') ?></span>
            <button type="submit" class="px-4 text-sm bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition">
                儲存
            </button>
            <span class="text-xs <?= !empty($dailyDue) ? 'text-amber-700 dark:text-amber-300' : 'text-slate-400 dark:text-slate-500' ?>">
                <?= !empty($dailyDue) ? '今天尚未執行 —— 下一輪會補跑' : '今天已完成' ?>
            </span>
        </form>
        <p class="text-xs text-slate-500 dark:text-slate-400 mt-2 leading-relaxed">
            以本站時區（<?= e($timezone ?? '') ?>）解讀，與主機時區無關。
            到期掃描、週期帳單、提醒、發票重試都在這個時刻之後的第一輪執行。
        </p>
    </div>
</div>

<!-- 方式二：URL 觸發 -->
<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
    <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-1">方式二：URL 觸發</h3>
    <p class="text-xs text-slate-500 dark:text-slate-400 mb-4 leading-relaxed">
        供無法使用主機排程時，以外部排程服務（如 cron-job.org）定時呼叫。
        <span class="block mt-1">
            ⚠️ 網址帶有密鑰，等同密碼。它會出現在伺服器 access log 中；
            若外部服務支援自訂 header，請改用
            <span class="font-mono">X-Cron-Token</span> 而不要把密鑰放在網址。
        </span>
    </p>

    <?php if ($token === ''): ?>
        <p class="text-sm text-slate-500 dark:text-slate-400 mb-3">
            目前未啟用。未啟用時該端點一律回應 404，不會透露它的存在。
        </p>
        <form method="POST" action="/admin/cron/token/regenerate">
            <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
            <button type="submit" class="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition">
                產生觸發網址
            </button>
        </form>
    <?php else: ?>
        <?php $url = $triggerBase . '?command=tick&token=' . $token; ?>
        <div x-data="{ copied: false }" class="space-y-3">
            <div>
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-1">完整網址 —— 每 5 分鐘呼叫一次即可（command 可換成上表任一項）</p>
                <div class="flex items-stretch gap-2">
                    <code class="flex-1 px-3 py-2 rounded-lg bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-surface-border
                                 font-mono text-xs text-slate-800 dark:text-slate-100 break-all"><?= e($url) ?></code>
                    <button type="button"
                            @click="navigator.clipboard.writeText(<?= e(json_encode($url, JSON_UNESCAPED_SLASHES)) ?>); copied = true; setTimeout(() => copied = false, 1500)"
                            class="px-3 py-2 text-xs rounded-lg border border-slate-300 dark:border-surface-border
                                   text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/5 whitespace-nowrap">
                        <span x-text="copied ? '已複製' : '複製'"></span>
                    </button>
                </div>
            </div>

            <div>
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-1">改用 header（較安全，密鑰不進網址與 log）</p>
                <code class="block px-3 py-2 rounded-lg bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-surface-border
                             font-mono text-xs text-slate-800 dark:text-slate-100 break-all">curl -H "X-Cron-Token: <?= e($token) ?>" "<?= e($triggerBase) ?>?command=tick"</code>
            </div>

            <div class="flex items-center gap-2 pt-1">
                <form method="POST" action="/admin/cron/token/regenerate"
                      onsubmit="return confirm('產生新網址後，舊網址會立刻失效。確定嗎？')">
                    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                    <button type="submit" class="px-3 py-1.5 text-xs rounded-lg border border-slate-300 dark:border-surface-border text-slate-600 dark:text-slate-300">
                        重新產生
                    </button>
                </form>
                <form method="POST" action="/admin/cron/token/revoke"
                      onsubmit="return confirm('停用後，URL 觸發會立刻失效（端點回 404）。確定嗎？')">
                    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                    <button type="submit" class="px-3 py-1.5 text-xs rounded-lg bg-red-100 text-red-700 hover:bg-red-200 transition">
                        停用
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- 執行紀錄 -->
<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-200 dark:border-surface-border">
        <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100">最近執行紀錄</h3>
    </div>
    <div class="overflow-x-auto">
    <table class="w-full text-sm whitespace-nowrap">
        <thead class="bg-slate-50 dark:bg-white/5 border-b border-slate-200 dark:border-surface-border">
            <tr>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">開始時間</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">項目</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">來源</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">結果</th>
                <th class="text-right px-4 py-3 font-medium text-slate-500 dark:text-slate-400">耗時</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">輸出</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
            <?php if (empty($runs)): ?>
            <tr><td colspan="6" class="px-4 py-12 text-center text-slate-400">尚無執行紀錄</td></tr>
            <?php else: ?>
                <?php foreach ($runs as $r): ?>
                <?php
                $st = (string) $r['status'];
                $chip = match ($st) {
                    'success' => 'bg-blue-600 text-white dark:bg-blue-600',
                    'running' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
                    default   => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
                };
                $stLabel = match ($st) { 'success' => '成功', 'running' => '執行中', default => '失敗' };
                $srcLabel = match ((string) $r['source']) {
                    'http' => 'URL 觸發', 'admin' => '後台手動', default => '主機排程',
                };
                ?>
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5 transition align-top">
                    <td class="px-4 py-3 font-mono text-xs text-slate-700 dark:text-slate-200"><?= e(substr((string) $r['started_at'], 0, 19)) ?></td>
                    <td class="px-4 py-3 font-mono text-xs text-slate-700 dark:text-slate-200"><?= e((string) $r['command']) ?></td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                        <?= e($srcLabel) ?>
                        <?php if (!empty($r['actor_name'])): ?>
                        <span class="block text-xs text-slate-400"><?= e((string) $r['actor_name']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $chip ?>"><?= e($stLabel) ?></span>
                    </td>
                    <td class="px-4 py-3 text-right font-mono text-xs text-slate-600 dark:text-slate-300 tabular-nums">
                        <?= $r['duration_ms'] !== null ? e((string) $r['duration_ms']) . ' ms' : '—' ?>
                    </td>
                    <td class="px-4 py-3 whitespace-pre-wrap font-mono text-xs text-slate-500 dark:text-slate-400 max-w-lg"><?= e((string) ($r['output'] ?? '')) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

</div>
