<?php
/**
 * 付款記錄列表（對應架構設計 §7.9）。
 * 付款編號 / 報價 / 客戶 / 金流商 / 金額 / 狀態 / 交易序號 / 日期 + 篩選（金流商 / 狀態 / 客戶 / search）。
 *
 * 深藍雙主題、嚴禁綠色：已付款用實心藍 bg-blue-600；待付款用 slate；失敗/取消用 red；退款用 indigo。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $payments        每筆含 quote_number、customer_name
 * @var int    $total
 * @var int    $page
 * @var int    $perPage
 * @var int    $totalPages
 * @var array  $filters         provider / status / customer_id / keyword
 * @var array  $customers       [{id, display_name}]
 * @var array  $statusLabels    status => 中文
 * @var array  $providerLabels  provider => 中文
 * @var bool   $canManage
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

$curProvider = (string) ($filters['provider'] ?? '');
$curStatus   = (string) ($filters['status'] ?? '');
$curCustomer = (string) ($filters['customer_id'] ?? '');
$curKeyword  = (string) ($filters['keyword'] ?? '');
$hasFilter   = $curProvider !== '' || $curStatus !== '' || ($curCustomer !== '' && $curCustomer !== '0') || $curKeyword !== '';

// 狀態 badge 配色（嚴禁綠色）。
$statusChip = [
    'pending'            => 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300',
    'paid'               => 'bg-blue-600 text-white dark:bg-blue-600 dark:text-white',
    'failed'             => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
    'cancelled'          => 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-300',
    'partially_refunded' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
    'refunded'           => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300',
];

$providerChip = [
    'sandbox'  => 'bg-slate-100 text-slate-500 dark:bg-white/5 dark:text-slate-400',
    'payuni'   => 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300',
    'shopline' => 'bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300',
];

$fmtMoney = static function ($v, string $cur): string {
    return $cur . ' ' . number_format((float) $v, 0);
};
$fmtDt = static function (?string $d): string {
    $d = (string) ($d ?? '');
    return $d !== '' ? substr($d, 0, 16) : '—';
};
?>

<!-- 操作列 -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <p class="text-slate-500 dark:text-slate-400 text-sm">共 <?= e((string) $total) ?> 筆付款記錄</p>
</div>

<!-- 篩選列：金流商 / 狀態 / 客戶 / 關鍵字（GET form） -->
<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-4 mb-5">
    <form method="GET" action="/admin/payments" class="ys-toolbar flex flex-wrap items-end gap-3">
        <div class="min-w-[150px]">
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">金流商</label>
            <select name="provider"
                    class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="">全部金流商</option>
                <?php foreach ($providerLabels as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= $curProvider === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="min-w-[140px]">
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">狀態</label>
            <select name="status"
                    class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="">全部狀態</option>
                <?php foreach ($statusLabels as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= $curStatus === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="min-w-[180px]">
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">客戶</label>
            <select name="customer_id"
                    class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="">全部客戶</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= e((string) $c['id']) ?>" <?= $curCustomer === (string) $c['id'] ? 'selected' : '' ?>>
                    <?= e($c['display_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="min-w-[180px] flex-1">
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">搜尋（編號 / 交易序號）</label>
            <input type="text" name="keyword" value="<?= e($curKeyword) ?>" maxlength="100"
                   placeholder="PAY-2026-0001 或交易序號"
                   class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div class="flex items-center gap-2">
            <button type="submit"
                    class="px-4 py-2 bg-slate-800 dark:bg-white/10 text-white rounded-lg text-sm font-medium hover:bg-slate-900 dark:hover:bg-white/20 transition">
                篩選
            </button>
            <?php if ($hasFilter): ?>
            <a href="/admin/payments" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400">清除</a>
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
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">付款編號</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">報價</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">客戶</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">金流商</th>
                <th class="text-right px-4 py-3 font-medium text-slate-500 dark:text-slate-400">金額</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">狀態</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">付款時間</th>
                <th class="text-right px-4 py-3 font-medium text-slate-500 dark:text-slate-400">操作</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
            <?php if (empty($payments)): ?>
            <tr>
                <td colspan="8" class="px-4 py-12 text-center text-slate-400">尚無付款記錄</td>
            </tr>
            <?php else: ?>
                <?php foreach ($payments as $p): ?>
                <?php
                $pid      = (int) $p['id'];
                $status   = (string) $p['status'];
                $provider = (string) $p['provider'];
                ?>
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5 transition">
                    <td class="px-4 py-3">
                        <a href="/admin/payments/<?= $pid ?>" class="font-mono text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline">
                            <?= e($p['payment_no']) ?>
                        </a>
                    </td>
                    <td class="px-4 py-3">
                        <?php if (($p['quote_id'] ?? null) && ($p['quote_number'] ?? '') !== ''): ?>
                            <a href="/admin/quotes/<?= (int) $p['quote_id'] ?>" class="font-mono text-xs text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400">
                                <?= e($p['quote_number']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-slate-400">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <?php if (($p['customer_id'] ?? null) && ($p['customer_name'] ?? '') !== ''): ?>
                            <a href="/admin/customers/<?= (int) $p['customer_id'] ?>" class="text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400">
                                <?= e($p['customer_name']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-slate-400">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= $providerChip[$provider] ?? $providerChip['sandbox'] ?>">
                            <?= e($providerLabels[$provider] ?? $provider) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right font-medium text-slate-800 dark:text-slate-100 tabular-nums">
                        <?= e($fmtMoney($p['amount'] ?? 0, (string) ($p['currency'] ?? 'TWD'))) ?>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $statusChip[$status] ?? $statusChip['pending'] ?>">
                            <?= e($statusLabels[$status] ?? $status) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300 font-mono text-xs"><?= e($fmtDt($p['paid_at'] ?? null)) ?></td>
                    <td class="px-4 py-3 text-right">
                        <a href="/admin/payments/<?= $pid ?>" class="text-slate-500 dark:text-slate-400 hover:text-slate-700 text-xs font-medium">檢視</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- 分頁（保留篩選參數） -->
<?php if ($totalPages > 1): ?>
    <?php
    $pageBase = array_filter([
        'provider'    => $curProvider,
        'status'      => $curStatus,
        'customer_id' => ($curCustomer !== '' && $curCustomer !== '0') ? $curCustomer : '',
        'keyword'     => $curKeyword,
    ], static fn ($v) => $v !== '');
    $baseUrl = '/admin/payments' . ($pageBase ? '?' . http_build_query($pageBase) : '');
    ?>
    <?php $view->partial('pagination', [
        'page'       => $page,
        'totalPages' => $totalPages,
        'baseUrl'    => $baseUrl,
    ]); ?>
<?php endif; ?>
