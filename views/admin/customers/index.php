<?php
/**
 * 客戶列表。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $_flash
 * @var array  $customers     每筆含 primary_contact / contacts_count / assigned_name
 * @var int    $total
 * @var int    $page
 * @var int    $perPage
 * @var int    $totalPages
 * @var array  $filters       type / status / keyword
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

$statusLabels = [
    'active'    => ['合作中', 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300'],
    'potential' => ['潛在客戶', 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300'],
    'inactive'  => ['已停止', 'bg-slate-100 text-slate-600 dark:bg-slate-500/15 dark:text-slate-300'],
];

// 組出保留現有篩選的 type tab 連結
$buildTabUrl = static function (string $type) use ($filters): string {
    $params = array_filter([
        'type'    => $type,
        'status'  => $filters['status'] ?? '',
        'keyword' => $filters['keyword'] ?? '',
    ], static fn ($v) => $v !== '');
    return '/admin/customers' . ($params ? '?' . http_build_query($params) : '');
};

$currentType = $filters['type'] ?? '';
$typeTabs = [
    ''           => '全部',
    'individual' => '個人',
    'company'    => '公司',
];
?>

<!-- 頂部操作列 -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <p class="text-slate-500 dark:text-slate-400 text-sm">共 <?= e((string) $total) ?> 位客戶</p>
    <a href="/admin/customers/create"
       class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        新增客戶
    </a>
</div>

<!-- 篩選列 -->
<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-4 mb-5">
    <div class="flex flex-wrap items-center gap-3">
        <!-- 類型 tab -->
        <div class="inline-flex rounded-lg border border-slate-200 dark:border-surface-border overflow-hidden">
            <?php foreach ($typeTabs as $value => $label): ?>
            <a href="<?= e($buildTabUrl($value)) ?>"
               class="px-4 py-2 text-sm font-medium transition
                      <?= $currentType === $value
                            ? 'bg-blue-600 text-white'
                            : 'bg-white dark:bg-surface-card text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/5' ?>">
                <?= e($label) ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- 狀態 + 關鍵字（GET form） -->
        <form method="GET" action="/admin/customers" class="ys-toolbar flex flex-wrap items-center gap-3 flex-1 min-w-[260px]">
            <?php if ($currentType !== ''): ?>
                <input type="hidden" name="type" value="<?= e($currentType) ?>">
            <?php endif; ?>

            <select name="status"
                    class="px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="">全部狀態</option>
                <?php foreach ($statusLabels as $code => [$label, $cls]): ?>
                <option value="<?= e($code) ?>" <?= ($filters['status'] ?? '') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>

            <div class="relative flex-1 min-w-[160px]">
                <input type="text" name="keyword" value="<?= e($filters['keyword'] ?? '') ?>"
                       placeholder="搜尋名稱 / 統編 / 電話 / Email"
                       class="w-full pl-9 pr-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </div>

            <button type="submit"
                    class="px-4 py-2 bg-slate-800 dark:bg-white/10 text-white rounded-lg text-sm font-medium hover:bg-slate-900 dark:hover:bg-white/20 transition">
                搜尋
            </button>
            <?php if (($filters['status'] ?? '') !== '' || ($filters['keyword'] ?? '') !== ''): ?>
            <a href="<?= e($buildTabUrl($currentType)) ?>" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400">清除</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- 客戶表格 -->
<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-sm whitespace-nowrap">
        <thead class="bg-slate-50 dark:bg-white/5 border-b border-slate-200 dark:border-surface-border">
            <tr>
                <th class="text-left px-6 py-3 font-medium text-slate-500 dark:text-slate-400">客戶</th>
                <th class="text-left px-6 py-3 font-medium text-slate-500 dark:text-slate-400">統一編號</th>
                <th class="text-left px-6 py-3 font-medium text-slate-500 dark:text-slate-400">主要聯絡人</th>
                <th class="text-left px-6 py-3 font-medium text-slate-500 dark:text-slate-400">電話</th>
                <th class="text-left px-6 py-3 font-medium text-slate-500 dark:text-slate-400">狀態</th>
                <th class="text-center px-6 py-3 font-medium text-slate-500 dark:text-slate-400">報價</th>
                <th class="text-center px-6 py-3 font-medium text-slate-500 dark:text-slate-400">工作</th>
                <th class="text-right px-6 py-3 font-medium text-slate-500 dark:text-slate-400">操作</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
            <?php if (empty($customers)): ?>
            <tr>
                <td colspan="8" class="px-6 py-12 text-center text-slate-400">
                    尚無客戶資料
                </td>
            </tr>
            <?php else: ?>
                <?php foreach ($customers as $customer): ?>
                    <?php
                    $isCompany = ($customer['type'] ?? '') === 'company';
                    $initial   = mb_substr(trim((string) ($customer['display_name'] ?? '?')), 0, 1) ?: '?';
                    $primary   = $customer['primary_contact'] ?? null;
                    [$sLabel, $sCls] = $statusLabels[$customer['status']] ?? ['—', 'bg-slate-100 text-slate-600'];
                    $cid = (int) $customer['id'];
                    ?>
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5 transition">
                    <!-- 客戶（頭像首字 + 名 + 類型 tag） -->
                    <td class="px-6 py-4">
                        <a href="/admin/customers/<?= $cid ?>" class="flex items-center gap-3 group">
                            <span class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-semibold flex-shrink-0
                                         <?= $isCompany
                                               ? 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300'
                                               : 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-200' ?>">
                                <?= e($initial) ?>
                            </span>
                            <span class="min-w-0">
                                <span class="block font-medium text-slate-900 dark:text-slate-100 group-hover:text-blue-600 dark:group-hover:text-blue-400 truncate">
                                    <?= e($customer['display_name']) ?>
                                </span>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium mt-0.5
                                             <?= $isCompany
                                                   ? 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300'
                                                   : 'bg-slate-50 text-slate-500 dark:bg-white/5 dark:text-slate-400' ?>">
                                    <?= $isCompany ? '公司' : '個人' ?>
                                </span>
                            </span>
                        </a>
                    </td>
                    <!-- 統編 -->
                    <td class="px-6 py-4 text-slate-500 dark:text-slate-400">
                        <?= ($customer['tax_id'] ?? '') !== '' ? e($customer['tax_id']) : '<span class="text-slate-400">—</span>' ?>
                    </td>
                    <!-- 主要聯絡人 -->
                    <td class="px-6 py-4 text-slate-700 dark:text-slate-300">
                        <?php if ($primary !== null): ?>
                            <span class="block"><?= e($primary['name']) ?></span>
                            <?php if (($primary['role'] ?? '') !== ''): ?>
                                <span class="block text-xs text-slate-400"><?= e($primary['role']) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-slate-400">—</span>
                        <?php endif; ?>
                    </td>
                    <!-- 電話（客戶電話，否則主要聯絡人電話/手機） -->
                    <td class="px-6 py-4 text-slate-500 dark:text-slate-400">
                        <?php
                        $phone = $customer['phone'] ?: ($primary['phone'] ?? '') ?: ($primary['mobile'] ?? '');
                        echo $phone !== '' ? e($phone) : '<span class="text-slate-400">—</span>';
                        ?>
                    </td>
                    <!-- 狀態 -->
                    <td class="px-6 py-4">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $sCls ?>">
                            <?= e($sLabel) ?>
                        </span>
                    </td>
                    <!-- 報價（佔位） -->
                    <td class="px-6 py-4 text-center text-slate-400">—</td>
                    <!-- 工作（佔位） -->
                    <td class="px-6 py-4 text-center text-slate-400">—</td>
                    <!-- 操作 -->
                    <td class="px-6 py-4 text-right">
                        <div class="flex items-center justify-end gap-3">
                            <a href="/admin/customers/<?= $cid ?>" class="text-blue-600 dark:text-blue-400 hover:text-blue-800 text-xs font-medium">檢視</a>
                            <a href="/admin/customers/<?= $cid ?>/edit" class="text-slate-500 dark:text-slate-400 hover:text-slate-700 text-xs font-medium">編輯</a>
                            <form method="POST" action="/admin/customers/<?= $cid ?>/delete"
                                  data-confirm="<?= e('確定要刪除「' . $customer['display_name'] . '」？此操作將一併刪除其聯絡人，且無法復原。') ?>" onsubmit="return confirm(this.dataset.confirm)">
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
        'type'    => $filters['type'] ?? '',
        'status'  => $filters['status'] ?? '',
        'keyword' => $filters['keyword'] ?? '',
    ], static fn ($v) => $v !== '');
    $baseUrl = '/admin/customers' . ($pageBase ? '?' . http_build_query($pageBase) : '');
    ?>
    <?php $view->partial('pagination', [
        'page'       => $page,
        'totalPages' => $totalPages,
        'baseUrl'    => $baseUrl,
    ]); ?>
<?php endif; ?>
