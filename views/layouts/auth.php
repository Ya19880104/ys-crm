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
    <link rel="stylesheet" href="<?= \YangSheep\CRM\Core\asset('/assets/css/app.css') ?>">
</head>
<!--
    登入流程專用版面：整頁深藍、無導覽列 / 無頁尾 chrome。
    此版面「固定」深藍（不隨 light/dark 切換改變底色），登入 / 首頁本來就要深色品牌感。
-->
<body class="ys-control-scope min-h-screen bg-gradient-to-br from-navy-900 via-surface-dark to-navy-950 text-slate-100 antialiased">

    <!-- 浮動 Flash 訊息（右上角，深底可讀的半透明卡片） -->
    <?php if (!empty($_flash['success']) || !empty($_flash['error'])): ?>
    <div class="fixed top-4 inset-x-4 sm:inset-x-auto sm:right-4 sm:max-w-sm z-50 space-y-2">
        <?php if (!empty($_flash['success'])): ?>
        <div class="flex items-start justify-between gap-3 rounded-xl border border-emerald-400/30 bg-emerald-500/15 backdrop-blur px-4 py-3 text-sm text-emerald-100 shadow-lg shadow-black/30"
             x-data="{ show: true }" x-show="show" x-transition.opacity>
            <span><?= \YangSheep\CRM\Core\e($_flash['success']) ?></span>
            <button @click="show = false" class="text-emerald-200/80 hover:text-white leading-none text-lg" aria-label="關閉訊息">&times;</button>
        </div>
        <?php endif; ?>
        <?php if (!empty($_flash['error'])): ?>
        <div class="flex items-start justify-between gap-3 rounded-xl border border-red-400/30 bg-red-500/15 backdrop-blur px-4 py-3 text-sm text-red-100 shadow-lg shadow-black/30"
             x-data="{ show: true }" x-show="show" x-transition.opacity>
            <span><?= \YangSheep\CRM\Core\e($_flash['error']) ?></span>
            <button @click="show = false" class="text-red-200/80 hover:text-white leading-none text-lg" aria-label="關閉訊息">&times;</button>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- 主要內容（撐滿） -->
    <?= $content ?>

    <script src="<?= \YangSheep\CRM\Core\asset('/assets/js/app.js') ?>"></script>
</body>
</html>
