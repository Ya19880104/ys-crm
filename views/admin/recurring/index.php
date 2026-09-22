<?php
/**
 * 週期帳務排程列表（對應架構設計 §7.8 週期單、§7.9）。
 *
 * 配色規範（嚴禁綠色）：啟用=實心藍、停用=slate、下次產生即將到=amber。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $_flash
 * @var array  $schedules       每筆含 quote_number / quote_title / customer_name
 * @var int    $total
 * @var int    $page
 * @var int    $perPage
 * @var int    $totalPages
 * @var array  $filters         is_active / customer_id
 * @var array  $customers       [{id, display_name}]
 * @var array  $unitLabels      day/month/year => 中文
 * @var array  $modeLabels      payment_mode => 中文
 * @var bool   $canManage
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

$curActive   = (string) ($filters['is_active'] ?? '');
$curCustomer = (string) ($filters['customer_id'] ?? '');
$hasFilter   = $curActive !== '' || ($curCustomer !== '' && $curCustomer !== '0');

$fmtDate = static function (?string $d): string {
    $d = (string) ($d ?? '');
    return $d !== '' ? substr($d, 0, 10) : '—';
};
$fmtMoney = static function ($v, ?string $cur): string {
    return ($cur ?: 'TWD') . ' ' . number_format((float) $v, 0);
};
$today = date('Y-m-d');
?>

<!-- 操作列 -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <p class="text-slate-500 dark:text-slate-400 text-sm">共 <?= e((string) $total) ?> 個週期排程</p>
    <?php if ($canManage): ?>
    <a href="/admin/recurring/create"
       class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        建立週期排程
    </a>
    <?php endif; ?>
</div>

<!-- 篩選列 -->
<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-4 mb-5">
    <form method="GET" action="/admin/recurring" class="ys-toolbar flex flex-wrap items-end gap-3">
        <div class="min-w-[140px]">
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">狀態</label>
            <select name="is_active"
                    class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="">全部</option>
                <option value="1" <?= $curActive === '1' ? 'selected' : '' ?>>啟用中</option>
                <option value="0" <?= $curActive === '0' ? 'selected' : '' ?>>已停用</option>
            </select>
        </div>
        <div class="min-w-[180px]">
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">客戶</label>
            <select name="customer_id"
                    class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="">全部客戶</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= e((string) $c['id']) ?>" <?= $curCustomer === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['display_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="px-4 py-2 bg-slate-800 dark:bg-white/10 text-white rounded-lg text-sm font-medium hover:bg-slate-900 dark:hover:bg-white/20 transition">篩選</button>
            <?php if ($hasFilter): ?>
            <a href="/admin/recurring" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400">清除</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- 列表 -->
<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-sm whitespace-nowrap">
        <thead class="bg-slate-50 dark:bg-white/5 border-b border-slate-200 dark:border-surface-border">
            <tr>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">來源報價</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">客戶</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">週期</th>
                <th class="text-right px-4 py-3 font-medium text-slate-500 dark:text-slate-400">金額</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">付款方式</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">下次產生</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">狀態</th>
                <th class="text-right px-4 py-3 font-medium text-slate-500 dark:text-slate-400">操作</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
            <?php if (empty($schedules)): ?>
            <tr><td colspan="8" class="px-4 py-12 text-center text-slate-400">尚無週期排程</td></tr>
            <?php else: ?>
                <?php foreach ($schedules as $s): ?>
                <?php
                $sid    = (int) $s['id'];
                $active = (int) ($s['is_active'] ?? 0) === 1;
                $unit   = (string) $s['interval_unit'];
                $val    = (int) $s['interval_value'];
                $nextRun = $fmtDate($s['next_run_at'] ?? null);
                // 下次產生即將到（<= 今日 + 7 天）→ amber 標示。
                $soon = ($s['next_run_at'] ?? '') !== '' && substr((string) $s['next_run_at'], 0, 10) <= date('Y-m-d', strtotime('+7 days'));
                ?>
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5 transition">
                    <td class="px-4 py-3">
                        <a href="/admin/recurring/<?= $sid ?>" class="font-medium text-slate-900 dark:text-slate-100 hover:text-blue-600 dark:hover:text-blue-400">
                            <?php if (($s['quote_number'] ?? '') !== ''): ?>
                                <span class="font-mono text-xs text-blue-600 dark:text-blue-400"><?= e($s['quote_number']) ?></span>
                            <?php else: ?>
                                <span class="text-slate-400">（來源已刪除）</span>
                            <?php endif; ?>
                        </a>
                        <?php if (($s['quote_title'] ?? '') !== ''): ?>
                        <span class="block text-xs text-slate-400 max-w-[200px] truncate" title="<?= e($s['quote_title']) ?>"><?= e($s['quote_title']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-slate-700 dark:text-slate-300">
                        <?= ($s['customer_name'] ?? '') !== '' ? e($s['customer_name']) : '<span class="text-slate-400">—</span>' ?>
                    </td>
                    <td class="px-4 py-3 text-slate-700 dark:text-slate-300">每 <?= e((string) $val) ?> <?= e($unitLabels[$unit] ?? $unit) ?></td>
                    <td class="px-4 py-3 text-right font-medium text-slate-800 dark:text-slate-100"><?= e($fmtMoney($s['quote_total'] ?? 0, $s['quote_currency'] ?? 'TWD')) ?></td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= ($s['payment_mode'] ?? '') === 'auto_card' ? 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' : 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300' ?>">
                            <?= e($modeLabels[$s['payment_mode']] ?? $s['payment_mode']) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <?php if ($active && $soon): ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300"><?= e($nextRun) ?></span>
                        <?php else: ?>
                        <span class="text-slate-600 dark:text-slate-300 text-xs"><?= e($nextRun) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <?php if ($active): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-600 text-white">啟用中</span>
                        <?php else: ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-500 dark:bg-white/5 dark:text-slate-400">已停用</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-3">
                            <a href="/admin/recurring/<?= $sid ?>" class="text-slate-500 dark:text-slate-400 hover:text-slate-700 text-xs font-medium">檢視</a>
                            <?php if ($canManage): ?>
                            <a href="/admin/recurring/<?= $sid ?>/edit" class="text-slate-500 dark:text-slate-400 hover:text-slate-700 text-xs font-medium">編輯</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if ($totalPages > 1): ?>
    <?php
    $pageBase = array_filter([
        'is_active'   => $curActive,
        'customer_id' => ($curCustomer !== '' && $curCustomer !== '0') ? $curCustomer : '',
    ], static fn ($v) => $v !== '');
    $baseUrl = '/admin/recurring' . ($pageBase ? '?' . http_build_query($pageBase) : '');
    ?>
    <?php $view->partial('pagination', ['page' => $page, 'totalPages' => $totalPages, 'baseUrl' => $baseUrl]); ?>
<?php endif; ?>
