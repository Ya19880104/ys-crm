<?php
/** @var array $stage */
/** @var array $board */

use function YangSheep\CRM\Core\e;
use YangSheep\CRM\Core\BrandColorPolicy;

$cards = $stage['cards'] ?? [];
$color = BrandColorPolicy::forDisplay($stage['color'] ?? '#6b7280');
?>

<div class="kanban-column flex-shrink-0 w-80 bg-gray-100 rounded-xl flex flex-col max-h-[calc(100vh-200px)]"
     data-stage-id="<?= (int) $stage['id'] ?>"
     data-allow-drag-in="<?= $stage['allow_drag_in'] ?? 1 ?>"
     data-allow-drag-out="<?= $stage['allow_drag_out'] ?? 1 ?>">

    <!-- Column 標題 -->
    <div class="px-4 py-3 flex items-center justify-between border-b border-gray-200 rounded-t-xl"
         style="border-top: 3px solid <?= e($color) ?>">
        <div class="flex items-center gap-2">
            <span class="w-3 h-3 rounded-full" style="background-color: <?= e($color) ?>"></span>
            <h4 class="font-semibold text-gray-700 text-sm"><?= e($stage['name']) ?></h4>
            <span class="kanban-count text-xs text-gray-400 bg-gray-200 px-2 py-0.5 rounded-full"><?= count($cards) ?></span>
        </div>
        <a href="/admin/cards/create?board_id=<?= (int) $board['id'] ?>&stage_id=<?= (int) $stage['id'] ?>"
           class="text-gray-400 hover:text-blue-600 transition" title="新增卡片">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
        </a>
    </div>

    <!-- Column 卡片列表（可拖拉區域） -->
    <div class="kanban-column-body flex-1 overflow-y-auto p-3 space-y-2 min-h-[60px]"
         data-stage-id="<?= (int) $stage['id'] ?>">
        <?php foreach ($cards as $card): ?>
            <?php $view->partial('card-item', ['card' => $card]); ?>
        <?php endforeach; ?>
    </div>
</div>
