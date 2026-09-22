<?php
/** @var \YangSheep\CRM\Core\View $view */
/** @var array $stage */
/** @var array $boards */
$view->layout('admin');

use function YangSheep\CRM\Core\e;
use YangSheep\CRM\Core\BrandColorPolicy;
?>

<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <a href="/admin/stages" class="text-sm text-gray-500 hover:text-gray-700">&larr; 返回狀態列表</a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border p-6">
        <form method="POST" action="/admin/stages/<?= (int) $stage['id'] ?>">
            <?= \YangSheep\CRM\Core\Csrf::field() ?>

            <div class="space-y-5">
                <!-- 所屬看板（唯讀顯示） -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">所屬看板</label>
                    <p class="text-sm text-gray-600 bg-gray-50 px-3 py-2 rounded-lg"><?= e($stage['board_name']) ?></p>
                </div>

                <!-- 狀態名稱 -->
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">狀態名稱 <span class="text-red-600 dark:text-red-400">*</span></label>
                    <input type="text" id="name" name="name" required maxlength="50"
                           value="<?= e($stage['name']) ?>"
                           class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>

                <!-- Slug -->
                <div>
                    <label for="slug" class="block text-sm font-medium text-gray-700 mb-1">Slug</label>
                    <input type="text" id="slug" name="slug" maxlength="50"
                           value="<?= e($stage['slug'] ?? '') ?>"
                           class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>

                <!-- 顏色選擇 -->
                <div>
                    <label for="color" class="block text-sm font-medium text-gray-700 mb-1">顏色</label>
                    <div class="flex items-center gap-3">
                        <input type="color" id="color" name="color" value="<?= e(BrandColorPolicy::forDisplay($stage['color'] ?? '#6b7280')) ?>"
                               class="w-12 h-10 rounded border-gray-300 cursor-pointer">
                        <div class="flex gap-2">
                            <?php
                            $presetColors = ['#ef4444', '#f97316', '#eab308', '#46caeb', '#3b82f6', '#8b5cf6', '#6b7280', '#1e293b'];
                            foreach ($presetColors as $c):
                            ?>
                            <button type="button"
                                    class="w-8 h-8 rounded-full border-2 border-transparent hover:border-gray-400 transition"
                                    style="background-color: <?= $c ?>"
                                    onclick="document.getElementById('color').value='<?= $c ?>'"></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- 排序 -->
                <div>
                    <label for="sort_order" class="block text-sm font-medium text-gray-700 mb-1">排序</label>
                    <input type="number" id="sort_order" name="sort_order"
                           value="<?= (int) ($stage['sort_order'] ?? 0) ?>" min="0"
                           class="w-32 rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>

                <!-- 設定選項 -->
                <div class="space-y-3 pt-3 border-t">
                    <p class="text-sm font-medium text-gray-700">設定</p>

                    <div class="flex items-center gap-2">
                        <input type="checkbox" id="is_public" name="is_public" value="1"
                               <?= ($stage['is_public'] ?? 1) ? 'checked' : '' ?>
                               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <label for="is_public" class="text-sm text-gray-700">公開可見</label>
                    </div>

                    <div class="flex items-center gap-2">
                        <input type="checkbox" id="allow_drag_in" name="allow_drag_in" value="1"
                               <?= ($stage['allow_drag_in'] ?? 1) ? 'checked' : '' ?>
                               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <label for="allow_drag_in" class="text-sm text-gray-700">允許拖拉進入此狀態</label>
                    </div>

                    <div class="flex items-center gap-2">
                        <input type="checkbox" id="allow_drag_out" name="allow_drag_out" value="1"
                               <?= ($stage['allow_drag_out'] ?? 1) ? 'checked' : '' ?>
                               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <label for="allow_drag_out" class="text-sm text-gray-700">允許從此狀態拖拉出去</label>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3 mt-8 pt-5 border-t">
                <button type="submit"
                        class="px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                    更新狀態
                </button>
                <a href="/admin/stages" class="px-5 py-2 text-gray-600 hover:text-gray-800">取消</a>
            </div>
        </form>
    </div>
</div>
