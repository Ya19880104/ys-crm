<?php
/**
 * 報價單列表（對應架構設計 §7.8）。
 * 編號 / 標題 / 客戶 / 金額 / 狀態 badge / 可見性 / 日期 / 操作 + 篩選（狀態 / 客戶 / search）。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $_flash
 * @var array  $quotes          每筆含 customer_name、signature_count
 * @var int    $total
 * @var int    $page
 * @var int    $perPage
 * @var int    $totalPages
 * @var array  $filters         status / customer_id / keyword
 * @var array  $customers       [{id, display_name}]
 * @var array  $statusLabels    status => 中文
 * @var array  $visLabels       visibility => 中文
 * @var bool   $canCreate
 * @var bool   $canEdit
 * @var bool   $canDelete
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

$curStatus   = (string) ($filters['status'] ?? '');
$curCustomer = (string) ($filters['customer_id'] ?? '');
$curKeyword  = (string) ($filters['keyword'] ?? '');
$hasFilter   = $curStatus !== '' || ($curCustomer !== '' && $curCustomer !== '0') || $curKeyword !== '';

// 狀態 badge 配色
$statusChip = [
    'draft'   => 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300',
    'sent'    => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
    'viewed'  => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300',
    'signed'  => 'bg-blue-600 text-white dark:bg-blue-600 dark:text-white',
    'paid'    => 'bg-blue-800 text-white dark:bg-blue-700 dark:text-white',
    'expired' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    'void'    => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
];

$visChip = [
    'private'       => 'bg-slate-100 text-slate-500 dark:bg-white/5 dark:text-slate-400',
    'public'        => 'bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-300',
    'password'      => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    'customer_only' => 'bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300',
];

// 金額格式
$fmtMoney = static function ($v, string $cur): string {
    return $cur . ' ' . number_format((float) $v, 0);
};
$fmtDate = static function (?string $d): string {
    $d = (string) ($d ?? '');
    return $d !== '' ? substr($d, 0, 10) : '—';
};
?>

<!-- 操作列 -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <p class="text-slate-500 dark:text-slate-400 text-sm">共 <?= e((string) $total) ?> 張報價單</p>
    <?php if ($canCreate): ?>
    <a href="/admin/quotes/create"
       class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        新增報價單
    </a>
    <?php endif; ?>
</div>

<!-- 篩選列：狀態 / 客戶 / 關鍵字（GET form） -->
<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-4 mb-5">
    <form method="GET" action="/admin/quotes" class="ys-toolbar flex flex-wrap items-end gap-3">
        <div class="min-w-[150px]">
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

        <div class="min-w-[200px] flex-1">
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">搜尋（編號 / 標題）</label>
            <input type="text" name="keyword" value="<?= e($curKeyword) ?>" maxlength="100"
                   placeholder="Q-2026-0001 或標題關鍵字"
                   class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div class="flex items-center gap-2">
            <button type="submit"
                    class="px-4 py-2 bg-slate-800 dark:bg-white/10 text-white rounded-lg text-sm font-medium hover:bg-slate-900 dark:hover:bg-white/20 transition">
                篩選
            </button>
            <?php if ($hasFilter): ?>
            <a href="/admin/quotes" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400">清除</a>
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
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">編號</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">標題</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">客戶</th>
                <th class="text-right px-4 py-3 font-medium text-slate-500 dark:text-slate-400">金額</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">狀態</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">可見性</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">有效期限</th>
                <th class="text-right px-4 py-3 font-medium text-slate-500 dark:text-slate-400">操作</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
            <?php if (empty($quotes)): ?>
            <tr>
                <td colspan="8" class="px-4 py-12 text-center text-slate-400">尚無報價單</td>
            </tr>
            <?php else: ?>
                <?php foreach ($quotes as $q): ?>
                <?php
                $qid    = (int) $q['id'];
                $status = (string) $q['status'];
                $vis    = (string) $q['visibility'];
                $sigCnt = (int) ($q['signature_count'] ?? 0);
                ?>
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5 transition">
                    <!-- 編號 -->
                    <td class="px-4 py-3">
                        <a href="/admin/quotes/<?= $qid ?>" class="font-mono text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline">
                            <?= e($q['quote_number']) ?>
                        </a>
                    </td>
                    <!-- 標題 -->
                    <td class="px-4 py-3 max-w-[240px] truncate">
                        <a href="/admin/quotes/<?= $qid ?>" class="font-medium text-slate-900 dark:text-slate-100 hover:text-blue-600 dark:hover:text-blue-400" title="<?= e($q['title']) ?>">
                            <?= e($q['title']) ?>
                        </a>
                        <?php if ($sigCnt > 0): ?>
                        <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300" title="已簽署">已簽</span>
                        <?php endif; ?>
                    </td>
                    <!-- 客戶 -->
                    <td class="px-4 py-3">
                        <?php if (($q['customer_id'] ?? null) && ($q['customer_name'] ?? '') !== ''): ?>
                            <a href="/admin/customers/<?= (int) $q['customer_id'] ?>" class="text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400">
                                <?= e($q['customer_name']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-slate-400">—</span>
                        <?php endif; ?>
                    </td>
                    <!-- 金額 -->
                    <td class="px-4 py-3 text-right font-medium text-slate-800 dark:text-slate-100">
                        <?= e($fmtMoney($q['total'] ?? 0, (string) ($q['currency'] ?? 'TWD'))) ?>
                    </td>
                    <!-- 狀態 -->
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $statusChip[$status] ?? $statusChip['draft'] ?>">
                            <?= e($statusLabels[$status] ?? $status) ?>
                        </span>
                    </td>
                    <!-- 可見性 -->
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= $visChip[$vis] ?? $visChip['private'] ?>">
                            <?= e($visLabels[$vis] ?? $vis) ?>
                        </span>
                    </td>
                    <!-- 有效期限 -->
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300"><?= e($fmtDate($q['valid_until'] ?? null)) ?></td>
                    <!-- 操作 -->
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-3">
                            <a href="/admin/quotes/<?= $qid ?>" class="text-slate-500 dark:text-slate-400 hover:text-slate-700 text-xs font-medium">檢視</a>
                            <?php if ($canEdit && !in_array($status, ['signed', 'paid'], true)): ?>
                            <a href="/admin/quotes/<?= $qid ?>/edit" class="text-slate-500 dark:text-slate-400 hover:text-slate-700 text-xs font-medium">編輯</a>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                            <form method="POST" action="/admin/quotes/<?= $qid ?>/delete"
                                  onsubmit="return confirm('確定要刪除報價單 <?= e($q['quote_number']) ?>？此操作將一併刪除其明細、瀏覽軌跡與簽署紀錄，無法復原。')">
                                <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                                <button type="submit" class="text-red-600 dark:text-red-400 hover:text-red-800 text-xs font-medium">刪除</button>
                            </form>
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

<!-- 分頁（保留篩選參數） -->
<?php if ($totalPages > 1): ?>
    <?php
    $pageBase = array_filter([
        'status'      => $curStatus,
        'customer_id' => ($curCustomer !== '' && $curCustomer !== '0') ? $curCustomer : '',
        'keyword'     => $curKeyword,
    ], static fn ($v) => $v !== '');
    $baseUrl = '/admin/quotes' . ($pageBase ? '?' . http_build_query($pageBase) : '');
    ?>
    <?php $view->partial('pagination', [
        'page'       => $page,
        'totalPages' => $totalPages,
        'baseUrl'    => $baseUrl,
    ]); ?>
<?php endif; ?>
