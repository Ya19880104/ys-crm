<?php
/**
 * 公開報價單 — 密碼輸入頁（visibility=password 未解鎖時）。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var string $token
 * @var array  $company
 * @var string|null $error
 */

use function YangSheep\CRM\Core\e;
?>

<div class="quote-doc mx-auto max-w-md">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-8 text-center">
        <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-blue-50 text-brand mb-4">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
        </div>
        <h1 class="text-xl font-bold text-slate-900">此報價單受密碼保護</h1>
        <p class="text-sm text-slate-500 mt-2 mb-6">請輸入提供的密碼以檢視報價內容。</p>

        <?php if ($error !== null): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-2.5 rounded-lg text-sm mb-4">
            <?= e($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="/q/<?= e($token) ?>/unlock" class="space-y-4">
            <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
            <input type="password" name="access_password" required autofocus autocomplete="off"
                   class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm text-center"
                   placeholder="請輸入密碼">
            <button type="submit"
                    class="w-full bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
                檢視報價單
            </button>
        </form>
    </div>
</div>
