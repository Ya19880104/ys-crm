<?php
/**
 * 客戶 Portal — 聯絡人（CRUD）。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $contacts
 */

use function YangSheep\CRM\Core\e;
?>

<div x-data="{ showAdd: false }">
    <div class="flex items-center justify-between mb-4">
        <p class="text-sm text-slate-500 dark:text-slate-400">共 <?= count($contacts) ?> 位聯絡人</p>
        <button type="button" @click="showAdd = !showAdd"
                class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            新增聯絡人
        </button>
    </div>

    <!-- 新增表單 -->
    <div x-show="showAdd" x-cloak x-transition class="bg-white dark:bg-surface-card border border-blue-200 dark:border-blue-500/30 rounded-xl p-5 mb-5">
        <form method="POST" action="/portal/contacts" class="space-y-3">
            <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">姓名 <span class="text-red-600 dark:text-red-400">*</span></label>
                    <input type="text" name="name" required maxlength="100" class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">職稱</label>
                    <input type="text" name="role" maxlength="100" class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">手機</label>
                    <input type="text" name="mobile" maxlength="50" class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Email</label>
                    <input type="email" name="email" maxlength="255" class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">LINE ID</label>
                    <input type="text" name="line_id" maxlength="100" class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">市話</label>
                    <input type="text" name="phone" maxlength="50" class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="is_primary" value="1" class="rounded text-blue-600 focus:ring-blue-500"> 設為主要聯絡人
            </label>
            <div class="flex items-center gap-2 pt-1">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition">儲存</button>
                <button type="button" @click="showAdd = false" class="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition">取消</button>
            </div>
        </form>
    </div>

    <!-- 列表 -->
    <?php if ($contacts === []): ?>
        <div class="bg-white dark:bg-surface-card rounded-xl border border-slate-200 dark:border-surface-border p-10 text-center text-slate-400">尚無聯絡人</div>
    <?php else: ?>
    <div class="space-y-3">
        <?php foreach ($contacts as $c): ?>
        <div class="bg-white dark:bg-surface-card border border-slate-200 dark:border-surface-border rounded-xl p-4 flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="font-medium text-slate-900 dark:text-slate-100"><?= e($c['name']) ?></span>
                    <?php if (!empty($c['is_primary'])): ?><span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300">主要</span><?php endif; ?>
                    <?php if (($c['role'] ?? '') !== ''): ?><span class="text-xs text-slate-400"><?= e($c['role']) ?></span><?php endif; ?>
                </div>
                <div class="mt-1.5 text-sm text-slate-500 dark:text-slate-400 space-y-0.5">
                    <?php if (($c['mobile'] ?? '') !== ''): ?><div>手機：<?= e($c['mobile']) ?></div><?php endif; ?>
                    <?php if (($c['email'] ?? '') !== ''): ?><div class="break-all">Email：<?= e($c['email']) ?></div><?php endif; ?>
                    <?php if (($c['line_id'] ?? '') !== ''): ?><div>LINE：<?= e($c['line_id']) ?></div><?php endif; ?>
                </div>
            </div>
            <form method="POST" action="/portal/contacts/<?= (int) $c['id'] ?>/delete" data-confirm="<?= e('確定要刪除聯絡人「' . $c['name'] . '」？') ?>" onsubmit="return confirm(this.dataset.confirm)">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                <button type="submit" class="text-red-600 dark:text-red-400 hover:text-red-700 text-xs whitespace-nowrap">刪除</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
