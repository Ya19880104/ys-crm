<?php
/**
 * 登入頁面 — YS CRM 全深藍無 header（auth 版面，split 品牌版面）
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $_flash
 * @var string $siteKey   Turnstile Site Key
 * @var string $_site_logo 網站 Logo 路徑（後台「系統設定 → 網站資訊」可上傳；留空則只顯示網站名稱文字）
 */
$view->layout('auth');

use function YangSheep\CRM\Core\e;

$siteName = $_site_name ?? 'CRM';
$siteLogo = $_site_logo ?? '';
?>

<div class="min-h-screen grid lg:grid-cols-2">

    <!-- 左側：深藍品牌面板（桌面顯示） -->
    <div class="hidden lg:flex relative overflow-hidden text-white p-12 flex-col justify-between">
        <!-- 裝飾光暈 -->
        <div class="pointer-events-none absolute -top-24 -right-24 w-96 h-96 rounded-full bg-brand-light/20 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-32 -left-20 w-96 h-96 rounded-full bg-brand-accent/10 blur-3xl"></div>

        <!-- 品牌標誌 -->
        <div class="relative flex items-center gap-3">
            <?php if ($siteLogo !== ''): ?>
                <img src="<?= e($siteLogo) ?>" alt="<?= e($siteName) ?> logo" class="h-10 max-w-[160px] object-contain flex-shrink-0">
            <?php endif; ?>
            <span class="text-sm font-semibold tracking-[0.2em] text-slate-200">YANGSHEEP</span>
        </div>

        <!-- 主視覺文案 -->
        <div class="relative">
            <h2 class="text-5xl font-bold tracking-tight"><?= e($siteName) ?></h2>
            <p class="mt-4 text-slate-300 text-lg">客戶・報價・工作・帳務 一站管理</p>
        </div>

        <!-- 版權 -->
        <p class="relative text-xs text-slate-400">&copy; <?= date('Y') ?> YANGSHEEP DESIGN</p>
    </div>

    <!-- 右側：登入卡片（浮在深藍上的淺色卡片） -->
    <div class="flex items-center justify-center p-6 sm:p-10">
        <div class="w-full max-w-md" x-data="{ showPwd: false }">
            <!-- 行動版品牌標題 -->
            <div class="lg:hidden flex items-center gap-2.5 mb-8">
                <?php if ($siteLogo !== ''): ?>
                    <img src="<?= e($siteLogo) ?>" alt="<?= e($siteName) ?> logo" class="h-9 max-w-[140px] object-contain flex-shrink-0">
                <?php endif; ?>
                <span class="text-lg font-bold text-white"><?= e($siteName) ?></span>
            </div>

            <!-- 登入卡片 -->
            <div class="bg-white dark:bg-surface-card rounded-2xl shadow-2xl shadow-black/40 border border-slate-200 dark:border-surface-border p-8">
                <div class="mb-7">
                    <p class="text-sm font-medium text-brand-light dark:text-brand-dark"><?= e($siteName) ?></p>
                    <h1 class="text-2xl font-bold text-slate-800 dark:text-slate-100 mt-1">登入</h1>
                    <p class="text-slate-500 dark:text-slate-400 text-sm mt-1">請輸入您的帳號密碼</p>
                </div>

                <!-- 錯誤訊息 -->
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

                <!-- 登入表單 -->
                <form method="POST" action="/login" class="space-y-5">
                    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">

                    <!-- 帳號 -->
                    <div>
                        <label for="username" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">帳號 / Email</label>
                        <input type="text"
                               id="username"
                               name="username"
                               required
                               autocomplete="username"
                               autofocus
                               class="w-full px-4 py-2.5 bg-white dark:bg-navy-topbar border border-slate-300 dark:border-surface-border rounded-lg text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:ring-2 focus:ring-brand-light focus:border-brand-light transition text-sm"
                               placeholder="alan@yangsheep.com">
                    </div>

                    <!-- 密碼 -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">密碼</label>
                        <div class="relative">
                            <input :type="showPwd ? 'text' : 'password'"
                                   id="password"
                                   name="password"
                                   required
                                   autocomplete="current-password"
                                   class="w-full px-4 py-2.5 pr-11 bg-white dark:bg-navy-topbar border border-slate-300 dark:border-surface-border rounded-lg text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:ring-2 focus:ring-brand-light focus:border-brand-light transition text-sm"
                                   placeholder="請輸入密碼">
                            <?php /* 不可加 tabindex="-1"：那會讓鍵盤使用者完全按不到這顆按鈕，
                                     而「看不到自己打了什麼」正是最需要它的情境。 */ ?>
                            <button type="button" @click="showPwd = !showPwd"
                                    class="absolute inset-y-0 right-0 px-3 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-light rounded-r-lg"
                                    :aria-label="showPwd ? '隱藏密碼' : '顯示密碼'"
                                    :aria-pressed="showPwd ? 'true' : 'false'">
                                <svg x-show="!showPwd" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                <svg x-show="showPwd" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display:none;">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Turnstile 驗證 -->
                    <?php if (!empty($siteKey)): ?>
                    <div class="flex justify-center">
                        <div class="cf-turnstile" data-sitekey="<?= e($siteKey) ?>" data-theme="auto"></div>
                    </div>
                    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                    <?php endif; ?>

                    <!-- 登入按鈕 -->
                    <button type="submit"
                            class="w-full bg-blue-600 text-white py-2.5 px-4 rounded-lg font-medium hover:bg-blue-700 focus:ring-2 focus:ring-brand-light focus:ring-offset-2 dark:focus:ring-offset-surface-card transition text-sm shadow-sm">
                        登入
                    </button>
                </form>
            </div>

            <!-- 底部連結 -->
            <div class="text-center mt-6">
                <a href="/" class="text-sm text-slate-400 hover:text-slate-200 transition">
                    &larr; 返回首頁
                </a>
            </div>
        </div>
    </div>

</div>
