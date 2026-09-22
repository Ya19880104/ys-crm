<?php
/**
 * 客戶 Portal — 我的主機與網站（唯讀）。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var array $websites
 * @var array $hostings
 * @var array $assetStatusLabels
 * @var array $caseTypeLabels
 * @var array $hostingTypeLabels
 */

use function YangSheep\CRM\Core\e;
use YangSheep\CRM\Asset\AssetHelper;

$fmtDate = static fn (?string $d): string => ($d = substr((string) ($d ?? ''), 0, 10)) !== '' ? $d : '—';

$statusBadge = static function (string $status, ?string $due) use ($assetStatusLabels): string {
    if (AssetHelper::isOverdue($status, $due)) {
        return '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300">已逾期</span>';
    }
    if (AssetHelper::isExpiringSoon($status, $due, 30)) {
        return '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">即將到期</span>';
    }
    $cls = $status === 'active'
        ? 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300'
        : 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300';
    $label = $assetStatusLabels[$status] ?? $status;
    return '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ' . $cls . '">' . e($label) . '</span>';
};
?>

<!-- 網站 -->
<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden mb-6">
    <div class="px-5 py-4 border-b border-slate-200 dark:border-surface-border">
        <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300">網站（<?= count($websites) ?>）</h3>
    </div>
    <div class="overflow-x-auto">
    <table class="w-full text-sm whitespace-nowrap">
        <thead class="bg-slate-50 dark:bg-white/5 text-slate-500 dark:text-slate-400">
            <tr>
                <th class="text-left px-4 py-2.5 font-medium">網址</th>
                <th class="text-left px-4 py-2.5 font-medium">案件類型</th>
                <th class="text-left px-4 py-2.5 font-medium">合約到期</th>
                <th class="text-left px-4 py-2.5 font-medium">狀態</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
            <?php if ($websites === []): ?>
            <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">尚無網站</td></tr>
            <?php else: foreach ($websites as $w): $url = trim((string) ($w['url'] ?? '')); ?>
            <tr>
                <td class="px-4 py-2.5 max-w-[260px] truncate">
                    <?php if ($url !== ''): ?><a href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 hover:underline" title="<?= e($url) ?>"><?= e($url) ?></a><?php else: ?><span class="text-slate-300">—</span><?php endif; ?>
                </td>
                <td class="px-4 py-2.5 text-slate-600 dark:text-slate-300"><?= e($caseTypeLabels[$w['case_type']] ?? (string) $w['case_type']) ?></td>
                <td class="px-4 py-2.5 text-slate-600 dark:text-slate-300"><?= e($fmtDate($w['contract_end'] ?? null)) ?></td>
                <td class="px-4 py-2.5"><?= $statusBadge((string) $w['status'], $w['contract_end'] ?? null) ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- 主機 -->
<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-200 dark:border-surface-border">
        <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300">主機（<?= count($hostings) ?>）</h3>
    </div>
    <div class="overflow-x-auto">
    <table class="w-full text-sm whitespace-nowrap">
        <thead class="bg-slate-50 dark:bg-white/5 text-slate-500 dark:text-slate-400">
            <tr>
                <th class="text-left px-4 py-2.5 font-medium">類型</th>
                <th class="text-left px-4 py-2.5 font-medium">IP 位址</th>
                <th class="text-left px-4 py-2.5 font-medium">租期到期</th>
                <th class="text-left px-4 py-2.5 font-medium">狀態</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
            <?php if ($hostings === []): ?>
            <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">尚無主機</td></tr>
            <?php else: foreach ($hostings as $h): $ip = trim((string) ($h['ip_address'] ?? '')); ?>
            <tr>
                <td class="px-4 py-2.5 text-slate-600 dark:text-slate-300"><?= e($hostingTypeLabels[$h['type']] ?? (string) $h['type']) ?></td>
                <td class="px-4 py-2.5 text-slate-600 dark:text-slate-300 font-mono text-xs"><?= $ip !== '' ? e($ip) : '—' ?></td>
                <td class="px-4 py-2.5 text-slate-600 dark:text-slate-300"><?= e($fmtDate($h['end_date'] ?? null)) ?></td>
                <td class="px-4 py-2.5"><?= $statusBadge((string) $h['status'], $h['end_date'] ?? null) ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</div>
