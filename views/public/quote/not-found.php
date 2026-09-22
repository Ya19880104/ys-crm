<?php
/**
 * 公開報價單 — 通用「無法使用」頁（404）。
 *
 * 以下情況全部顯示這一頁、同一個狀態碼、同一段文字，不讓回應差異洩漏報價是否存在：
 * token 查無、visibility=private、非該客戶的 customer_only，以及匿名分享已關閉／過期／未初始化。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var array  $company
 */

use function YangSheep\CRM\Core\e;
?>

<div class="quote-doc mx-auto max-w-md">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-8 text-center">
        <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-slate-100 text-slate-400 mb-4">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5m6.828-1.328a4 4 0 010-5.656l3-3a4 4 0 015.656 5.656l-1.5 1.5M4 4l16 16"/>
            </svg>
        </div>
        <h1 class="text-xl font-bold text-slate-900">報價連結無法使用</h1>
        <p class="text-sm text-slate-500 mt-2">
            連結無法使用或已關閉，請聯絡提供者。
        </p>
    </div>
</div>
