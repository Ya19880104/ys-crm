<?php
/**
 * 客戶 Portal — 付款卡片管理。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $cards
 * @var array  $brands
 */

use function YangSheep\CRM\Core\e;
?>

<div x-data="{ showAdd: false }">
    <!-- 安全說明 -->
    <div class="bg-blue-50 dark:bg-blue-500/10 border border-blue-200 dark:border-blue-500/30 text-blue-700 dark:text-blue-300 px-4 py-3 rounded-lg text-xs mb-5">
        為保護您的資料，我們<strong>不會儲存完整卡號或安全碼（CVV）</strong>，僅保留卡別與末四碼供辨識。
    </div>

    <div class="flex items-center justify-between mb-4">
        <p class="text-sm text-slate-500 dark:text-slate-400">共 <?= count($cards) ?> 張卡片</p>
        <button type="button" @click="showAdd = !showAdd"
                class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            新增卡片
        </button>
    </div>

    <!-- 新增卡片 -->
    <div x-show="showAdd" x-cloak x-transition class="bg-white dark:bg-surface-card border border-blue-200 dark:border-blue-500/30 rounded-xl p-5 mb-5">
        <form method="POST" action="/portal/payment-methods" class="space-y-3">
            <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">卡別 <span class="text-red-600 dark:text-red-400">*</span></label>
                    <select name="brand" required class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <?php foreach ($brands as $b): ?><option value="<?= e($b) ?>"><?= e($b) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">末四碼 <span class="text-red-600 dark:text-red-400">*</span></label>
                    <input type="text" name="last4" required inputmode="numeric" pattern="\d{4}" maxlength="4" placeholder="1234" class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">到期月</label>
                    <input type="text" name="exp_month" inputmode="numeric" maxlength="2" placeholder="MM" class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">到期年</label>
                    <input type="text" name="exp_year" inputmode="numeric" maxlength="4" placeholder="YYYY" class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">備註名稱</label>
                <input type="text" name="label" maxlength="100" placeholder="如：公司卡" class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="is_default" value="1" class="rounded text-blue-600 focus:ring-blue-500"> 設為預設卡片
            </label>
            <div class="flex items-center gap-2 pt-1">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition">新增卡片</button>
                <button type="button" @click="showAdd = false" class="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition">取消</button>
            </div>
        </form>
    </div>

    <!-- 卡片列表 -->
    <?php if ($cards === []): ?>
        <div class="bg-white dark:bg-surface-card rounded-xl border border-slate-200 dark:border-surface-border p-10 text-center text-slate-400">尚無付款卡片</div>
    <?php else: ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <?php foreach ($cards as $card): $isDefault = (int) ($card['is_default'] ?? 0) === 1; ?>
        <div class="bg-white dark:bg-surface-card border border-slate-200 dark:border-surface-border rounded-xl p-5">
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-semibold text-slate-800 dark:text-slate-100"><?= e($card['brand']) ?></span>
                        <?php if ($isDefault): ?><span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-600 text-white">預設</span><?php endif; ?>
                    </div>
                    <p class="mt-1 text-slate-600 dark:text-slate-300 font-mono">•••• •••• •••• <?= e($card['last4']) ?></p>
                    <?php if (($card['exp_month'] ?? null) && ($card['exp_year'] ?? null)): ?>
                    <p class="text-xs text-slate-400 mt-0.5">有效期限 <?= e(str_pad((string) $card['exp_month'], 2, '0', STR_PAD_LEFT)) ?>/<?= e((string) $card['exp_year']) ?></p>
                    <?php endif; ?>
                    <?php if (($card['label'] ?? '') !== ''): ?><p class="text-xs text-slate-400 mt-0.5"><?= e($card['label']) ?></p><?php endif; ?>
                </div>
            </div>
            <div class="flex items-center gap-3 mt-4 pt-4 border-t border-slate-100 dark:border-surface-border">
                <?php if (!$isDefault): ?>
                <form method="POST" action="/portal/payment-methods/<?= (int) $card['id'] ?>/default">
                    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                    <button type="submit" class="text-blue-600 dark:text-blue-400 hover:underline text-xs font-medium">設為預設</button>
                </form>
                <?php endif; ?>
                <form method="POST" action="/portal/payment-methods/<?= (int) $card['id'] ?>/delete" onsubmit="return confirm('確定要刪除此卡片？')">
                    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                    <button type="submit" class="text-red-600 dark:text-red-400 hover:text-red-700 text-xs font-medium">刪除</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
