<?php
/** @var \YangSheep\CRM\Core\View $view */
/** @var array $board */
$view->layout('admin');

use function YangSheep\CRM\Core\e;
?>

<?php $view->startSection('head'); ?>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<?php $view->endSection(); ?>

<!-- 看板標題列 -->
<div class="flex items-center justify-between mb-6">
    <div class="flex items-center gap-3">
        <a href="/admin/boards" class="text-gray-400 hover:text-gray-600">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
        </a>
        <h3 class="text-lg font-semibold text-gray-700"><?= e($board['name']) ?></h3>
        <?php if (!empty($board['description'])): ?>
            <span class="text-sm text-gray-400"><?= e($board['description']) ?></span>
        <?php endif; ?>
    </div>
    <div class="flex items-center gap-2">
        <a href="/admin/boards/<?= (int) $board['id'] ?>/edit"
           class="px-3 py-1.5 text-sm border rounded-lg text-gray-600 hover:bg-gray-50 transition">
            編輯看板
        </a>
        <a href="/admin/cards/create?board_id=<?= (int) $board['id'] ?>"
           class="px-3 py-1.5 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
            + 新增卡片
        </a>
    </div>
</div>

<!-- Kanban 看板 -->
<div class="kanban-board flex gap-4 overflow-x-auto pb-4" id="kanban-board" data-board-id="<?= (int) $board['id'] ?>">
    <?php if (empty($board['stages'])): ?>
        <div class="flex-1 text-center py-12 text-gray-400">
            <p class="mb-4">此看板尚無狀態欄位</p>
            <a href="/admin/stages/create?board_id=<?= (int) $board['id'] ?>"
               class="text-blue-600 hover:underline">新增第一個狀態</a>
        </div>
    <?php else: ?>
        <?php foreach ($board['stages'] as $stage): ?>
            <?php $view->partial('kanban-column', ['stage' => $stage, 'board' => $board]); ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- 卡片快速檢視 Modal -->
<?php $view->partial('card-modal'); ?>

<!-- Kanban JS -->
<script src="<?= \YangSheep\CRM\Core\asset('/assets/js/kanban.js') ?>"></script>
