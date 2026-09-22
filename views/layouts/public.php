<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= \YangSheep\CRM\Core\e($_csrf ?? '') ?>">
    <title><?= \YangSheep\CRM\Core\e($title ?? 'YS CRM') ?> - <?= \YangSheep\CRM\Core\e($_site_name ?? 'YS CRM') ?></title>
    <?php
        $ogUrl = ($_ENV['APP_URL'] ?? '') ?: (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? ''));
        $ogImage = $_og_image ?? '/assets/images/og-image.png';
        $siteName = $_site_name ?? 'YS CRM';
        $siteDesc = $_site_desc ?? '';
    ?>
    <meta property="og:title" content="<?= \YangSheep\CRM\Core\e($title ?? 'YS CRM') ?> - <?= \YangSheep\CRM\Core\e($siteName) ?>">
    <meta property="og:description" content="<?= \YangSheep\CRM\Core\e($siteDesc ?: 'YS CRM 客戶關係管理系統') ?>">
    <meta property="og:image" content="<?= \YangSheep\CRM\Core\e($ogUrl . $ogImage) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= \YangSheep\CRM\Core\e($ogUrl . ($_SERVER['REQUEST_URI'] ?? '/')) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= \YangSheep\CRM\Core\e($title ?? 'YS CRM') ?> - <?= \YangSheep\CRM\Core\e($siteName) ?>">
    <meta name="twitter:description" content="<?= \YangSheep\CRM\Core\e($siteDesc ?: 'YS CRM 客戶關係管理系統') ?>">
    <meta name="twitter:image" content="<?= \YangSheep\CRM\Core\e($ogUrl . $ogImage) ?>">
    <!-- 主題初始化（在繪製前套用，避免深色閃爍 FOUC） -->
    <script>
        (function () {
            try {
                var m = document.cookie.match(/(?:^|;\s*)ys_theme=(dark|light)/);
                var t = m ? m[1] : (localStorage.getItem('ys_theme') || 'light');
                if (t === 'dark') { document.documentElement.classList.add('dark'); }
            } catch (e) {}
        })();
    </script>
    <?php require VIEWS_PATH . '/partials/tailwind-config.php'; ?>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="<?= \YangSheep\CRM\Core\asset('/assets/css/ys-tokens.css') ?>">
    <link rel="stylesheet" href="<?= \YangSheep\CRM\Core\asset('/assets/css/app.css') ?>">
    <style>
        .site-container { width: 100%; padding-left: 5vw; padding-right: 5vw; }
        @media (max-width: 640px) { .site-container { padding-left: 20px; padding-right: 20px; } }
    </style>
</head>
<body class="bg-slate-50 dark:bg-surface-dark text-slate-800 dark:text-slate-100 min-h-screen transition-colors">
    <!-- 頂部導航 -->
    <nav class="bg-white dark:bg-navy-topbar shadow-sm border-b border-slate-200 dark:border-surface-border transition-colors"
         x-data="{
             mobileMenu: false,
             openMenu() { this.mobileMenu = true; },
             closeMenu() { this.mobileMenu = false; this.$nextTick(() => this.$refs.menuToggle?.focus()); }
         }"
         @keydown.escape.window="if (mobileMenu) closeMenu()">
        <div class="site-container">
            <div class="flex justify-between h-14 sm:h-16 items-center">
                <a href="/" class="flex items-center gap-2.5 min-w-0 mr-2">
                    <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-brand-light to-brand-accent flex items-center justify-center text-white font-bold text-sm flex-shrink-0">羊</span>
                    <span class="text-base sm:text-xl font-bold text-slate-800 dark:text-slate-100 truncate"><?= \YangSheep\CRM\Core\e($siteName) ?></span>
                </a>
                <!-- 桌面選單 -->
                <div class="hidden sm:flex items-center gap-6">
                    <a href="/login" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700 transition shadow-sm">管理員登入</a>
                </div>
                <!-- 手機漢堡 -->
                <button type="button" x-ref="menuToggle" @click="mobileMenu ? closeMenu() : openMenu()"
                        class="sm:hidden ys-touch-icon text-slate-600 dark:text-slate-300"
                        aria-label="切換選單"
                        aria-controls="ys-public-menu"
                        :aria-expanded="mobileMenu ? 'true' : 'false'">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
            </div>
            <!-- 手機展開選單 -->
            <?php /* x-collapse 收合後高度為 0 但元素仍在，連結會留在 tab 順序 → 用 inert 一併退出。 */ ?>
            <div id="ys-public-menu" x-show="mobileMenu" x-collapse
                 :inert="!mobileMenu"
                 class="sm:hidden pb-3 space-y-1 border-t border-slate-200 dark:border-surface-border">
                <a href="/login" class="block px-2 py-2 text-blue-600 dark:text-brand-dark font-medium text-sm">管理員登入</a>
            </div>
        </div>
    </nav>

    <!-- Flash 訊息 -->
    <?php if (!empty($_flash['success'])): ?>
    <div class="site-container mt-4">
        <div class="bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30 text-emerald-700 dark:text-emerald-300 px-4 py-3 rounded-lg" x-data="{ show: true }" x-show="show">
            <div class="flex justify-between items-center">
                <span><?= \YangSheep\CRM\Core\e($_flash['success']) ?></span>
                <button @click="show = false" class="text-emerald-600 hover:text-emerald-700 dark:hover:text-emerald-200">&times;</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($_flash['error'])): ?>
    <div class="site-container mt-4">
        <div class="bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg" x-data="{ show: true }" x-show="show">
            <div class="flex justify-between items-center">
                <span><?= \YangSheep\CRM\Core\e($_flash['error']) ?></span>
                <button @click="show = false" class="text-red-600 dark:text-red-400 hover:text-red-700 dark:hover:text-red-200">&times;</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 主要內容 -->
    <main class="site-container py-8">
        <?= $content ?>
    </main>

    <!-- 頁尾 -->
    <footer class="bg-white dark:bg-navy-topbar border-t border-slate-200 dark:border-surface-border mt-auto transition-colors">
        <div class="site-container py-6 text-center text-slate-500 dark:text-slate-400 text-sm">
            &copy; <?= date('Y') ?> YANGSHEEP DESIGN. All rights reserved.
        </div>
    </footer>

    <script src="<?= \YangSheep\CRM\Core\asset('/assets/js/app.js') ?>"></script>
</body>
</html>
