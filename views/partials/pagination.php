<?php
/**
 * 通用分頁元件
 *
 * @var int    $page       當前頁碼
 * @var int    $totalPages 總頁數
 * @var string $baseUrl    基礎 URL（不含 ?page=）
 */

use function YangSheep\CRM\Core\e;

if ($totalPages <= 1) {
    return;
}

// 計算顯示的頁碼範圍（前後各 2 頁）
$range    = 2;
$startPage = max(1, $page - $range);
$endPage   = min($totalPages, $page + $range);

$separator = str_contains($baseUrl, '?') ? '&' : '?';
?>

<nav class="flex items-center justify-center gap-1 mt-6" aria-label="分頁導航">
    <!-- 上一頁 -->
    <?php if ($page > 1): ?>
        <a href="<?= e($baseUrl . $separator . 'page=' . ($page - 1)) ?>"
           class="px-3 py-2 text-sm text-gray-600 bg-white border rounded-lg hover:bg-gray-50 transition"
           aria-label="上一頁">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
        </a>
    <?php else: ?>
        <span class="px-3 py-2 text-sm text-gray-300 bg-gray-50 border rounded-lg cursor-not-allowed">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
        </span>
    <?php endif; ?>

    <!-- 第一頁 -->
    <?php if ($startPage > 1): ?>
        <a href="<?= e($baseUrl . $separator . 'page=1') ?>"
           class="px-3 py-2 text-sm text-gray-600 bg-white border rounded-lg hover:bg-gray-50 transition">
            1
        </a>
        <?php if ($startPage > 2): ?>
            <span class="px-2 py-2 text-sm text-gray-400">...</span>
        <?php endif; ?>
    <?php endif; ?>

    <!-- 頁碼 -->
    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
        <?php if ($i === $page): ?>
            <span class="px-3 py-2 text-sm font-medium text-white bg-blue-600 border border-blue-600 rounded-lg">
                <?= $i ?>
            </span>
        <?php else: ?>
            <a href="<?= e($baseUrl . $separator . 'page=' . $i) ?>"
               class="px-3 py-2 text-sm text-gray-600 bg-white border rounded-lg hover:bg-gray-50 transition">
                <?= $i ?>
            </a>
        <?php endif; ?>
    <?php endfor; ?>

    <!-- 最後一頁 -->
    <?php if ($endPage < $totalPages): ?>
        <?php if ($endPage < $totalPages - 1): ?>
            <span class="px-2 py-2 text-sm text-gray-400">...</span>
        <?php endif; ?>
        <a href="<?= e($baseUrl . $separator . 'page=' . $totalPages) ?>"
           class="px-3 py-2 text-sm text-gray-600 bg-white border rounded-lg hover:bg-gray-50 transition">
            <?= $totalPages ?>
        </a>
    <?php endif; ?>

    <!-- 下一頁 -->
    <?php if ($page < $totalPages): ?>
        <a href="<?= e($baseUrl . $separator . 'page=' . ($page + 1)) ?>"
           class="px-3 py-2 text-sm text-gray-600 bg-white border rounded-lg hover:bg-gray-50 transition"
           aria-label="下一頁">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </a>
    <?php else: ?>
        <span class="px-3 py-2 text-sm text-gray-300 bg-gray-50 border rounded-lg cursor-not-allowed">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </span>
    <?php endif; ?>
</nav>
