<?php
/** @var \YangSheep\CRM\Core\View $view */
/** @var array $grouped */
$view->layout('admin');

use function YangSheep\CRM\Core\e;
use YangSheep\CRM\Core\BrandColorPolicy;
?>

<div class="flex items-center justify-between mb-6">
    <h3 class="text-lg font-semibold text-gray-700">所有狀態</h3>
    <a href="/admin/stages/create"
       class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        新增狀態
    </a>
</div>

<?php if (empty($grouped)): ?>
    <div class="bg-white rounded-xl shadow-sm border p-8 text-center text-gray-400">
        尚無狀態資料，請先建立看板再新增狀態
    </div>
<?php else: ?>
    <?php foreach ($grouped as $group): ?>
    <div class="mb-8">
        <h4 class="text-md font-semibold text-gray-600 mb-3 flex items-center gap-2">
            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7"/>
            </svg>
            <?= e($group['board_name']) ?>
        </h4>

        <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
            <table class="w-full text-left">
                <thead class="bg-gray-50 border-b">
                    <tr>
                        <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">顏色</th>
                        <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">名稱</th>
                        <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">Slug</th>
                        <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase text-center">排序</th>
                        <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase text-center">卡片數</th>
                        <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase text-center">公開</th>
                        <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase text-center">允許拖入</th>
                        <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase text-center">允許拖出</th>
                        <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase text-center">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($group['stages'] as $stage): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <span class="inline-block w-6 h-6 rounded" style="background-color: <?= e(BrandColorPolicy::forDisplay($stage['color'] ?? '#6b7280')) ?>"></span>
                        </td>
                        <td class="px-6 py-4 font-medium"><?= e($stage['name']) ?></td>
                        <td class="px-6 py-4 text-sm text-gray-500"><?= e($stage['slug'] ?? '') ?></td>
                        <td class="px-6 py-4 text-center text-sm"><?= (int) $stage['sort_order'] ?></td>
                        <td class="px-6 py-4 text-center">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                                <?= (int) $stage['card_count'] ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <?= $stage['is_public'] ? '<span class="text-green-600">&#10003;</span>' : '<span class="text-slate-400">&mdash;</span>' ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <?= $stage['allow_drag_in'] ? '<span class="text-green-600">&#10003;</span>' : '<span class="text-red-600 dark:text-red-400">&#10005;</span>' ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <?= $stage['allow_drag_out'] ? '<span class="text-green-600">&#10003;</span>' : '<span class="text-red-600 dark:text-red-400">&#10005;</span>' ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <div class="flex items-center justify-center gap-2">
                                <a href="/admin/stages/<?= (int) $stage['id'] ?>/edit"
                                   class="text-gray-600 hover:text-gray-800 text-sm">編輯</a>
                                <?php if ((int) $stage['card_count'] === 0): ?>
                                <form method="POST" action="/admin/stages/<?= (int) $stage['id'] ?>/delete"
                                      onsubmit="return confirm('確定要刪除此狀態嗎？')">
                                    <?= \YangSheep\CRM\Core\Csrf::field() ?>
                                    <button type="submit" class="text-red-600 dark:text-red-400 hover:text-red-700 text-sm">刪除</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
