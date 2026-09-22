<?php
/**
 * 看板欄位管理（總控增刪 / 編輯欄位）。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $columns  含 job_count、is_active
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;
use YangSheep\CRM\Core\BrandColorPolicy;
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

<div class="max-w-3xl space-y-5" x-data="{ showAdd: false }">

    <!-- 新增欄位 -->
    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100">看板欄位</h3>
            <button type="button" @click="showAdd = !showAdd"
                    class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                新增欄位
            </button>
        </div>

        <div x-show="showAdd" x-cloak x-transition class="border border-blue-200 dark:border-blue-500/30 bg-blue-50/50 dark:bg-blue-500/5 rounded-lg p-4 mb-4">
            <form method="POST" action="/admin/jobs/columns" class="space-y-3">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div class="md:col-span-1">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">名稱 <span class="text-red-600 dark:text-red-400">*</span></label>
                        <input type="text" name="name" required maxlength="100"
                               class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="例如：等待回覆">
                    </div>
                    <div class="md:col-span-1">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">代碼（slug，可留空自動產生）</label>
                        <input type="text" name="slug" maxlength="50"
                               class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="waiting">
                    </div>
                    <div class="md:col-span-1">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">顏色</label>
                        <input type="color" name="color" value="#1E40AF"
                               class="w-full h-[38px] px-1 py-1 border border-slate-300 dark:border-surface-border rounded-lg cursor-pointer">
                    </div>
                </div>
                <div class="flex items-center gap-2 pt-1">
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition">建立欄位</button>
                    <button type="button" @click="showAdd = false" class="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition">取消</button>
                </div>
            </form>
        </div>

        <!-- 欄位列表 -->
        <?php if ($columns === []): ?>
            <p class="text-sm text-slate-400 py-6 text-center">尚無欄位</p>
        <?php else: ?>
        <div class="space-y-2">
            <?php foreach ($columns as $col): ?>
            <?php
                $cid = (int) $col['id'];
                $jobCount = (int) ($col['job_count'] ?? 0);
                $displayColor = BrandColorPolicy::forDisplay($col['color'] ?? '#1E40AF');
            ?>
            <div x-data="{ editing: false }" class="border border-slate-200 dark:border-surface-border rounded-lg">
                <!-- 顯示列 -->
                <div x-show="!editing" class="flex items-center justify-between gap-3 p-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color: <?= e($displayColor) ?>;"></span>
                        <span class="font-medium text-slate-800 dark:text-slate-100 truncate"><?= e($col['name']) ?></span>
                        <span class="text-xs text-slate-400 font-mono"><?= e($col['slug']) ?></span>
                        <span class="text-xs text-slate-400">（<?= $jobCount ?> 張卡片）</span>
                        <?php if ((int) $col['is_active'] !== 1): ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-500 dark:bg-white/5 dark:text-slate-400">已停用</span>
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <button type="button" @click="editing = true" class="text-slate-500 dark:text-slate-400 hover:text-slate-700 text-xs font-medium">編輯</button>
                        <?php if ($jobCount === 0): ?>
                        <form method="POST" action="/admin/jobs/columns/<?= $cid ?>/delete" data-confirm="<?= e('刪除欄位「' . $col['name'] . '」？') ?>" onsubmit="return confirm(this.dataset.confirm)">
                            <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                            <button type="submit" class="text-red-600 dark:text-red-400 hover:text-red-700 text-xs font-medium">刪除</button>
                        </form>
                        <?php else: ?>
                        <span class="text-xs text-slate-400" title="欄位內仍有卡片，無法刪除">刪除</span>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- 編輯列 -->
                <div x-show="editing" x-cloak class="p-3 bg-slate-50 dark:bg-white/5 rounded-lg">
                    <form method="POST" action="/admin/jobs/columns/<?= $cid ?>" class="space-y-3">
                        <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">名稱</label>
                                <input type="text" name="name" required maxlength="100" value="<?= e($col['name']) ?>"
                                       class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">顏色</label>
                                <input type="color" name="color" value="<?= e($displayColor) ?>"
                                       class="w-full h-[38px] px-1 py-1 border border-slate-300 dark:border-surface-border rounded-lg cursor-pointer">
                            </div>
                            <div class="flex items-end">
                                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300 pb-2">
                                    <input type="checkbox" name="is_active" value="1" <?= (int) $col['is_active'] === 1 ? 'checked' : '' ?> class="rounded text-blue-600 focus:ring-blue-500">
                                    啟用（顯示於看板）
                                </label>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition">儲存</button>
                            <button type="button" @click="editing = false" class="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition">取消</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="text-xs text-slate-400 mt-4">提示：名稱含「完成 / done / completed」等字樣的欄位（依 slug）會在卡片移入時自動記錄完成時間。欄位內仍有卡片時無法刪除，可改為停用。</p>
        <?php endif; ?>
    </div>
</div>
