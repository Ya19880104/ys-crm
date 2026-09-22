<?php
/** @var \YangSheep\CRM\Core\View $view */
/** @var array $boards */
/** @var array $stages */
/** @var array $users */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

$selectedBoard = $_GET['board_id'] ?? '';
$selectedStage = $_GET['stage_id'] ?? '';
?>

<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <a href="/admin/cards" class="text-sm text-gray-500 hover:text-gray-700">&larr; 返回卡片列表</a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border p-6">
        <form method="POST" action="/admin/cards" x-data="{ boardId: '<?= e($selectedBoard) ?>' }">
            <?= \YangSheep\CRM\Core\Csrf::field() ?>

            <div class="space-y-5">
                <!-- 所屬看板（用於篩選狀態） -->
                <div>
                    <label for="board_select" class="block text-sm font-medium text-gray-700 mb-1">所屬看板</label>
                    <select id="board_select" x-model="boardId"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">請選擇看板</option>
                        <?php foreach ($boards as $board): ?>
                            <option value="<?= (int) $board['id'] ?>"><?= e($board['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 狀態 -->
                <div>
                    <label for="stage_id" class="block text-sm font-medium text-gray-700 mb-1">狀態 <span class="text-red-600 dark:text-red-400">*</span></label>
                    <select id="stage_id" name="stage_id" required
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">請選擇狀態</option>
                        <?php foreach ($stages as $stage): ?>
                            <option value="<?= (int) $stage['id'] ?>"
                                    data-board="<?= (int) $stage['board_id'] ?>"
                                    x-show="!boardId || boardId == '<?= (int) $stage['board_id'] ?>'"
                                    <?= $selectedStage == $stage['id'] ? 'selected' : '' ?>>
                                [<?= e($stage['board_name']) ?>] <?= e($stage['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 標題 -->
                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700 mb-1">標題 <span class="text-red-600 dark:text-red-400">*</span></label>
                    <input type="text" id="title" name="title" required maxlength="200"
                           class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                           placeholder="卡片標題">
                </div>

                <!-- 描述 -->
                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-1">描述</label>
                    <textarea id="description" name="description" rows="4"
                              class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                              placeholder="卡片描述..."></textarea>
                </div>

                <!-- 優先級 -->
                <div>
                    <label for="priority" class="block text-sm font-medium text-gray-700 mb-1">優先級 <span class="text-red-600 dark:text-red-400">*</span></label>
                    <select id="priority" name="priority" required
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="low">低</option>
                        <option value="medium" selected>中</option>
                        <option value="high">高</option>
                        <option value="critical">緊急</option>
                    </select>
                </div>

                <!-- 負責人 -->
                <div>
                    <label for="assignee_id" class="block text-sm font-medium text-gray-700 mb-1">負責人</label>
                    <select id="assignee_id" name="assignee_id"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">未指定</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>"><?= e($u['display_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 是否公開 -->
                <div class="flex items-center gap-2">
                    <input type="checkbox" id="is_public" name="is_public" value="1" checked
                           class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <label for="is_public" class="text-sm text-gray-700">公開可見</label>
                </div>
            </div>

            <div class="flex items-center gap-3 mt-8 pt-5 border-t">
                <button type="submit"
                        class="px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                    建立卡片
                </button>
                <a href="/admin/cards" class="px-5 py-2 text-gray-600 hover:text-gray-800">取消</a>
            </div>
        </form>
    </div>
</div>
