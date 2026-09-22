<?php
/**
 * 公開報價單 — 需登入客戶專區（visibility=customer_only）。
 *
 * 註：P4-2 起，PublicQuoteController 對 customer_only 報價未登入時「直接導向」
 *     /portal/login?return=/q/{token}（登入後自動返回本報價），通常不會渲染本頁。
 *     本頁保留為導引備援（如直接被引用），提供前往客戶專區登入的按鈕。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var array  $company
 * @var string $loginUrl  前往客戶專區登入的網址（含 return）
 */

use function YangSheep\CRM\Core\e;

$loginUrl = $loginUrl ?? '/portal/login';
?>

<div class="quote-doc mx-auto max-w-md">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-8 text-center">
        <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-blue-50 text-blue-600 mb-4">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
            </svg>
        </div>
        <h1 class="text-xl font-bold text-slate-900">此報價單僅限客戶檢視</h1>
        <p class="text-sm text-slate-500 mt-2 mb-6">
            此報價單設定為「僅限客戶」，請以您的客戶帳號登入客戶專區後檢視。
        </p>
        <a href="<?= e($loginUrl) ?>"
           class="inline-flex items-center justify-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
            </svg>
            登入客戶專區
        </a>
        <?php if (($company['email'] ?? '') !== '' || ($company['phone'] ?? '') !== ''): ?>
        <div class="text-sm text-slate-600 bg-slate-50 rounded-lg px-4 py-3 mt-6">
            <p class="text-xs text-slate-400 mb-1">如需協助請聯絡我們</p>
            <?php if (($company['phone'] ?? '') !== ''): ?><p>電話：<?= e($company['phone']) ?></p><?php endif; ?>
            <?php if (($company['email'] ?? '') !== ''): ?><p><?= e($company['email']) ?></p><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
