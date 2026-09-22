<?php
/** @var array $card */

use function YangSheep\CRM\Core\e;

$priorityColors = [
    'critical' => 'border-l-red-500',
    'high'     => 'border-l-orange-500',
    'medium'   => 'border-l-blue-500',
    'low'      => 'border-l-gray-300',
];
$priorityClass = $priorityColors[$card['priority'] ?? 'medium'] ?? $priorityColors['medium'];

$sourceLabels = [
    'manual' => '',
    'wish'   => '提案',
    'import' => '匯入',
];
$sourceLabel = $sourceLabels[$card['source'] ?? 'manual'] ?? '';
?>

<div class="kanban-card bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 <?= $priorityClass ?> border-l-4 p-3 cursor-pointer hover:shadow-md transition"
     data-card-id="<?= (int) $card['id'] ?>"
     onclick="window.kanbanApp && window.kanbanApp.openModal(<?= (int) $card['id'] ?>)">

    <!-- 標題 -->
    <div class="flex items-start justify-between mb-2">
        <p class="text-sm font-medium text-gray-800 line-clamp-2 flex-1"><?= e($card['title']) ?></p>
        <a href="/admin/cards/<?= (int) $card['id'] ?>" onclick="event.stopPropagation()"
           class="ml-2 flex-shrink-0 text-gray-300 hover:text-blue-500 transition" title="查看詳情">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
            </svg>
        </a>
    </div>

    <!-- 底部資訊 -->
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <!-- 優先級標籤 -->
            <?php
            $priorityBadges = [
                'critical' => 'priority-critical bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-300',
                'high'     => 'priority-high bg-orange-100 text-orange-600 dark:bg-orange-900/40 dark:text-orange-300',
                'medium'   => 'priority-medium bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-300',
                'low'      => 'priority-low bg-gray-100 text-gray-500 dark:bg-gray-700/40 dark:text-gray-400',
            ];
            $priorityNames = ['critical' => '緊急', 'high' => '高', 'medium' => '中', 'low' => '低'];
            $badge = $priorityBadges[$card['priority'] ?? 'medium'] ?? $priorityBadges['medium'];
            $pName = $priorityNames[$card['priority'] ?? 'medium'] ?? '中';
            ?>
            <span class="<?= $badge ?> text-xs px-1.5 py-0.5 rounded font-medium"><?= $pName ?></span>

            <!-- 來源標記 -->
            <?php if ($sourceLabel): ?>
            <span class="text-xs text-gray-400 bg-gray-50 px-1.5 py-0.5 rounded"><?= e($sourceLabel) ?></span>
            <?php endif; ?>
        </div>

        <!-- 負責人名稱 -->
        <?php if (!empty($card['assignee_name'])): ?>
        <span class="text-xs text-gray-400 truncate max-w-[80px]" title="<?= e($card['assignee_name']) ?>">
            <?= e($card['assignee_name']) ?>
        </span>
        <?php endif; ?>
    </div>
</div>
