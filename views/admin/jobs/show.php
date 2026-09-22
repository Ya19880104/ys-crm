<?php
/**
 * 工作卡片詳情（對應架構設計 §7.6）。
 * 標題 / 內容 / 封面圖 + 持續追加內容（job_entries） + 計時器（開始 / 停止 / 繼續 + 各段紀錄 + 工時加總）。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $job             含 entries[]、timers[]、total_seconds、running、欄位/客戶/負責人名
 * @var array  $priorityLabels
 * @var bool   $canEdit
 * @var bool   $canDelete
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;
use YangSheep\CRM\Core\BrandColorPolicy;

$jid       = (int) $job['id'];
$running   = $job['running'] ?? null;
$entries   = $job['entries'] ?? [];
$timers    = $job['timers'] ?? [];
$totalSec  = (int) ($job['total_seconds'] ?? 0);
$prio      = (string) ($job['priority'] ?? 'medium');
$columnColor = BrandColorPolicy::forDisplay($job['column_color'] ?? '#1E40AF');

/** 秒數 → H時M分S秒（詳情頁完整顯示）。 */
$fmtFull = static function (int $sec): string {
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;
    if ($h > 0) { return "{$h} 時 {$m} 分 {$s} 秒"; }
    if ($m > 0) { return "{$m} 分 {$s} 秒"; }
    return "{$s} 秒";
};

$priorityChip = [
    'low'      => 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300',
    'medium'   => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
    'high'     => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    'critical' => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
];
$fmtDt = static fn (?string $d): string => $d !== null && $d !== '' ? substr($d, 0, 16) : '—';
?>

<style>[x-cloak]{display:none!important;}</style>

