<?php
/**
 * 資產狀態 badge（網站 / 主機共用）。
 *
 * 顯示規則：
 * - status=expired → 紅「已到期」。
 * - status=terminated → 灰「終止」。
 * - status=active 且到期日已過今日 → 紅「已逾期」（實際 status 仍 active，僅視覺標示；
 *   自動翻 expired 屬未來 cron P4-6）。
 * - status=active 且到期日於 30 天內 → 橘「即將到期」+ 藍底狀態。
 * - 其餘 active → 藍「啟用中」。
 *
 * @var \YangSheep\CRM\Core\View    $view
 * @var string                      $status        資產狀態代碼
 * @var string|null                 $dueDate       到期日（Y-m-d 或 null）
 * @var array<string, string>       $statusLabels  狀態代碼 => 中文標籤
 */

use function YangSheep\CRM\Core\e;
use YangSheep\CRM\Asset\AssetHelper;

$status       = (string) ($status ?? 'active');
$dueDate      = $dueDate ?? null;
$statusLabels = $statusLabels ?? AssetHelper::ASSET_STATUSES;

$badgeCls = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium';

$isOverdue  = AssetHelper::isOverdue($status, $dueDate);
$isExpiring = !$isOverdue && AssetHelper::isExpiringSoon($status, $dueDate, 30);

if ($status === 'expired' || $isOverdue) {
    $cls   = 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300';
    $label = $isOverdue ? '已逾期' : ($statusLabels['expired'] ?? '已到期');
} elseif ($status === 'terminated') {
    $cls   = 'bg-slate-100 text-slate-600 dark:bg-slate-500/15 dark:text-slate-300';
    $label = $statusLabels['terminated'] ?? '終止';
} else {
    $cls   = 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300';
    $label = $statusLabels['active'] ?? '啟用中';
}
?>
<span class="<?= $badgeCls ?> <?= $cls ?>"><?= e($label) ?></span>
<?php if ($isExpiring): ?>
<span class="<?= $badgeCls ?> bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300 ml-1" title="到期日於 30 天內">即將到期</span>
<?php endif; ?>
