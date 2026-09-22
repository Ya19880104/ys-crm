<?php
/**
 * 客戶 Portal — 找不到資源（404；如報價不屬於本客戶）。
 * 不洩漏資源是否存在，一律顯示通用「找不到」。
 *
 * @var \YangSheep\CRM\Core\View $view
 */

use function YangSheep\CRM\Core\e;
?>

<div class="max-w-md mx-auto bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-10 text-center">
    <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-slate-100 dark:bg-white/5 text-slate-400 mb-4">
        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    </div>
    <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">找不到此項目</h1>
    <p class="text-sm text-slate-500 dark:text-slate-400 mt-2 mb-6">您要查看的項目不存在，或您沒有檢視權限。</p>
    <a href="/portal" class="inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition">返回總覽</a>
</div>
