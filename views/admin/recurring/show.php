<?php
/**
 * 週期排程詳情（對應架構設計 §7.8）。顯示排程設定、已產生的週期帳單，並可「立即產生」。
 *
 * 配色規範（嚴禁綠色）：啟用/已付=實心藍、待收=amber、作廢=red、其他=slate。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $_flash
 * @var array  $schedule    含 quote_number / quote_title / customer_name / quote_total / quote_currency
 * @var array  $generated   已產生的週期報價 [{id, quote_number, total, currency, status, payment_status, created_at}]
 * @var array  $unitLabels
 * @var array  $modeLabels
 * @var bool   $canManage
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

$sid    = (int) $schedule['id'];
$active = (int) ($schedule['is_active'] ?? 0) === 1;
$unit   = (string) $schedule['interval_unit'];
$val    = (int) $schedule['interval_value'];

$fmtDate = static function (?string $d): string {
    $d = (string) ($d ?? '');
    return $d !== '' ? substr($d, 0, 10) : '—';
};
$fmtDateTime = static function (?string $d): string {
    $d = (string) ($d ?? '');
    return $d !== '' ? substr($d, 0, 16) : '—';
};
$fmtMoney = static function ($v, ?string $cur): string {
    return ($cur ?: 'TWD') . ' ' . number_format((float) $v, 0);
};

// 報價狀態 badge（與 quotes/index 一致）。
$quoteStatusChip = [
    'draft'   => 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300',
    'sent'    => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
    'viewed'  => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300',
    'signed'  => 'bg-blue-600 text-white dark:bg-blue-600 dark:text-white',
    'paid'    => 'bg-blue-800 text-white dark:bg-blue-700 dark:text-white',
    'expired' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    'void'    => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
];
$quoteStatusLabels = [
    'draft' => '草稿', 'sent' => '已送出', 'viewed' => '已檢視',
    'signed' => '已簽署', 'paid' => '已付款', 'expired' => '已逾期', 'void' => '作廢',
];
$payStatusChip = [
    'unpaid'  => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    'partial' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    'paid'    => 'bg-blue-600 text-white dark:bg-blue-600 dark:text-white',
];
$payStatusLabels = ['unpaid' => '未付款', 'partial' => '部分付款', 'paid' => '已付款'];
?>

<a href="/admin/recurring" class="inline-flex items-center gap-1 text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 mb-4">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    返回週期帳務
</a>

<!-- 排程設定卡 -->
<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden mb-6">
    <div class="px-6 py-4 border-b border-slate-200 dark:border-surface-border flex items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100">週期排程 #<?= $sid ?></h3>
            <?php if ($active): ?>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-600 text-white">啟用中</span>
            <?php else: ?>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-500 dark:bg-white/5 dark:text-slate-400">已停用</span>
            <?php endif; ?>
        </div>
        <div class="flex items-center gap-2">
            <?php if ($canManage): ?>
            <form method="POST" action="/admin/recurring/<?= $sid ?>/generate" class="inline"
                  onsubmit="return confirm('立即產生本期帳單與待付款？（若本期已產生則略過）')">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition">立即產生本期帳單</button>
            </form>
            <a href="/admin/recurring/<?= $sid ?>/edit" class="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-surface-border hover:bg-slate-100 dark:hover:bg-white/5 transition">編輯</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="p-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-4 text-sm">
        <div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-0.5">來源報價</p>
            <p class="text-slate-800 dark:text-slate-100">
                <?php if (($schedule['quote_number'] ?? '') !== ''): ?>
                <a href="/admin/quotes/<?= (int) ($schedule['quote_id'] ?? 0) ?>" class="font-mono text-blue-600 dark:text-blue-400 hover:underline"><?= e($schedule['quote_number']) ?></a>
                <?php else: ?>
                <span class="text-slate-400">（來源已刪除）</span>
                <?php endif; ?>
            </p>
        </div>
        <div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-0.5">客戶</p>
            <p class="text-slate-800 dark:text-slate-100"><?= ($schedule['customer_name'] ?? '') !== '' ? e($schedule['customer_name']) : '—' ?></p>
        </div>
        <div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-0.5">每期金額</p>
            <p class="text-slate-800 dark:text-slate-100 font-medium"><?= e($fmtMoney($schedule['quote_total'] ?? 0, $schedule['quote_currency'] ?? 'TWD')) ?></p>
        </div>
        <div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-0.5">週期</p>
            <p class="text-slate-800 dark:text-slate-100">每 <?= e((string) $val) ?> <?= e($unitLabels[$unit] ?? $unit) ?></p>
        </div>
        <div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-0.5">下次產生</p>
            <p class="text-slate-800 dark:text-slate-100"><?= e($fmtDate($schedule['next_run_at'] ?? null)) ?></p>
        </div>
        <div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-0.5">最近產生</p>
            <p class="text-slate-800 dark:text-slate-100"><?= e($fmtDate($schedule['last_generated_at'] ?? null)) ?></p>
        </div>
        <div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-0.5">付款方式</p>
            <p class="text-slate-800 dark:text-slate-100"><?= e($modeLabels[$schedule['payment_mode']] ?? $schedule['payment_mode']) ?></p>
        </div>
        <div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-0.5">提前產生天數</p>
            <p class="text-slate-800 dark:text-slate-100"><?= $schedule['advance_generate_days'] !== null ? e((string) $schedule['advance_generate_days']) . ' 天' : '系統預設' ?></p>
        </div>
        <div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-0.5">自動扣款延遲</p>
            <p class="text-slate-800 dark:text-slate-100"><?= $schedule['auto_charge_after_days'] !== null ? e((string) $schedule['auto_charge_after_days']) . ' 天' : '系統預設' ?></p>
        </div>
    </div>
    <?php if (($schedule['payment_mode'] ?? '') === 'auto_card' && (int) ($schedule['payment_method_id'] ?? 0) === 0): ?>
    <div class="px-6 pb-5">
        <div class="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 text-amber-700 dark:text-amber-300 px-4 py-3 rounded-lg text-sm">
            此排程設為「綁卡自動扣款」，但尚未綁定卡片（客戶專區綁卡為 P4-2 功能）。到達扣款時點時將以「待人工」提醒處理，不會自動扣款。
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- 已產生的週期帳單 -->
<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-200 dark:border-surface-border">
        <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100">已產生的週期帳單（<?= e((string) count($generated)) ?>）</h3>
    </div>
    <div class="overflow-x-auto">
    <table class="w-full text-sm whitespace-nowrap">
        <thead class="bg-slate-50 dark:bg-white/5 border-b border-slate-200 dark:border-surface-border">
            <tr>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">報價編號</th>
                <th class="text-right px-4 py-3 font-medium text-slate-500 dark:text-slate-400">金額</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">報價狀態</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">付款狀態</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">產生時間</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
            <?php if (empty($generated)): ?>
            <tr><td colspan="5" class="px-4 py-10 text-center text-slate-400">尚未產生任何週期帳單</td></tr>
            <?php else: ?>
                <?php foreach ($generated as $g): ?>
                <?php $gst = (string) $g['status']; $gps = (string) ($g['payment_status'] ?? 'unpaid'); ?>
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5 transition">
                    <td class="px-4 py-3">
                        <a href="/admin/quotes/<?= (int) $g['id'] ?>" class="font-mono text-xs text-blue-600 dark:text-blue-400 hover:underline"><?= e($g['quote_number']) ?></a>
                    </td>
                    <td class="px-4 py-3 text-right font-medium text-slate-800 dark:text-slate-100"><?= e($fmtMoney($g['total'] ?? 0, $g['currency'] ?? 'TWD')) ?></td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $quoteStatusChip[$gst] ?? $quoteStatusChip['draft'] ?>"><?= e($quoteStatusLabels[$gst] ?? $gst) ?></span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $payStatusChip[$gps] ?? $payStatusChip['unpaid'] ?>"><?= e($payStatusLabels[$gps] ?? $gps) ?></span>
                    </td>
                    <td class="px-4 py-3 text-slate-500 dark:text-slate-400 text-xs"><?= e($fmtDateTime($g['created_at'] ?? null)) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
