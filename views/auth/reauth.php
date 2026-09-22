<?php
/**
 * step-up 再認證 — 敏感操作前確認本人
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var array  $_flash
 * @var string $return        驗證成功後導回的站內路徑（已於後端過濾）
 * @var bool   $requiresTotp  使用者是否已啟用 2FA（啟用則需一併輸入 TOTP）
 */

use function YangSheep\CRM\Core\e;
use YangSheep\CRM\Core\Csrf;

$view->layout('auth');
?>

<div class="min-h-screen flex items-center justify-center px-6 py-12">
    <div class="w-full max-w-md">
        <div class="bg-white dark:bg-surface-card rounded-2xl shadow-lg dark:shadow-black/30 border border-slate-200 dark:border-surface-border p-8">
            <div class="text-center mb-8">
                <div class="mx-auto w-16 h-16 bg-amber-100 dark:bg-amber-400/15 rounded-full flex items-center justify-center mb-4">
                    <svg class="w-8 h-8 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-slate-800 dark:text-slate-100">安全驗證</h1>
                <p class="text-slate-500 dark:text-slate-400 text-sm mt-1">此為敏感操作，請重新確認您的身分</p>
            </div>

            <?php if (!empty($_flash['error'])): ?>
            <div class="mb-6 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg text-sm">
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span><?= e($_flash['error']) ?></span>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST" action="/admin/reauth" class="space-y-5">
                <?= Csrf::field() ?>
                <input type="hidden" name="return" value="<?= e($return) ?>">

                <!-- 密碼 -->
                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">密碼</label>
                    <input type="password"
                           id="password"
                           name="password"
                           required
                           autocomplete="current-password"
                           autofocus
                           class="w-full px-4 py-2.5 bg-white dark:bg-navy-topbar border border-slate-300 dark:border-surface-border rounded-lg text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:ring-2 focus:ring-brand-light focus:border-brand-light transition text-sm"
                           placeholder="請輸入目前密碼">
                </div>

                <?php if (!empty($requiresTotp)): ?>
                <!-- TOTP（帳號已啟用 2FA） -->
                <div>
                    <label for="code" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">驗證碼</label>
                    <input type="text"
                           id="code"
                           name="code"
                           inputmode="numeric"
                           autocomplete="one-time-code"
                           pattern="[0-9]*"
                           maxlength="6"
                           required
                           class="w-full px-4 py-2.5 bg-white dark:bg-navy-topbar border border-slate-300 dark:border-surface-border rounded-lg text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-brand-light focus:border-brand-light transition text-center tracking-[0.5em] font-mono text-lg"
                           placeholder="000000">
                </div>
                <?php endif; ?>

                <button type="submit"
                        class="w-full bg-blue-600 text-white py-2.5 px-4 rounded-lg font-medium hover:bg-blue-700 focus:ring-2 focus:ring-brand-light focus:ring-offset-2 dark:focus:ring-offset-surface-card transition text-sm shadow-sm">
                    驗證並繼續
                </button>
            </form>
        </div>

        <div class="text-center mt-6">
            <a href="/admin/dashboard" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition">
                &larr; 返回儀表板
            </a>
        </div>
    </div>
</div>
