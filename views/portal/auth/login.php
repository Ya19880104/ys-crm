<?php
/**
 * 客戶 Portal 登入表單。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var string $return  登入後導回目標（站內白名單）
 * @var string $reason  ?reason= 提示（expired / security）
 */

use function YangSheep\CRM\Core\e;

$return = (string) ($return ?? '');
$reason = (string) ($reason ?? '');
?>
<div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
    <?php if ($reason === 'expired'): ?>
    <div class="mb-4 bg-amber-50 border border-amber-200 text-amber-700 px-3 py-2 rounded-lg text-xs">
        您的連線已逾時或帳號狀態已變更，請重新登入。
    </div>
    <?php elseif ($reason === 'security'): ?>
    <div class="mb-4 bg-amber-50 border border-amber-200 text-amber-700 px-3 py-2 rounded-lg text-xs">
        基於安全考量，請重新登入。
    </div>
    <?php endif; ?>

    <h2 class="text-base font-semibold text-slate-800 mb-1">登入</h2>
    <p class="text-xs text-slate-500 mb-5">請輸入您的客戶帳號 Email 與密碼。</p>

    <form method="POST" action="/portal/login" class="space-y-4">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
        <?php if ($return !== ''): ?>
        <input type="hidden" name="return" value="<?= e($return) ?>">
        <?php endif; ?>

        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Email</label>
            <input type="email" name="email" required autofocus maxlength="255" autocomplete="username"
                   class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                   placeholder="you@example.com">
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">密碼</label>
            <input type="password" name="password" required autocomplete="current-password"
                   class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                   placeholder="請輸入密碼">
        </div>

        <button type="submit"
                class="w-full bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
            登入
        </button>
    </form>
</div>
