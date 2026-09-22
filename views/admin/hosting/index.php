<?php
/**
 * 客戶主機資產 — Excel-like 總表（對應架構設計 §7.7）。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $_flash
 * @var array  $hostings        每筆含 customer_name / related_website_url
 * @var int    $total
 * @var int    $page
 * @var int    $perPage
 * @var int    $totalPages
 * @var array  $filters         customer_id / status / due_month
 * @var array  $customers       [{id, display_name}]
 * @var array  $dueMonths       YYYY-MM => 中文月份
 * @var array  $typeLabels      type => 中文
 * @var array  $statusLabels    status => 中文
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

$curCustomer = (string) ($filters['customer_id'] ?? '');
$curStatus   = (string) ($filters['status'] ?? '');
$curDueMonth = (string) ($filters['due_month'] ?? '');
$hasFilter   = $curCustomer !== '' || $curStatus !== '' || $curDueMonth !== '';

$fmtDate = static function (?string $d): string {
    $d = (string) ($d ?? '');
    return $d !== '' ? substr($d, 0, 10) : '';
};
?>

<!-- 頂部互切 tab：網站 ⇄ 主機 -->
<?php $view->partial('asset-tabs', ['active' => 'hosting']); ?>

<!-- 操作列 -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <p class="text-slate-500 dark:text-slate-400 text-sm">共 <?= e((string) $total) ?> 筆主機資產</p>
    <a href="/admin/hosting/create"
       class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        新增主機
    </a>
</div>

<!-- 篩選列：客戶 / 狀態 / 到期月份（GET form） -->
<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-4 mb-5">
    <form method="GET" action="/admin/hosting" class="ys-toolbar flex flex-wrap items-end gap-3">
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

        <div class="min-w-[160px]">
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">租用到期月份</label>
            <select name="due_month"
                    class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="">全部月份</option>
                <?php foreach ($dueMonths as $ym => $label): ?>
                <option value="<?= e($ym) ?>" <?= $curDueMonth === $ym ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="flex items-center gap-2">
            <button type="submit"
                    class="px-4 py-2 bg-slate-800 dark:bg-white/10 text-white rounded-lg text-sm font-medium hover:bg-slate-900 dark:hover:bg-white/20 transition">
                篩選
            </button>
            <?php if ($hasFilter): ?>
            <a href="/admin/hosting" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400">清除</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Excel-like 總表 -->
<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-sm whitespace-nowrap">
        <thead class="bg-slate-50 dark:bg-white/5 border-b border-slate-200 dark:border-surface-border">
            <tr>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">客戶</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">類型</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">IP 位址</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">帳號信箱</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">規格</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">租用起訖</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">狀態</th>
                <th class="text-right px-4 py-3 font-medium text-slate-500 dark:text-slate-400">操作</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
            <?php if (empty($hostings)): ?>
            <tr>
                <td colspan="8" class="px-4 py-12 text-center text-slate-400">尚無主機資產</td>
            </tr>
            <?php else: ?>
                <?php foreach ($hostings as $h): ?>
                <?php
                $hid       = (int) $h['id'];
                $sd        = $fmtDate($h['start_date'] ?? null);
                $ed        = $fmtDate($h['end_date'] ?? null);
                $ip        = trim((string) ($h['ip_address'] ?? ''));
                $acct      = trim((string) ($h['account_email'] ?? ''));
                $spec      = trim((string) ($h['spec'] ?? ''));
                $typeLabel = $typeLabels[$h['type']] ?? (string) $h['type'];
                ?>
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5 transition">
                    <!-- 客戶 -->
                    <td class="px-4 py-3">
                        <a href="/admin/customers/<?= (int) $h['customer_id'] ?>"
                           class="font-medium text-slate-900 dark:text-slate-100 hover:text-blue-600 dark:hover:text-blue-400">
                            <?= e($h['customer_name'] ?? ('#' . (int) $h['customer_id'])) ?>
                        </a>
                        <?php if (($h['related_website_url'] ?? '') !== ''): ?>
                            <span class="block text-xs text-slate-400 truncate max-w-[160px]" title="關聯網站：<?= e($h['related_website_url']) ?>">關聯：<?= e($h['related_website_url']) ?></span>
                        <?php endif; ?>
                    </td>
                    <!-- 類型 -->
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                     <?= $h['type'] === 'vps'
                                           ? 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300'
                                           : 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300' ?>">
                            <?= e($typeLabel) ?>
                        </span>
                    </td>
                    <!-- IP -->
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300 font-mono text-xs">
                        <?= $ip !== '' ? e($ip) : '<span class="text-slate-400 font-sans">—</span>' ?>
                    </td>
                    <!-- 帳號信箱 -->
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300 max-w-[200px] truncate" title="<?= e($acct) ?>">
                        <?= $acct !== '' ? e($acct) : '<span class="text-slate-400">—</span>' ?>
                    </td>
                    <!-- 規格 -->
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300 max-w-[200px] truncate" title="<?= e($spec) ?>">
                        <?= $spec !== '' ? e($spec) : '<span class="text-slate-400">—</span>' ?>
                    </td>
                    <!-- 起訖 -->
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                        <?php if ($sd !== '' || $ed !== ''): ?>
                            <?= e($sd !== '' ? $sd : '—') ?> <span class="text-slate-400">~</span> <?= e($ed !== '' ? $ed : '—') ?>
                        <?php else: ?>
                            <span class="text-slate-400">—</span>
                        <?php endif; ?>
                    </td>
                    <!-- 狀態（含逾期/即將到期視覺） -->
                    <td class="px-4 py-3">
                        <?php $view->partial('asset-status-badge', [
                            'status'       => (string) $h['status'],
                            'dueDate'      => $h['end_date'] ?? null,
                            'statusLabels' => $statusLabels,
                        ]); ?>
                    </td>
                    <!-- 操作 -->
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-3">
                            <a href="/admin/hosting/<?= $hid ?>/edit" class="text-slate-500 dark:text-slate-400 hover:text-slate-700 text-xs font-medium">編輯</a>
                            <form method="POST" action="/admin/hosting/<?= $hid ?>/delete"
                                  onsubmit="return confirm('確定要刪除此主機資產？此操作無法復原。')">
                                <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                                <button type="submit" class="text-red-600 dark:text-red-400 hover:text-red-800 text-xs font-medium">刪除</button>
                            </form>
                        </div>
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
        'customer_id' => $curCustomer,
        'status'      => $curStatus,
        'due_month'   => $curDueMonth,
    ], static fn ($v) => $v !== '');
    $baseUrl = '/admin/hosting' . ($pageBase ? '?' . http_build_query($pageBase) : '');
    ?>
    <?php $view->partial('pagination', [
        'page'       => $page,
        'totalPages' => $totalPages,
        'baseUrl'    => $baseUrl,
    ]); ?>
<?php endif; ?>
