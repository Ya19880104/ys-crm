<?php
/**
 * 稽核紀錄：操作紀錄與登入紀錄兩個頁簽。
 *
 * 頁簽以 `?type=` 切換（伺服器端），因為兩邊各有自己的篩選與分頁 ——
 * 網址就是狀態，上一頁與分享連結才會正確。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $type            audit | login
 * @var array  $logs            操作紀錄
 * @var array  $attempts        登入紀錄
 * @var array  $locations       ip => 位置描述
 * @var int    $total
 * @var int    $page
 * @var int    $totalPages
 * @var string $action          操作紀錄的動作篩選
 * @var string $result          登入紀錄的結果篩選
 * @var string $keyword         登入紀錄的關鍵字
 * @var bool   $clientIpIsReal
 * @var bool   $geoEnabled
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

$isLogin = $type === 'login';

/** 保留另一個頁簽的篩選條件，切換時不會互相洗掉。 */
$qs = static function (array $override) use ($type, $action, $result, $keyword): string {
    $params = array_filter([
        'type'    => $override['type']    ?? $type,
        'page'    => $override['page']    ?? null,
        'action'  => $override['action']  ?? $action,
        'result'  => $override['result']  ?? $result,
        'keyword' => $override['keyword'] ?? $keyword,
    ], static fn($v): bool => $v !== null && $v !== '' && $v !== 'audit');

    return '/admin/audit-logs' . ($params === [] ? '' : '?' . http_build_query($params));
};

$fmtDt = static fn (?string $d): string => ($d = (string) ($d ?? '')) !== '' ? substr($d, 0, 19) : '—';
?>

