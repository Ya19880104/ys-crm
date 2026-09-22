<?php
/**
 * 公開報價頁專用版面（自有版面，§7.8）。
 * 專業報價單文件感、深藍 accent、響應式、含列印 CSS（window.print 作 PDF 出口）。
 *
 * 與後台 admin / public 版面分離：無側欄、無後台導航；僅報價文件本身。
 * 列印時（@media print）隱藏所有 .no-print 元素（按鈕 / 頁首列 / 頁尾），輸出乾淨報價單。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $content
 * @var string $title
 * @var array  $_flash
 * @var array  $company   我方公司資訊（name/logo...）
 */

use function YangSheep\CRM\Core\e;

$company = $company ?? [];
$companyName = (string) ($company['name'] ?? '') !== '' ? (string) $company['name'] : 'YANGSHEEP DESIGN';
$companyLogo = (string) ($company['logo'] ?? '');
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- 公開報價頁：不應被搜尋引擎索引（與全域 X-Robots-Tag 一致） -->
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title><?= e($title ?? '報價單') ?> · <?= e($companyName) ?></title>
    <?php require VIEWS_PATH . '/partials/tailwind-config.php'; ?>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        body { background: #F1F5F9; }
        /* 報價文件 A4 比例容器 */
        .quote-doc { max-width: 880px; }
        /* 🔴 列印樣式**只定義在報價頁**（views/public/quote/show.php），不要在這裡再寫一份。
         *
         * 原本這裡也有一組 @media print，其中 `@page { margin: 14mm }` 與報價頁的
         * `@page { size: A4 portrait; margin: 12mm }` 互相衝突 —— @page 的描述子會
         * 合併，最終邊界取決於樣式順序，等於「看不出是誰贏」。
         * 同一件事在兩個檔案各定義一次，改其中一個永遠會忘記另一個。
         *
         * 但「隱藏 .no-print」必須留在這裡：這個 layout 也服務付款相關頁面，
         * 那些 view 沒有自己的列印樣式，唯一的來源就是這裡。 */
        @media print {
            body { background: #fff !important; }

            /* 🔴 【為何這裡必須有】這個 layout 自己就輸出 4 個 .no-print 元素
               （深藍品牌頁首、兩個 flash、頁尾），而且它同時服務
               PublicPaymentController 的 7 個 render 點（付款結果、付款方式、
               密碼頁、not-found、need-login）。那些 view 不載入 app.css，
               全站唯一定義 .no-print 的地方是報價頁自己的 <style>。
               先前把這條規則移除時註解寫著「共用的部分已包含在報價頁那一份裡」——
               那句話只對報價頁成立，客戶列印付款收據就會印出整條深色頁首與頁尾。 */
            .no-print { display: none !important; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body class="min-h-screen text-slate-800">
    <!-- 頂部品牌列（列印時隱藏） -->
    <header class="no-print bg-navy-900 text-white">
        <div class="quote-doc mx-auto px-6 py-4 flex items-center gap-3">
            <?php if ($companyLogo !== ''): ?>
                <img src="<?= e($companyLogo) ?>" alt="<?= e($companyName) ?>" class="w-9 h-9 rounded-lg object-contain bg-white/5">
            <?php else: ?>
                <span class="w-9 h-9 rounded-lg bg-gradient-to-br from-brand-light to-brand-accent flex items-center justify-center text-white font-bold">羊</span>
            <?php endif; ?>
            <span class="font-semibold tracking-wide"><?= e($companyName) ?></span>
        </div>
    </header>

    <!-- Flash 訊息（列印時隱藏） -->
    <?php if (!empty($_flash['success'])): ?>
    <div class="no-print quote-doc mx-auto px-6 mt-4">
        <div class="bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded-lg text-sm" x-data="{ show: true }" x-show="show">
            <div class="flex justify-between items-center">
                <span><?= e($_flash['success']) ?></span>
                <button @click="show = false" class="text-blue-500 hover:text-blue-700">&times;</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($_flash['error'])): ?>
    <div class="no-print quote-doc mx-auto px-6 mt-4">
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm" x-data="{ show: true }" x-show="show">
            <div class="flex justify-between items-center">
                <span><?= e($_flash['error']) ?></span>
                <button @click="show = false" class="text-red-600 dark:text-red-400 hover:text-red-700">&times;</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 主內容 -->
    <main class="py-6 sm:py-10 px-4">
        <?= $content ?>
    </main>

    <!-- 頁尾（列印時隱藏） -->
    <footer class="no-print py-8 text-center text-xs text-slate-400">
        &copy; <?= date('Y') ?> <?= e($companyName) ?>．本報價單由系統線上產生
    </footer>
</body>
</html>
