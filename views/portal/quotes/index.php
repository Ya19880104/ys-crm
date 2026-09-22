<?php
/**
 * 客戶 Portal — 我的報價單列表。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var array $quotes
 * @var array $statusLabels
 */

use function YangSheep\CRM\Core\e;

$statusChip = [
    'draft' => 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300',
    'sent' => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
    'viewed' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300',
    'signed' => 'bg-blue-600 text-white dark:bg-blue-500',
    'paid' => 'bg-blue-800 text-white dark:bg-blue-700',
    'expired' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    'void' => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
];
$payChip = [
    'unpaid' => 'bg-slate-100 text-slate-500 dark:bg-white/5 dark:text-slate-400',
    'partial' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    'paid' => 'bg-blue-600 text-white',
];
$payLabel = ['unpaid' => '未付款', 'partial' => '部分付款', 'paid' => '已付款'];
$fmtDate = static fn (?string $d): string => ($d = substr((string) ($d ?? ''), 0, 10)) !== '' ? $d : '—';
?>

<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-sm whitespace-nowrap">
        <thead class="bg-slate-50 dark:bg-white/5 border-b border-slate-200 dark:border-surface-border text-slate-500 dark:text-slate-400">
            <tr>
                <th class="text-left px-4 py-3 font-medium">編號</th>
                <th class="text-left px-4 py-3 font-medium">標題</th>
                <th class="text-right px-4 py-3 font-medium">金額</th>
                <th class="text-left px-4 py-3 font-medium">狀態</th>
                <th class="text-left px-4 py-3 font-medium">付款</th>
                <th class="text-left px-4 py-3 font-medium">有效期限</th>
                <th class="text-right px-4 py-3 font-medium">操作</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
            <?php if ($quotes === []): ?>
            <tr><td colspan="7" class="px-4 py-12 text-center text-slate-400">尚無報價單</td></tr>
            <?php else: foreach ($quotes as $q): $st = (string) $q['status']; $ps = (string) ($q['payment_status'] ?? 'unpaid'); ?>
            <tr class="hover:bg-slate-50 dark:hover:bg-white/5 transition">
                <td class="px-4 py-3"><a href="/portal/quotes/<?= (int) $q['id'] ?>" class="font-mono text-xs text-blue-600 dark:text-blue-400 hover:underline"><?= e($q['quote_number']) ?></a></td>
                <td class="px-4 py-3 max-w-[220px] truncate"><a href="/portal/quotes/<?= (int) $q['id'] ?>" class="font-medium text-slate-800 dark:text-slate-100 hover:text-blue-600" title="<?= e($q['title']) ?>"><?= e($q['title']) ?></a></td>
                <td class="px-4 py-3 text-right font-medium text-slate-800 dark:text-slate-100 tabular-nums"><?= e((string) ($q['currency'] ?? 'TWD')) ?> <?= e(number_format((float) $q['total'], 0)) ?></td>
                <td class="px-4 py-3"><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $statusChip[$st] ?? $statusChip['draft'] ?>"><?= e($statusLabels[$st] ?? $st) ?></span></td>
                <td class="px-4 py-3"><span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= $payChip[$ps] ?? $payChip['unpaid'] ?>"><?= e($payLabel[$ps] ?? $ps) ?></span></td>
                <td class="px-4 py-3 text-slate-600 dark:text-slate-300"><?= e($fmtDate($q['valid_until'] ?? null)) ?></td>
                <td class="px-4 py-3 text-right"><a href="/portal/quotes/<?= (int) $q['id'] ?>" class="text-blue-600 dark:text-blue-400 hover:underline text-xs font-medium">檢視</a></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</div>
