<?php
/**
 * 客戶 Portal 總覽。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var array  $quotes
 * @var array  $pendingPayments
 * @var float  $pendingAmount
 * @var array  $awaitingSign
 * @var int    $websiteCount
 * @var int    $hostingCount
 * @var array  $expiringAssets
 * @var array  $quoteStatusLabels
 * @var array  $_customer
 * @var callable $_can
 */

use function YangSheep\CRM\Core\e;

$can = $_can ?? static fn (string $s): bool => false;
$name = (string) ($_customer['display_name'] ?? '');

$statusChip = [
    'draft' => 'bg-slate-100 text-slate-600', 'sent' => 'bg-blue-100 text-blue-700',
    'viewed' => 'bg-indigo-100 text-indigo-700', 'signed' => 'bg-blue-600 text-white',
    'paid' => 'bg-blue-800 text-white', 'expired' => 'bg-amber-100 text-amber-800',
    'void' => 'bg-red-100 text-red-700',
];
$fmtDate = static fn (?string $d): string => ($d = substr((string) ($d ?? ''), 0, 10)) !== '' ? $d : '—';
?>

<p class="text-slate-500 dark:text-slate-400 text-sm mb-5">您好，<?= e($name) ?>。以下是您的帳戶概況。</p>

<!-- 摘要卡 -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <?php if ($can('payments')): ?>
    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-5">
        <p class="text-xs text-slate-500 dark:text-slate-400">未付款項</p>
        <p class="mt-1 text-2xl font-bold text-slate-900 dark:text-slate-100 tabular-nums">
            <?= e('NT$ ' . number_format($pendingAmount, 0)) ?>
        </p>
        <p class="text-xs text-slate-400 mt-1"><?= count($pendingPayments) ?> 筆待付款</p>
    </div>
    <?php endif; ?>
    <?php if ($can('quotes')): ?>
    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-5">
        <p class="text-xs text-slate-500 dark:text-slate-400">待簽署報價</p>
        <p class="mt-1 text-2xl font-bold text-slate-900 dark:text-slate-100"><?= count($awaitingSign) ?></p>
        <p class="text-xs text-slate-400 mt-1">共 <?= count($quotes) ?> 張報價單</p>
    </div>
    <?php endif; ?>
    <?php if ($can('assets')): ?>
    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-5">
        <p class="text-xs text-slate-500 dark:text-slate-400">網站</p>
        <p class="mt-1 text-2xl font-bold text-slate-900 dark:text-slate-100"><?= (int) $websiteCount ?></p>
    </div>
    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-5">
        <p class="text-xs text-slate-500 dark:text-slate-400">主機</p>
        <p class="mt-1 text-2xl font-bold text-slate-900 dark:text-slate-100"><?= (int) $hostingCount ?></p>
    </div>
    <?php endif; ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
    <!-- 待簽署 / 最近報價 -->
    <?php if ($can('quotes')): ?>
    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-5">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300">最近報價單</h3>
            <a href="/portal/quotes" class="text-xs text-blue-600 dark:text-blue-400 hover:underline">查看全部</a>
        </div>
        <?php $recent = array_slice($quotes, 0, 5); ?>
        <?php if ($recent === []): ?>
            <p class="text-sm text-slate-400 py-6 text-center">尚無報價單</p>
        <?php else: ?>
        <div class="space-y-2">
            <?php foreach ($recent as $q): $st = (string) $q['status']; ?>
            <a href="/portal/quotes/<?= (int) $q['id'] ?>" class="flex items-center justify-between gap-3 p-3 border border-slate-200 dark:border-surface-border rounded-lg hover:bg-slate-50 dark:hover:bg-white/5 transition">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate"><?= e($q['title']) ?></p>
                    <p class="text-xs text-slate-400 font-mono"><?= e($q['quote_number']) ?></p>
                </div>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium flex-shrink-0 <?= $statusChip[$st] ?? $statusChip['draft'] ?>">
                    <?= e($quoteStatusLabels[$st] ?? $st) ?>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- 即將到期資產 -->
    <?php if ($can('assets')): ?>
    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-5">
        <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-3">即將到期（60 天內）</h3>
        <?php if ($expiringAssets === []): ?>
            <p class="text-sm text-slate-400 py-6 text-center">近期無到期項目</p>
        <?php else: ?>
        <div class="space-y-2">
            <?php foreach ($expiringAssets as $a): ?>
            <div class="flex items-center justify-between gap-3 p-3 border border-amber-200 dark:border-amber-500/30 bg-amber-50/50 dark:bg-amber-500/5 rounded-lg">
                <div class="min-w-0">
                    <p class="text-sm text-slate-800 dark:text-slate-100 truncate"><?= e($a['name']) ?></p>
                    <p class="text-xs text-slate-400"><?= e($a['type']) ?></p>
                </div>
                <span class="text-xs font-medium text-amber-700 dark:text-amber-300 flex-shrink-0"><?= e($a['due']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
