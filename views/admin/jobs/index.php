<?php
/**
 * 工作看板總覽（對應架構設計 §7.6）。
 * 欄位橫向；卡片可拖拉（SortableJS + AJAX /admin/jobs/{id}/move），
 * 並提供「變更狀態」下拉作為拖拉的後備（無 JS 亦可用）。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $board           每欄含 jobs[]
 * @var array  $columns         啟用欄位（供下拉）
 * @var array  $customers
 * @var array  $assignees
 * @var array  $priorityLabels
 * @var int    $filterAssignee
 * @var bool   $canCreate
 * @var bool   $canEdit
 * @var bool   $canManageCols
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;
use YangSheep\CRM\Core\BrandColorPolicy;

/** 秒數 → 時:分（看板 chip 用；不顯秒以免太長）。 */
$fmtHm = static function (int $sec): string {
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    return sprintf('%d:%02d', $h, $m);
};

$priorityChip = [
    'low'      => 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300',
    'medium'   => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
    'high'     => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    'critical' => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
];

$today = new DateTimeImmutable('today');
?>

<!-- 操作列 -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <form method="GET" action="/admin/jobs" class="ys-toolbar flex items-end gap-2">
        <div class="min-w-[180px]">
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">負責人篩選</label>
            <select name="assigned_to" onchange="this.form.submit()"
                    class="px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="">全部負責人</option>
                <?php foreach ($assignees as $a): ?>
                <option value="<?= e((string) $a['id']) ?>" <?= $filterAssignee === (int) $a['id'] ? 'selected' : '' ?>>
                    <?= e($a['display_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($filterAssignee > 0): ?>
        <a href="/admin/jobs" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 pb-2">清除</a>
        <?php endif; ?>
    </form>

    <div class="flex items-center gap-2">
        <?php if ($canManageCols): ?>
        <a href="/admin/jobs/columns"
           class="px-4 py-2 text-sm border border-slate-300 dark:border-surface-border rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/5 transition">
            欄位管理
        </a>
        <?php endif; ?>
        <?php if ($canCreate): ?>
        <a href="/admin/jobs/create"
           class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            新增工作
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($board === []): ?>
    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-12 text-center">
        <p class="text-slate-500 dark:text-slate-400 text-sm">尚未建立任何看板欄位。</p>
        <?php if ($canManageCols): ?>
        <a href="/admin/jobs/columns" class="text-blue-600 dark:text-blue-400 hover:underline text-sm mt-2 inline-block">前往欄位管理新增</a>
        <?php endif; ?>
    </div>
<?php else: ?>

<!-- 看板：橫向捲動的欄位容器 -->
<div id="jobs-board" class="flex gap-4 overflow-x-auto pb-4" data-can-edit="<?= $canEdit ? '1' : '0' ?>">
    <?php foreach ($board as $col): ?>
    <?php $jobsInCol = $col['jobs'] ?? []; ?>
    <div class="jobs-column flex-shrink-0 w-80 bg-slate-50 dark:bg-white/5 rounded-xl border border-slate-200 dark:border-surface-border flex flex-col max-h-[calc(100vh-13rem)]">
        <!-- 欄頭 -->
        <div class="px-4 py-3 border-b border-slate-200 dark:border-surface-border flex items-center justify-between flex-shrink-0">
            <div class="flex items-center gap-2 min-w-0">
                <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background-color: <?= e(BrandColorPolicy::forDisplay($col['color'])) ?>;"></span>
                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 truncate"><?= e($col['name']) ?></h3>
                <span class="jobs-count text-xs text-slate-400 bg-white dark:bg-white/10 px-1.5 py-0.5 rounded-full"><?= count($jobsInCol) ?></span>
            </div>
            <?php if ($canCreate): ?>
            <a href="/admin/jobs/create?column_id=<?= (int) $col['id'] ?>" title="在此欄新增工作"
               class="text-slate-400 hover:text-blue-600 dark:hover:text-blue-400">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
            </a>
            <?php endif; ?>
        </div>

        <!-- 卡片容器（拖拉目標） -->
        <div class="jobs-column-body p-3 space-y-3 overflow-y-auto flex-1" data-column-id="<?= (int) $col['id'] ?>">
            <?php if ($jobsInCol === []): ?>
                <p class="jobs-empty text-xs text-slate-400 text-center py-6">尚無工作</p>
            <?php endif; ?>
            <?php foreach ($jobsInCol as $job): ?>
            <?php
                $jid       = (int) $job['id'];
                $running   = (int) ($job['running_count'] ?? 0) > 0;
                $totalSec  = (int) ($job['total_seconds'] ?? 0);
                $prio      = (string) ($job['priority'] ?? 'medium');
                $due       = substr((string) ($job['due_date'] ?? ''), 0, 10);
                $isOverdue = false;
                if ($due !== '' && ($job['completed_at'] ?? null) === null) {
                    $dueDt = DateTimeImmutable::createFromFormat('Y-m-d', $due);
                    $isOverdue = $dueDt !== false && $dueDt < $today;
                }
            ?>
            <div class="jobs-card group bg-white dark:bg-surface-card rounded-lg border border-slate-200 dark:border-surface-border p-3 shadow-sm hover:shadow-md transition cursor-pointer"
                 data-job-id="<?= $jid ?>">
                <!-- 封面（若有） -->
                <?php if (($job['cover_image_path'] ?? '') !== ''): ?>
                <a href="/admin/jobs/<?= $jid ?>" class="block mb-2 -mt-0.5">
                    <img src="<?= e($job['cover_image_path']) ?>" alt="" class="w-full h-24 object-cover rounded-md">
                </a>
                <?php endif; ?>

                <!-- 標題 -->
                <a href="/admin/jobs/<?= $jid ?>" class="block text-sm font-medium text-slate-800 dark:text-slate-100 hover:text-blue-600 dark:hover:text-blue-400 leading-snug mb-2">
                    <?= e($job['title']) ?>
                </a>

                <!-- meta 標籤列 -->
                <div class="flex flex-wrap items-center gap-1.5 mb-2">
                    <!-- 優先度 -->
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium <?= $priorityChip[$prio] ?? $priorityChip['medium'] ?>">
                        <?= e($priorityLabels[$prio] ?? $prio) ?>
                    </span>
                    <!-- 客戶 tag -->
                    <?php if (($job['customer_name'] ?? '') !== ''): ?>
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300 max-w-[120px] truncate" title="<?= e($job['customer_name']) ?>">
                        <?= e($job['customer_name']) ?>
                    </span>
                    <?php else: ?>
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium bg-slate-50 text-slate-400 dark:bg-white/5 dark:text-slate-500">內部</span>
                    <?php endif; ?>
                    <!-- 計時 chip -->
                    <?php if ($running): ?>
                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[11px] font-medium bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300" title="計時進行中">
                        <span class="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse"></span>
                        <?= e($fmtHm($totalSec)) ?>
                    </span>
                    <?php elseif ($totalSec > 0): ?>
                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[11px] font-medium bg-slate-100 text-slate-500 dark:bg-white/5 dark:text-slate-400" title="累計工時">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <?= e($fmtHm($totalSec)) ?>
                    </span>
                    <?php endif; ?>
                </div>

                <!-- 底列：負責人 + 到期 -->
                <div class="flex items-center justify-between text-[11px] text-slate-400">
                    <span class="truncate"><?= ($job['assignee_name'] ?? '') !== '' ? e($job['assignee_name']) : '未指定' ?></span>
                    <?php if ($due !== ''): ?>
                    <span class="<?= $isOverdue ? 'text-red-600 dark:text-red-400 font-medium' : '' ?>">
                        <?= e($due) ?><?= $isOverdue ? '（逾期）' : '' ?>
                    </span>
                    <?php endif; ?>
                </div>

                <!-- 變更狀態下拉（拖拉的後備；無 JS 也可用） -->
                <?php if ($canEdit && count($columns) > 1): ?>
                <form method="POST" action="/admin/jobs/<?= $jid ?>/move" class="mt-2 pt-2 border-t border-slate-100 dark:border-surface-border opacity-0 group-hover:opacity-100 transition"
                      onsubmit="return true;">
                    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                    <input type="hidden" name="sort" value="0">
                    <label class="sr-only">移動到</label>
                    <select name="column_id" onchange="this.form.submit()"
                            class="w-full text-[11px] px-2 py-1 border border-slate-200 dark:border-surface-border dark:bg-surface-dark dark:text-slate-200 rounded">
                        <?php foreach ($columns as $c): ?>
                        <option value="<?= e((string) $c['id']) ?>" <?= (int) $c['id'] === (int) $job['column_id'] ? 'selected' : '' ?>>
                            移至：<?= e($c['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($canEdit): ?>
<script src="<?= \YangSheep\CRM\Core\asset('/assets/js/jobs-board.js') ?>"></script>
<?php endif; ?>

<?php endif; ?>
