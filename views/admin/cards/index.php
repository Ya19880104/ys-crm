<?php
/** @var \YangSheep\CRM\Core\View $view */
/** @var array $cards */
/** @var array $filters */
/** @var array $boards */
/** @var array $stages */
/** @var array $users */
/** @var int $page */
/** @var int $totalPages */
/** @var int $total */
$view->layout('admin');

use function YangSheep\CRM\Core\e;
use YangSheep\CRM\Core\BrandColorPolicy;

$priorityLabels = [
    'critical' => ['label' => '緊急', 'class' => 'bg-red-100 text-red-700'],
    'high'     => ['label' => '高',   'class' => 'bg-orange-100 text-orange-700'],
    'medium'   => ['label' => '中',   'class' => 'bg-blue-100 text-blue-700'],
    'low'      => ['label' => '低',   'class' => 'bg-gray-100 text-gray-500'],
];
?>

<div class="flex items-center justify-between mb-6">
    <h3 class="text-lg font-semibold text-gray-700">所有卡片 <span class="text-sm font-normal text-gray-400">(<?= $total ?> 筆)</span></h3>
    <a href="/admin/cards/create"
       class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        新增卡片
    </a>
</div>

<!-- 篩選條件 -->
<div class="bg-white rounded-xl shadow-sm border p-4 mb-6">
    <form method="GET" action="/admin/cards" class="ys-toolbar flex flex-wrap items-end gap-4">
        <div>
            <label class="block text-xs text-gray-500 mb-1">看板</label>
            <select name="board_id" class="rounded-lg border-gray-300 text-sm">
                <option value="">全部看板</option>
                <?php foreach ($boards as $board): ?>
                    <option value="<?= (int) $board['id'] ?>" <?= ($filters['board_id'] ?? '') == $board['id'] ? 'selected' : '' ?>>
                        <?= e($board['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">狀態</label>
            <select name="stage_id" class="rounded-lg border-gray-300 text-sm">
                <option value="">全部狀態</option>
                <?php foreach ($stages as $stage): ?>
                    <option value="<?= (int) $stage['id'] ?>" <?= ($filters['stage_id'] ?? '') == $stage['id'] ? 'selected' : '' ?>>
                        [<?= e($stage['board_name']) ?>] <?= e($stage['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">優先級</label>
            <select name="priority" class="rounded-lg border-gray-300 text-sm">
                <option value="">全部</option>
                <option value="critical" <?= ($filters['priority'] ?? '') === 'critical' ? 'selected' : '' ?>>緊急</option>
                <option value="high" <?= ($filters['priority'] ?? '') === 'high' ? 'selected' : '' ?>>高</option>
                <option value="medium" <?= ($filters['priority'] ?? '') === 'medium' ? 'selected' : '' ?>>中</option>
                <option value="low" <?= ($filters['priority'] ?? '') === 'low' ? 'selected' : '' ?>>低</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">負責人</label>
            <select name="assignee_id" class="rounded-lg border-gray-300 text-sm">
                <option value="">全部</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= ($filters['assignee_id'] ?? '') == $u['id'] ? 'selected' : '' ?>>
                        <?= e($u['display_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">關鍵字</label>
            <input type="text" name="keyword" value="<?= e($filters['keyword'] ?? '') ?>"
                   class="rounded-lg border-gray-300 text-sm" placeholder="搜尋標題或描述...">
        </div>
        <button type="submit" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-sm">篩選</button>
        <a href="/admin/cards" class="px-4 py-2 text-gray-500 hover:text-gray-700 text-sm">清除</a>
    </form>
</div>

<!-- 卡片表格 -->
<div class="bg-white rounded-xl shadow-sm border overflow-hidden">
    <table class="w-full text-left">
        <thead class="bg-gray-50 border-b">
            <tr>
                <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">標題</th>
                <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">看板 / 狀態</th>
                <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase text-center">優先級</th>
                <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">負責人</th>
                <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">更新時間</th>
                <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase text-center">操作</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            <?php if (empty($cards)): ?>
                <tr>
                    <td colspan="6" class="px-6 py-8 text-center text-gray-400">沒有找到符合條件的卡片</td>
                </tr>
            <?php else: ?>
                <?php foreach ($cards as $card): ?>
                <?php $p = $priorityLabels[$card['priority']] ?? $priorityLabels['medium']; ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4">
                        <a href="/admin/cards/<?= (int) $card['id'] ?>" class="text-blue-600 hover:underline font-medium">
                            <?= e($card['title']) ?>
                        </a>
                    </td>
                    <td class="px-6 py-4 text-sm">
                        <span class="text-gray-500"><?= e($card['board_name']) ?></span>
                        <span class="mx-1 text-slate-400">/</span>
                        <span class="inline-flex items-center gap-1">
                            <span class="w-2 h-2 rounded-full" style="background-color: <?= e(BrandColorPolicy::forDisplay($card['stage_color'])) ?>"></span>
                            <?= e($card['stage_name']) ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $p['class'] ?>">
                            <?= $p['label'] ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500"><?= e($card['assignee_name'] ?? '-') ?></td>
                    <td class="px-6 py-4 text-sm text-gray-400"><?= e($card['updated_at']) ?></td>
                    <td class="px-6 py-4 text-center">
                        <div class="flex items-center justify-center gap-2">
                            <a href="/admin/cards/<?= (int) $card['id'] ?>" class="text-blue-600 hover:text-blue-800 text-sm">檢視</a>
                            <a href="/admin/cards/<?= (int) $card['id'] ?>/edit" class="text-gray-600 hover:text-gray-800 text-sm">編輯</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 分頁 -->
<?php if ($totalPages > 1): ?>
<div class="flex items-center justify-between mt-6">
    <p class="text-sm text-gray-500">第 <?= $page ?> / <?= $totalPages ?> 頁，共 <?= $total ?> 筆</p>
    <div class="flex items-center gap-1">
        <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($filters, ['page' => $page - 1])) ?>"
               class="px-3 py-1.5 text-sm border rounded-lg hover:bg-gray-50">&laquo; 上一頁</a>
        <?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="?<?= http_build_query(array_merge($filters, ['page' => $i])) ?>"
               class="px-3 py-1.5 text-sm border rounded-lg <?= $i === $page ? 'bg-blue-600 text-white border-blue-600' : 'hover:bg-gray-50' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($filters, ['page' => $page + 1])) ?>"
               class="px-3 py-1.5 text-sm border rounded-lg hover:bg-gray-50">下一頁 &raquo;</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
