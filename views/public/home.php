<?php
/**
 * 公開首頁 — YS CRM 歡迎頁（auth 版面：全深藍、全高、無 header）
 *
 * 前台僅顯示歡迎訊息與管理員登入入口。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_site_logo 網站 Logo 路徑（後台「系統設定 → 網站資訊」可上傳；留空則只顯示網站名稱文字）
 */
$view->layout('auth');

use function YangSheep\CRM\Core\e;

$siteName = $_site_name ?? 'CRM';
$siteLogo = $_site_logo ?? '';
?>

<div class="relative min-h-screen flex items-center justify-center overflow-hidden px-6">
    <!-- 裝飾光暈 -->
    <div class="pointer-events-none absolute -top-32 -right-24 w-[28rem] h-[28rem] rounded-full bg-brand-light/15 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-40 -left-24 w-[28rem] h-[28rem] rounded-full bg-brand-accent/10 blur-3xl"></div>

    <div class="relative text-center max-w-xl">
        <?php if ($siteLogo !== ''): ?>
            <img src="<?= e($siteLogo) ?>" alt="<?= e($siteName) ?> logo"
                 class="mx-auto h-20 max-w-[280px] object-contain mb-7">
        <?php endif; ?>

        <h1 class="text-4xl sm:text-5xl font-bold text-white tracking-tight">
            <?= e($siteName) ?>
        </h1>
        <p class="mt-4 text-slate-300 text-base sm:text-lg leading-relaxed">
            客戶關係管理系統，請登入以使用後台功能。
        </p>
        <div class="mt-9">
            <a href="/login"
               class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-3 rounded-lg text-sm font-medium hover:bg-blue-700 transition shadow-lg shadow-brand-light/20">
                管理員登入
            </a>
        </div>

        <p class="mt-16 text-xs text-slate-500">&copy; <?= date('Y') ?> YANGSHEEP DESIGN. All rights reserved.</p>
    </div>
</div>
