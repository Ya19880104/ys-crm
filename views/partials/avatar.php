<?php
/**
 * 使用者頭像（共用元件）。
 *
 * 有上傳照片就顯示照片；沒有則顯示預設圖案 —— 一個中性的人像剪影，
 * 不用姓名首字，因為中文姓氏重複率高（一整排「王」「陳」反而分不出人），
 * 且首字在深色底上需要額外處理對比。
 *
 * @var string $avatarPath 頭像相對路徑（空字串代表未上傳）
 * @var int    $size       邊長（px），預設 32
 * @var string $alt        替代文字（通常為顯示名稱）
 * @var string $extraClass 額外 class
 */

use function YangSheep\CRM\Core\e;

$avatarPath = $avatarPath ?? '';
$size       = (int) ($size ?? 32);
$alt        = $alt ?? '';
$extraClass = $extraClass ?? '';
?>
<?php if (trim((string) $avatarPath) !== ''): ?>
    <img src="<?= e($avatarPath) ?>"
         alt="<?= e($alt) ?>"
         width="<?= $size ?>"
         height="<?= $size ?>"
         class="ys-avatar-img flex-shrink-0 <?= e($extraClass) ?>"
         style="width: <?= $size ?>px; height: <?= $size ?>px;">
<?php else: ?>
    <span class="ys-avatar-default flex-shrink-0 <?= e($extraClass) ?>"
          style="width: <?= $size ?>px; height: <?= $size ?>px;"
          role="img"
          aria-label="<?= e($alt !== '' ? $alt . ' 的預設頭像' : '預設頭像') ?>">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <circle cx="12" cy="8.5" r="3.5" fill="currentColor" opacity="0.9"/>
            <path d="M4.5 20a7.5 7.5 0 0 1 15 0"
                  stroke="currentColor" stroke-width="2.4" stroke-linecap="round" opacity="0.9"/>
        </svg>
    </span>
<?php endif; ?>