<div class="mb-5">
    <a href="/admin/jobs" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 flex items-center gap-1">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
        </svg>
        返回工作看板
    </a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

    <!-- 左：工作內容 + 追加內容 -->
    <div class="lg:col-span-2 space-y-5">

        <!-- 抬頭卡 -->
        <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap mb-2">
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium" style="background-color: <?= e($columnColor) ?>1A; color: <?= e($columnColor) ?>;">
                            <?= e($job['column_name'] ?? '—') ?>
                        </span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= $priorityChip[$prio] ?? $priorityChip['medium'] ?>">
                            <?= e($priorityLabels[$prio] ?? $prio) ?>
                        </span>
                        <?php if (($job['completed_at'] ?? null) !== null): ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">已完成</span>
                        <?php endif; ?>
                    </div>
                    <h2 class="text-xl font-bold text-slate-900 dark:text-slate-100 break-words"><?= e($job['title']) ?></h2>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <?php if ($canEdit): ?>
                    <a href="/admin/jobs/<?= $jid ?>/edit"
                       class="px-3 py-2 text-sm border border-slate-300 dark:border-surface-border rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/5 transition">編輯</a>
                    <?php endif; ?>
                    <?php if ($canDelete): ?>
                    <form method="POST" action="/admin/jobs/<?= $jid ?>/delete"
                          onsubmit="return confirm('確定要刪除此工作？所有追加內容與計時紀錄將一併刪除，無法復原。')">
                        <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                        <button type="submit" class="px-3 py-2 text-sm border border-red-200 dark:border-red-500/30 text-red-600 dark:text-red-400 rounded-lg hover:bg-red-50 dark:hover:bg-red-500/10 transition">刪除</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (($job['cover_image_path'] ?? '') !== ''): ?>
            <div class="mt-4">
                <img src="<?= e($job['cover_image_path']) ?>" alt="封面" class="w-full max-h-64 object-cover rounded-lg border border-slate-200 dark:border-surface-border">
            </div>
            <?php endif; ?>

            <?php if (($job['description'] ?? '') !== ''): ?>
            <div class="mt-4 text-sm text-slate-700 dark:text-slate-300 whitespace-pre-line leading-relaxed"><?= e($job['description']) ?></div>
            <?php endif; ?>
        </div>

        <!-- 追加內容 -->
        <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
            <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-4">追加內容（<?= count($entries) ?>）</h3>

            <?php if ($canEdit): ?>
            <!-- 新增追加內容（附圖） -->
            <form method="POST" action="/admin/jobs/<?= $jid ?>/entries" enctype="multipart/form-data"
                  class="border border-slate-200 dark:border-surface-border rounded-lg p-4 mb-5 space-y-3">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                <textarea name="content" rows="2"
                          class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                          placeholder="記錄進度、溝通內容、待辦…"></textarea>
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp"
                           class="text-sm text-slate-600 dark:text-slate-300 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-blue-50 file:text-blue-700 dark:file:bg-blue-500/10 dark:file:text-blue-300">
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition">新增紀錄</button>
                </div>
            </form>
            <?php endif; ?>

            <!-- 追加內容時間軸 -->
            <?php if ($entries === []): ?>
                <p class="text-sm text-slate-400 py-6 text-center">尚無追加內容</p>
            <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($entries as $entry): ?>
                <div class="flex gap-3">
                    <div class="flex-shrink-0 w-8 h-8 rounded-full bg-slate-100 dark:bg-white/10 flex items-center justify-center text-xs font-semibold text-slate-500 dark:text-slate-300">
                        <?= e(mb_substr((string) ($entry['author_name'] ?? '?'), 0, 1) ?: '?') ?>
                    </div>
                    <div class="min-w-0 flex-1 border border-slate-100 dark:border-surface-border rounded-lg p-3">
                        <div class="flex items-center justify-between gap-2 mb-1">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-200"><?= ($entry['author_name'] ?? '') !== '' ? e($entry['author_name']) : '（已移除使用者）' ?></span>
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-slate-400"><?= e($fmtDt($entry['created_at'] ?? null)) ?></span>
                                <?php if ($canEdit): ?>
                                <form method="POST" action="/admin/jobs/<?= $jid ?>/entries/<?= (int) $entry['id'] ?>/delete"
                                      onsubmit="return confirm('刪除此追加內容？')">
                                    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                                    <button type="submit" class="text-red-600 dark:text-red-400 hover:text-red-600 text-xs">刪除</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (($entry['content'] ?? '') !== ''): ?>
                        <div class="text-sm text-slate-600 dark:text-slate-300 whitespace-pre-line break-words"><?= e($entry['content']) ?></div>
                        <?php endif; ?>
                        <?php if (($entry['image_path'] ?? '') !== ''): ?>
                        <a href="<?= e($entry['image_path']) ?>" target="_blank" rel="noopener" class="inline-block mt-2">
                            <img src="<?= e($entry['image_path']) ?>" alt="附圖" class="max-h-40 rounded-lg border border-slate-200 dark:border-surface-border">
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 右：基本資訊 + 計時器 -->
    <div class="lg:col-span-1 space-y-5">

        <!-- 計時器 -->
        <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
            <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-4">計時器</h3>

            <!-- 累計工時 -->
            <div class="text-center py-4 rounded-lg bg-slate-50 dark:bg-white/5 mb-4">
                <div class="text-xs text-slate-400 mb-1">累計工時</div>
                <div class="text-2xl font-bold text-slate-800 dark:text-slate-100"><?= e($fmtFull($totalSec)) ?></div>
                <?php if ($running !== null): ?>
                <div class="inline-flex items-center gap-1.5 mt-2 text-xs font-medium text-red-600 dark:text-red-400">
                    <span class="w-2 h-2 rounded-full bg-red-500 animate-pulse"></span>
                    計時中（自 <?= e($fmtDt($running['started_at'] ?? null)) ?>）
                </div>
                <?php endif; ?>
            </div>

            <?php if ($canEdit): ?>
            <!-- 開始 / 繼續 / 停止 -->
            <?php if ($running === null): ?>
            <form method="POST" action="/admin/jobs/<?= $jid ?>/timer/start">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                <button type="submit" class="w-full inline-flex items-center justify-center gap-2 bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M6 4l10 6-10 6V4z"/></svg>
                    <?= $totalSec > 0 ? '繼續計時' : '開始計時' ?>
                </button>
            </form>
            <?php else: ?>
            <form method="POST" action="/admin/jobs/<?= $jid ?>/timer/stop">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                <button type="submit" class="w-full inline-flex items-center justify-center gap-2 bg-red-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-red-700 transition">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M5 5h10v10H5z"/></svg>
                    停止計時
                </button>
            </form>
            <?php endif; ?>
            <?php endif; ?>

            <!-- 計時分段紀錄 -->
            <?php if ($timers !== []): ?>
            <div class="mt-5 pt-4 border-t border-slate-100 dark:border-surface-border">
                <div class="text-xs font-medium text-slate-500 dark:text-slate-400 mb-2">分段紀錄（<?= count($timers) ?>）</div>
                <div class="space-y-2 max-h-64 overflow-y-auto">
                    <?php foreach ($timers as $t): ?>
                    <?php $isRun = ($t['status'] ?? '') === 'running'; ?>
                    <div class="flex items-center justify-between text-xs gap-2 py-1.5 border-b border-slate-50 dark:border-white/5 last:border-0">
                        <div class="min-w-0">
                            <div class="text-slate-600 dark:text-slate-300"><?= e($fmtDt($t['started_at'] ?? null)) ?></div>
                            <?php if (($t['operator_name'] ?? '') !== ''): ?>
                            <div class="text-slate-400 text-[11px]"><?= e($t['operator_name']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <?php if ($isRun): ?>
                            <span class="inline-flex items-center gap-1 text-red-600 dark:text-red-400 font-medium">
                                <span class="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse"></span>進行中
                            </span>
                            <?php else: ?>
                            <span class="text-slate-700 dark:text-slate-200 font-medium"><?= e($fmtFull((int) ($t['duration_seconds'] ?? 0))) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- 基本資訊 -->
        <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
            <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-4">資訊</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">客戶</dt>
                    <dd class="text-right">
                        <?php if (($job['customer_id'] ?? null) && ($job['customer_name'] ?? '') !== ''): ?>
                        <a href="/admin/customers/<?= (int) $job['customer_id'] ?>" class="text-blue-600 dark:text-blue-400 hover:underline"><?= e($job['customer_name']) ?></a>
                        <?php else: ?>
                        <span class="text-slate-400">內部工作</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">負責人</dt>
                    <dd class="text-slate-800 dark:text-slate-200 text-right"><?= ($job['assignee_name'] ?? '') !== '' ? e($job['assignee_name']) : '未指定' ?></dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">到期日</dt>
                    <dd class="text-slate-800 dark:text-slate-200 text-right"><?= ($job['due_date'] ?? '') !== '' ? e(substr((string) $job['due_date'], 0, 10)) : '—' ?></dd>
                </div>
                <div class="flex justify-between gap-3 pt-3 border-t border-slate-100 dark:border-surface-border">
                    <dt class="text-slate-500 dark:text-slate-400">建立者</dt>
                    <dd class="text-slate-800 dark:text-slate-200 text-right"><?= ($job['created_by_name'] ?? '') !== '' ? e($job['created_by_name']) : '—' ?></dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">建立時間</dt>
                    <dd class="text-slate-800 dark:text-slate-200 text-right"><?= e($fmtDt($job['created_at'] ?? null)) ?></dd>
                </div>
                <?php if (($job['completed_at'] ?? null) !== null): ?>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">完成時間</dt>
                    <dd class="text-emerald-600 dark:text-emerald-400 text-right"><?= e($fmtDt($job['completed_at'])) ?></dd>
                </div>
                <?php endif; ?>
            </dl>
        </div>
    </div>
</div>
