<?php
/**
 * 客戶 Portal — 個人資料（顯示名稱 + 改密碼；客戶基本資料唯讀）。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var ?array $customer  客戶基本資料（唯讀）
 * @var array  $me        當前帳號 context
 */

use function YangSheep\CRM\Core\e;

$customer = $customer ?? [];
$me = $me ?? [];
?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
    <!-- 帳號資料 + 顯示名稱 -->
    <div class="space-y-5">
        <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
            <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-4">帳號資料</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">登入 Email</dt>
                    <dd class="text-slate-800 dark:text-slate-200 break-all"><?= e($me['login_email'] ?? '') ?></dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">帳號類型</dt>
                    <dd class="text-slate-800 dark:text-slate-200"><?= ($me['role'] ?? '') === 'owner' ? '主帳號' : '子帳號' ?></dd>
                </div>
            </dl>

            <form method="POST" action="/portal/profile" class="mt-5 pt-5 border-t border-slate-100 dark:border-surface-border space-y-3">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">顯示名稱</label>
                    <input type="text" name="display_name" required maxlength="100" value="<?= e($me['display_name'] ?? '') ?>"
                           class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition">儲存</button>
            </form>
        </div>

        <!-- 客戶基本資料（唯讀） -->
        <?php if ($customer !== []): ?>
        <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
            <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-1">公司／客戶資料</h3>
            <p class="text-xs text-slate-400 mb-4">如需修改以下資料，請聯絡我們。</p>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-slate-400">名稱</dt><dd class="text-slate-800 dark:text-slate-200 text-right"><?= e($customer['display_name'] ?? '') ?></dd></div>
                <?php if (($customer['tax_id'] ?? '') !== ''): ?><div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-slate-400">統一編號</dt><dd class="text-slate-800 dark:text-slate-200 text-right"><?= e($customer['tax_id']) ?></dd></div><?php endif; ?>
                <?php if (($customer['phone'] ?? '') !== ''): ?><div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-slate-400">電話</dt><dd class="text-slate-800 dark:text-slate-200 text-right"><?= e($customer['phone']) ?></dd></div><?php endif; ?>
                <?php if (($customer['address'] ?? '') !== ''): ?><div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-slate-400">地址</dt><dd class="text-slate-800 dark:text-slate-200 text-right"><?= e($customer['address']) ?></dd></div><?php endif; ?>
            </dl>
        </div>
        <?php endif; ?>
    </div>

    <!-- 變更密碼 -->
    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
        <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-4">變更密碼</h3>
        <form method="POST" action="/portal/profile/password" class="space-y-3">
            <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">目前密碼</label>
                <input type="password" name="current_password" required autocomplete="current-password"
                       class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">新密碼（至少 8 字）</label>
                <input type="password" name="new_password" required minlength="8" autocomplete="new-password"
                       class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">確認新密碼</label>
                <input type="password" name="new_password_confirmation" required minlength="8" autocomplete="new-password"
                       class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition">變更密碼</button>
        </form>
    </div>
</div>
