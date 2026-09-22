<?php
/**
 * 客戶 Portal — 報價單檢視（含明細 + 付款入口 + 簽署連結）。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $quote          含 items
 * @var ?array $latestPayment
 * @var array  $company
 * @var array  $statusLabels
 */

use function YangSheep\CRM\Core\e;

$st = (string) $quote['status'];
$ps = (string) ($quote['payment_status'] ?? 'unpaid');
$paid = $ps === 'paid' || $st === 'paid';
$canPay = (int) ($quote['payment_enabled'] ?? 0) === 1 && !$paid;
$fmtMoney = static fn ($v) => 'NT$ ' . number_format((float) $v, 0);
$fmtDate = static fn (?string $d): string => ($d = substr((string) ($d ?? ''), 0, 10)) !== '' ? $d : '—';
$items = $quote['items'] ?? [];
?>

<div class="mb-5">
    <a href="/portal/quotes" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 flex items-center gap-1">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        返回報價列表
    </a>
</div>

<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6 mb-5">
    <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
        <div>
            <p class="font-mono text-xs text-slate-400"><?= e($quote['quote_number']) ?></p>
            <h2 class="text-xl font-bold text-slate-900 dark:text-slate-100 mt-0.5"><?= e($quote['title']) ?></h2>
        </div>
        <div class="text-right">
            <p class="text-2xl font-bold text-slate-900 dark:text-slate-100 tabular-nums"><?= e($fmtMoney($quote['total'])) ?></p>
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium mt-1 <?= $paid ? 'bg-blue-600 text-white' : 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' ?>">
                <?= $paid ? '已付款' : '未付款' ?>
            </span>
        </div>
    </div>

    <!-- 明細 -->
    <div class="overflow-x-auto border border-slate-200 dark:border-surface-border rounded-lg">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-white/5 text-slate-500 dark:text-slate-400">
            <tr>
                <th class="text-left px-3 py-2 font-medium">項目</th>
                <th class="text-right px-3 py-2 font-medium">數量</th>
                <th class="text-right px-3 py-2 font-medium">單價</th>
                <th class="text-right px-3 py-2 font-medium">小計</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
            <?php foreach ($items as $it): ?>
            <tr>
                <td class="px-3 py-2 text-slate-800 dark:text-slate-100">
                    <?= e($it['name']) ?>
                    <?php if (($it['description'] ?? '') !== ''): ?><p class="text-xs text-slate-400"><?= e($it['description']) ?></p><?php endif; ?>
                </td>
                <td class="px-3 py-2 text-right text-slate-600 dark:text-slate-300 tabular-nums"><?= e((string) ($it['qty'] ?? '')) ?> <?= e((string) ($it['unit'] ?? '')) ?></td>
                <td class="px-3 py-2 text-right text-slate-600 dark:text-slate-300 tabular-nums"><?= e($fmtMoney($it['unit_price'] ?? 0)) ?></td>
                <td class="px-3 py-2 text-right text-slate-800 dark:text-slate-100 font-medium tabular-nums"><?= e($fmtMoney($it['amount'] ?? 0)) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot class="border-t border-slate-200 dark:border-surface-border text-sm">
            <tr><td colspan="3" class="px-3 py-1.5 text-right text-slate-500 dark:text-slate-400">小計</td><td class="px-3 py-1.5 text-right text-slate-700 dark:text-slate-200 tabular-nums"><?= e($fmtMoney($quote['subtotal'] ?? 0)) ?></td></tr>
            <tr><td colspan="3" class="px-3 py-1.5 text-right text-slate-500 dark:text-slate-400">稅（<?= e((string) ($quote['tax_rate'] ?? 0)) ?>%）</td><td class="px-3 py-1.5 text-right text-slate-700 dark:text-slate-200 tabular-nums"><?= e($fmtMoney($quote['tax'] ?? 0)) ?></td></tr>
            <tr><td colspan="3" class="px-3 py-2 text-right font-semibold text-slate-800 dark:text-slate-100">總計</td><td class="px-3 py-2 text-right font-bold text-slate-900 dark:text-slate-100 tabular-nums"><?= e($fmtMoney($quote['total'])) ?></td></tr>
        </tfoot>
    </table>
    </div>

    <?php if (($quote['terms'] ?? '') !== ''): ?>
    <div class="mt-4 pt-4 border-t border-slate-100 dark:border-surface-border">
        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">條款</p>
        <p class="text-sm text-slate-600 dark:text-slate-300 whitespace-pre-line"><?= e($quote['terms']) ?></p>
    </div>
    <?php endif; ?>
</div>

<!-- 動作：簽署（未簽署）/ 付款（已啟用且未付款） -->
<div class="flex flex-wrap items-center gap-3">
    <?php if (!in_array($st, ['signed', 'paid', 'void'], true) && ($quote['access_token'] ?? '') !== ''): ?>
    <a href="/q/<?= e($quote['access_token']) ?>"
       class="inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
        前往線上簽署
    </a>
    <?php endif; ?>

    <?php if ($canPay && $st === 'signed'): ?>
    <form method="POST" action="/portal/quotes/<?= (int) $quote['id'] ?>/pay">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
        <button type="submit"
                class="inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            前往付款
        </button>
    </form>
    <?php elseif ($canPay && $st !== 'signed'): ?>
    <p class="text-sm text-slate-500 dark:text-slate-400">請先完成線上簽署後，即可進行付款。</p>
    <?php elseif ($paid): ?>
    <span class="inline-flex items-center gap-2 bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300 px-4 py-2 rounded-lg text-sm font-medium">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        此報價單已完成付款
    </span>
    <?php endif; ?>
</div>
