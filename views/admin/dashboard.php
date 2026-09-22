<?php
/**
 * 後台儀表板 — YS CRM 歡迎頁
 *
 * @var \YangSheep\CRM\Core\View $view
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

$siteName = $_site_name ?? 'YS CRM';
?>

<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-8 sm:p-12 text-center max-w-2xl mx-auto transition-colors">
    <div class="w-14 h-14 mx-auto mb-5 bg-blue-100 dark:bg-brand-light/15 rounded-2xl flex items-center justify-center">
        <svg class="w-7 h-7 text-blue-600 dark:text-brand-dark" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
        </svg>
    </div>
    <h2 class="text-2xl font-bold text-slate-800 dark:text-slate-100">歡迎使用 <?= e($siteName) ?></h2>
    <p class="mt-3 text-slate-500 dark:text-slate-400 leading-relaxed">
        客戶、報價單、工作看板、主機管理等模組建置中。
    </p>
</div>
