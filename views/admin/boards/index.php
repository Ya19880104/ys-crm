<?php
/** @var \YangSheep\CRM\Core\View $view */
/** @var array $boards */
$view->layout('admin');

use function YangSheep\CRM\Core\e;
?>

<div class="flex items-center justify-between mb-6">
    <h3 class="text-lg font-semibold text-gray-700">所有看板</h3>
    <a href="/admin/boards/create"
       class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        新增看板
    </a>
</div>

<div class="bg-white rounded-xl shadow-sm border overflow-hidden">
    <table class="w-full text-left">
        <thead class="bg-gray-50 border-b">
            <tr>
                <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">名稱</th>
                <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">說明</th>
                <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase text-center">卡片數</th>
                <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase text-center">狀態</th>
                <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">建立者</th>
                <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase text-center">操作</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            <?php if (empty($boards)): ?>
                <tr>
                    <td colspan="6" class="px-6 py-8 text-center text-gray-400">尚無看板，請先建立一個</td>
                </tr>
            <?php else: ?>
                <?php foreach ($boards as $board): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4">
                        <a href="/admin/boards/<?= (int) $board['id'] ?>" class="text-blue-600 hover:underline font-medium">
                            <?= e($board['name']) ?>
                        </a>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate">
                        <?= e($board['description'] ?? '') ?>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                            <?= (int) $board['card_count'] ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <?php if ($board['is_active']): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">啟用</span>
                        <?php else: ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">停用</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500"><?= e($board['creator_name'] ?? '-') ?></td>
                    <td class="px-6 py-4 text-center">
                        <div class="flex items-center justify-center gap-2">
                            <a href="/admin/boards/<?= (int) $board['id'] ?>"
                               class="text-blue-600 hover:text-blue-800 text-sm"
                               title="檢視看板">檢視</a>
                            <a href="/admin/boards/<?= (int) $board['id'] ?>/edit"
                               class="text-gray-600 hover:text-gray-800 text-sm"
                               title="編輯">編輯</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
