<?php
/**
 * 客戶 Portal — 我的付款記錄。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var array $payments
 * @var array $statusLabels
 */

use function YangSheep\CRM\Core\e;

$chip = [
    'pending' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    'awaiting_transfer' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    'paid' => 'bg-blue-600 text-white',
    'failed' => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
    'cancelled' => 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300',
    'refunded' => 'bg-slate-200 text-slate-700 dark:bg-white/10 dark:text-slate-200',
];
$fmtDate = static fn (?string $d): string => ($d = substr((string) ($d ?? ''), 0, 16)) !== '' ? str_replace('T', ' ', $d) : '—';
?>

<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-sm whitespace-nowrap">
        <thead class="bg-slate-50 dark:bg-white/5 border-b border-slate-200 dark:border-surface-border text-slate-500 dark:text-slate-400">
            <tr>
                <th class="text-left px-4 py-3 font-medium">付款編號</th>
                <th class="text-left px-4 py-3 font-medium">報價</th>
                <th class="text-right px-4 py-3 font-medium">金額</th>
                <th class="text-left px-4 py-3 font-medium">狀態</th>
                <th class="text-left px-4 py-3 font-medium">付款時間</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
            <?php if ($payments === []): ?>
            <tr><td colspan="5" class="px-4 py-12 text-center text-slate-400">尚無付款記錄</td></tr>
            <?php else: foreach ($payments as $p): $s = (string) $p['status']; ?>
            <tr class="hover:bg-slate-50 dark:hover:bg-white/5 transition">
                <td class="px-4 py-3 font-mono text-xs text-slate-700 dark:text-slate-300"><?= e($p['payment_no']) ?></td>
                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                    <?php if (($p['quote_id'] ?? null)): ?>
                        <a href="/portal/quotes/<?= (int) $p['quote_id'] ?>" class="text-blue-600 dark:text-blue-400 hover:underline"><?= e($p['quote_number'] ?? '報價') ?></a>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td class="px-4 py-3 text-right font-medium text-slate-800 dark:text-slate-100 tabular-nums"><?= e((string) ($p['currency'] ?? 'TWD')) ?> <?= e(number_format((float) $p['amount'], 0)) ?></td>
                <td class="px-4 py-3"><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $chip[$s] ?? $chip['pending'] ?>"><?= e($statusLabels[$s] ?? $s) ?></span></td>
                <td class="px-4 py-3 text-slate-500 dark:text-slate-400"><?= e($fmtDate($p['paid_at'] ?? null)) ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</div>
