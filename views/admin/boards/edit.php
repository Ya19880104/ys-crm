<?php
/** @var \YangSheep\CRM\Core\View $view */
/** @var array $board */
$view->layout('admin');

use function YangSheep\CRM\Core\e;
?>

<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <a href="/admin/boards" class="text-sm text-gray-500 hover:text-gray-700">&larr; 返回看板列表</a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border p-6">
        <form method="POST" action="/admin/boards/<?= (int) $board['id'] ?>">
            <?= \YangSheep\CRM\Core\Csrf::field() ?>

            <div class="space-y-5">
                <!-- 看板名稱 -->
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">看板名稱 <span class="text-red-600 dark:text-red-400">*</span></label>
                    <input type="text" id="name" name="name" required maxlength="100"
                           value="<?= e($board['name']) ?>"
                           class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>

                <!-- 說明 -->
                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-1">說明</label>
                    <textarea id="description" name="description" rows="3"
                              class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"><?= e($board['description'] ?? '') ?></textarea>
                </div>

                <!-- 看板類型 -->
                <div>
                    <label for="type" class="block text-sm font-medium text-gray-700 mb-1">類型</label>
                    <select id="type" name="type"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="internal" <?= ($board['type'] ?? '') === 'internal' ? 'selected' : '' ?>>內部看板（僅管理員可見）</option>
                        <option value="public" <?= ($board['type'] ?? '') === 'public' ? 'selected' : '' ?>>公開看板（首頁可見）</option>
                    </select>
                </div>

                <!-- 排序 -->
                <div>
                    <label for="sort_order" class="block text-sm font-medium text-gray-700 mb-1">排序</label>
                    <input type="number" id="sort_order" name="sort_order"
                           value="<?= (int) ($board['sort_order'] ?? 0) ?>" min="0"
                           class="w-32 rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <p class="text-xs text-gray-400 mt-1">數字越小排越前面</p>
                </div>

                <!-- 啟用 -->
                <div class="flex items-center gap-2">
                    <input type="checkbox" id="is_active" name="is_active" value="1"
                           <?= $board['is_active'] ? 'checked' : '' ?>
                           class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <label for="is_active" class="text-sm text-gray-700">啟用看板</label>
                </div>
            </div>

            <div class="flex items-center gap-3 mt-8 pt-5 border-t">
                <button type="submit"
                        class="px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                    更新看板
                </button>
                <a href="/admin/boards" class="px-5 py-2 text-gray-600 hover:text-gray-800">取消</a>
            </div>
        </form>
    </div>
</div>
