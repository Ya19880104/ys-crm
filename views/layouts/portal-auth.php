<?php
/**
 * 客戶 Portal 認證版面（登入頁）。無側欄、置中卡片，深藍品牌。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $content
 * @var string $title
 * @var array  $_flash
 */

use function YangSheep\CRM\Core\e;
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? '客戶專區登入') ?> · 客戶專區</title>
    <?php require VIEWS_PATH . '/partials/tailwind-config.php'; ?>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="<?= \YangSheep\CRM\Core\asset('/assets/css/app.css') ?>">
    <style>[x-cloak]{display:none!important;}</style>
</head>
<body class="ys-control-scope min-h-screen bg-slate-100 text-slate-800 flex items-center justify-center p-4">
    <div class="w-full max-w-sm">
        <!-- 品牌 -->
        <div class="text-center mb-6">
            <span class="inline-flex w-12 h-12 rounded-xl bg-gradient-to-br from-brand-light to-brand-accent items-center justify-center text-white font-bold text-lg">羊</span>
            <h1 class="mt-3 text-lg font-bold text-navy-900">客戶專區</h1>
        </div>

        <?php if (!empty($_flash['success'])): ?>
        <div class="mb-4 bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded-lg text-sm"><?= e($_flash['success']) ?></div>
        <?php endif; ?>
        <?php if (!empty($_flash['info'])): ?>
        <div class="mb-4 bg-slate-50 border border-slate-200 text-slate-700 px-4 py-3 rounded-lg text-sm"><?= e($_flash['info']) ?></div>
        <?php endif; ?>
        <?php if (!empty($_flash['error'])): ?>
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm"><?= e($_flash['error']) ?></div>
        <?php endif; ?>

        <?= $content ?>

        <p class="text-center text-xs text-slate-400 mt-6">&copy; <?= date('Y') ?> YANGSHEEP DESIGN</p>
    </div>
</body>
</html>