<div class="space-y-4">

    <!-- 頁簽 -->
    <div class="ys-tabs-wrap">
        <div class="ys-tabs" role="tablist">
            <?php foreach ([
                ['audit', '操作紀錄'],
                ['login', '登入紀錄'],
            ] as [$key, $label]): ?>
            <?php $active = $type === $key; ?>
            <a href="<?= e($qs(['type' => $key, 'page' => null])) ?>"
               role="tab" aria-selected="<?= $active ? 'true' : 'false' ?>"
               class="ys-tab<?= $active ? ' is-active' : '' ?>">
                <?= e($label) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <span class="text-sm text-slate-500 dark:text-slate-400 pb-2">共 <?= e((string) $total) ?> 筆</span>
    </div>

    <?php if ($isLogin && !$clientIpIsReal): ?>
    <?php /* 不標示的話，整欄都是同一個內網 IP，看的人會以為所有人從同一處登入。 */ ?>
    <div class="bg-amber-50 dark:bg-amber-500/10 border border-amber-300 dark:border-amber-500/30
                text-amber-800 dark:text-amber-200 rounded-xl px-4 py-3 text-sm">
        <p class="font-medium mb-1">目前記錄到的是反向代理位址，不是訪客的真實 IP</p>
        <p class="text-xs leading-relaxed">
            前端設備尚未把訪客位址寫進 HTTP header。
            請至 <a href="/admin/settings" class="underline">系統設定 → 網路與 CDN</a>，
            該頁會列出每一種偵測方式現在會得到什麼 IP，可據以選擇。
        </p>
    </div>
    <?php endif; ?>

    <!-- 篩選 -->
    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-4">
        <form method="GET" action="/admin/audit-logs" class="ys-toolbar flex flex-wrap items-end gap-3">
            <input type="hidden" name="type" value="<?= e($type) ?>">

            <?php if ($isLogin): ?>
                <div class="min-w-[140px]">
                    <label for="result" class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">結果</label>
                    <select name="result" id="result"
                            class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm">
                        <option value="">全部</option>
                        <option value="success" <?= $result === 'success' ? 'selected' : '' ?>>成功</option>
                        <option value="failed"  <?= $result === 'failed'  ? 'selected' : '' ?>>失敗</option>
                    </select>
                </div>
                <div class="min-w-[220px] flex-1">
                    <label for="keyword" class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">搜尋（帳號 / 位址）</label>
                    <input type="text" name="keyword" id="keyword" value="<?= e($keyword) ?>" maxlength="100"
                           placeholder="admin 或 203.0.113."
                           class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm">
                </div>
            <?php else: ?>
                <div class="min-w-[240px] flex-1">
                    <label for="action" class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">動作</label>
                    <input type="text" name="action" id="action" value="<?= e($action) ?>" maxlength="100"
                           placeholder="payment_refunded、login_success…"
                           class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm">
                </div>
            <?php endif; ?>

            <div class="flex items-center gap-2">
                <button type="submit" class="px-4 py-2 bg-slate-800 dark:bg-white/10 text-white rounded-lg text-sm font-medium transition">篩選</button>
                <?php if ($action !== '' || $result !== '' || $keyword !== ''): ?>
                <a href="<?= e($qs(['action' => '', 'result' => '', 'keyword' => '', 'page' => null])) ?>"
                   class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400">清除</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- 列表 -->
    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden transition-colors">
        <div class="overflow-x-auto">
        <table class="w-full text-sm whitespace-nowrap">
            <thead class="bg-slate-50 dark:bg-white/5 text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-surface-border">
                <tr>
                    <?php if ($isLogin): ?>
                        <th class="text-left font-medium px-4 py-3">時間</th>
                        <th class="text-left font-medium px-4 py-3">結果</th>
                        <th class="text-left font-medium px-4 py-3">輸入的帳號 / Email</th>
                        <th class="text-left font-medium px-4 py-3">來源位址</th>
                        <th class="text-left font-medium px-4 py-3">位置</th>
                    <?php else: ?>
                        <th class="text-left font-medium px-4 py-3">時間</th>
                        <th class="text-left font-medium px-4 py-3">操作者</th>
                        <th class="text-left font-medium px-4 py-3">動作</th>
                        <th class="text-left font-medium px-4 py-3">目標</th>
                        <th class="text-left font-medium px-4 py-3">IP</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
                <?php if ($isLogin): ?>
                    <?php if (empty($attempts)): ?>
                        <tr><td colspan="5" class="px-4 py-12 text-center text-slate-400">尚無登入紀錄</td></tr>
                    <?php else: ?>
                        <?php foreach ($attempts as $a): ?>
                        <?php
                        $ok  = (int) ($a['success'] ?? 0) === 1;
                        $ip  = (string) ($a['ip_address'] ?? '');
                        $loc = $locations[$ip] ?? ['kind' => 'unknown', 'label' => '—', 'detail' => ''];
                        ?>
                        <tr class="hover:bg-slate-50 dark:hover:bg-white/5 transition">
                            <td class="px-4 py-3 font-mono text-xs text-slate-700 dark:text-slate-200"><?= e($fmtDt($a['attempted_at'] ?? '')) ?></td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                    <?= $ok
                                        ? 'bg-blue-600 text-white dark:bg-blue-600 dark:text-white'
                                        : 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300' ?>">
                                    <?= $ok ? '成功' : '失敗' ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-700 dark:text-slate-200"><?= e((string) ($a['username'] ?? '—')) ?></td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-700 dark:text-slate-200"><?= e($ip !== '' ? $ip : '—') ?></td>
                            <td class="px-4 py-3">
                                <span class="<?= ($loc['kind'] ?? '') === 'located' ? 'text-slate-700 dark:text-slate-200' : 'text-slate-400' ?>"><?= e($loc['label'] ?? '—') ?></span>
                                <?php if (($loc['detail'] ?? '') !== ''): ?>
                                <span class="block text-xs text-slate-400"><?= e($loc['detail']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="5" class="px-4 py-12 text-center text-slate-400">尚無紀錄</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <tr class="hover:bg-slate-50 dark:hover:bg-white/5 transition">
                            <td class="px-4 py-3 font-mono text-xs text-slate-700 dark:text-slate-200"><?= e($fmtDt($log['created_at'] ?? '')) ?></td>
                            <td class="px-4 py-3 text-slate-700 dark:text-slate-200">
                                <?= e((string) ($log['display_name'] ?? $log['username'] ?? ('#' . ($log['user_id'] ?? 0)))) ?>
                            </td>
                            <td class="px-4 py-3">
                                <a href="<?= e($qs(['type' => 'audit', 'action' => (string) ($log['action'] ?? ''), 'page' => null])) ?>"
                                   class="inline-block px-2 py-0.5 rounded bg-blue-50 dark:bg-brand-light/15 text-blue-700 dark:text-brand-dark text-xs hover:underline"
                                   title="只看這個動作"><?= e((string) ($log['action'] ?? '')) ?></a>
                            </td>
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400">
                                <?php $tt = $log['target_type'] ?? ''; $ti = $log['target_id'] ?? ''; ?>
                                <?= $tt !== '' ? e((string) $tt) . ($ti !== '' ? ' #' . e((string) $ti) : '') : '—' ?>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-400"><?= e((string) ($log['ip_address'] ?? '')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="flex items-center justify-between text-sm">
        <span class="text-slate-500 dark:text-slate-400">第 <?= e((string) $page) ?> / <?= e((string) $totalPages) ?> 頁</span>
        <div class="flex gap-2">
            <?php if ($page > 1): ?>
            <a href="<?= e($qs(['page' => $page - 1])) ?>" class="px-3 py-1.5 rounded-lg border border-slate-300 dark:border-surface-border text-slate-600 dark:text-slate-300">上一頁</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
            <a href="<?= e($qs(['page' => $page + 1])) ?>" class="px-3 py-1.5 rounded-lg border border-slate-300 dark:border-surface-border text-slate-600 dark:text-slate-300">下一頁</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isLogin && !$geoEnabled): ?>
    <p class="text-xs text-slate-400">
        地理位置查詢預設關閉（查詢會把 IP 送到第三方服務）。需要時於「系統設定 → 安全設定」開啟。
    </p>
    <?php endif; ?>

</div>
