<?php
/**
 * 兩階段驗證 — 啟用設定頁
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var array  $_flash
 * @var string $secret  Base32 secret（供手動輸入）
 * @var string $uri     otpauth:// provisioning URI（供 QR）
 * @var string $issuer  發行者名稱
 */

use function YangSheep\CRM\Core\e;
use YangSheep\CRM\Core\Csrf;

$view->layout('auth');

// secret 以 4 字一組分隔，方便手動輸入時辨識
$secretGrouped = trim(chunk_split($secret, 4, ' '));
?>

<div class="min-h-screen flex items-center justify-center px-6 py-12">
    <div class="w-full max-w-md">
        <div class="bg-white dark:bg-surface-card rounded-2xl shadow-lg dark:shadow-black/30 border border-slate-200 dark:border-surface-border p-8">
            <div class="text-center mb-6">
                <div class="mx-auto w-16 h-16 bg-blue-100 dark:bg-brand-light/15 rounded-full flex items-center justify-center mb-4">
                    <svg class="w-8 h-8 text-blue-600 dark:text-brand-dark" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-slate-800 dark:text-slate-100">啟用兩階段驗證</h1>
                <p class="text-slate-500 dark:text-slate-400 text-sm mt-1">使用驗證器 App 掃描 QR 碼或手動輸入金鑰</p>
            </div>

            <?php if (!empty($_flash['error'])): ?>
            <div class="mb-6 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg text-sm">
                <?= e($_flash['error']) ?>
            </div>
            <?php endif; ?>

            <!-- 步驟 1：掃描 / 手動輸入 -->
            <ol class="text-sm text-slate-600 dark:text-slate-300 space-y-1 mb-4 list-decimal list-inside">
                <li>於手機安裝驗證器 App（Google Authenticator、Authy、1Password 等）。</li>
                <li>掃描下方 QR 碼，或手動輸入金鑰。</li>
                <li>輸入 App 顯示的 6 位數驗證碼以完成啟用。</li>
            </ol>

            <!-- QR 碼（以 CDN 函式庫於前端產生，secret 不外送第三方 API） -->
            <div class="flex justify-center mb-4">
                <div id="qrcode" class="p-3 bg-white border border-slate-200 rounded-lg"></div>
            </div>

            <!-- 手動金鑰 -->
            <div class="mb-6">
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">手動輸入金鑰（Base32）</label>
                <div class="font-mono text-sm bg-slate-50 dark:bg-navy-topbar border border-slate-200 dark:border-surface-border rounded-lg px-3 py-2 break-all select-all text-slate-800 dark:text-slate-100">
                    <?= e($secretGrouped) ?>
                </div>
            </div>

            <!-- 驗證表單 -->
            <form method="POST" action="/admin/2fa/setup" class="space-y-4">
                <?= Csrf::field() ?>
                <div>
                    <label for="current_password" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">目前密碼</label>
                    <input type="password"
                           id="current_password"
                           name="current_password"
                           required
                           autocomplete="current-password"
                           class="w-full px-4 py-2.5 bg-white dark:bg-navy-topbar border border-slate-300 dark:border-surface-border rounded-lg text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:ring-2 focus:ring-brand-light focus:border-brand-light transition text-sm"
                           placeholder="請輸入您目前的登入密碼">
                </div>
                <div>
                    <label for="code" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">驗證碼</label>
                    <input type="text"
                           id="code"
                           name="code"
                           inputmode="numeric"
                           autocomplete="one-time-code"
                           pattern="[0-9]*"
                           maxlength="6"
                           required
                           autofocus
                           class="w-full px-4 py-2.5 bg-white dark:bg-navy-topbar border border-slate-300 dark:border-surface-border rounded-lg text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-brand-light focus:border-brand-light transition text-center tracking-[0.5em] font-mono text-lg"
                           placeholder="000000">
                </div>
                <button type="submit"
                        class="w-full bg-blue-600 text-white py-2.5 px-4 rounded-lg font-medium hover:bg-blue-700 focus:ring-2 focus:ring-brand-light focus:ring-offset-2 dark:focus:ring-offset-surface-card transition text-sm shadow-sm">
                    驗證並啟用
                </button>
            </form>
        </div>

        <div class="text-center mt-6">
            <a href="/admin/dashboard" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition">稍後再說</a>
        </div>
    </div>
</div>

<!-- QR 產生：使用 qrcodejs（純前端，secret 不離開瀏覽器） -->
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
(function () {
    var uri = <?= json_encode($uri, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var el = document.getElementById('qrcode');
    if (el && window.QRCode) {
        new QRCode(el, { text: uri, width: 180, height: 180, correctLevel: QRCode.CorrectLevel.M });
    } else if (el) {
        // 函式庫載入失敗時的後備：顯示連結提示，使用者仍可手動輸入金鑰
        el.innerHTML = '<p class="text-xs text-slate-400 max-w-[180px] text-center">QR 載入失敗，請以上方金鑰手動新增。</p>';
    }
})();
</script>
