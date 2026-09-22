<?php $view->layout('install'); ?>

<h2 class="text-xl font-bold text-white mb-6">Step 6：基本設定</h2>
<p class="text-gray-400 mb-6">設定站台基本資訊與安全金鑰。Turnstile 金鑰可稍後再設定。</p>

<?php if (\YangSheep\CRM\Core\Session::hasFlash('error')): ?>
<div class="bg-red-900/50 border border-red-700 text-red-300 px-4 py-3 rounded-lg mb-6">
    <?= \YangSheep\CRM\Core\e(\YangSheep\CRM\Core\Session::getFlash('error')) ?>
</div>
<?php endif; ?>

<form method="POST" action="/install/step-config">
    <?= \YangSheep\CRM\Core\Csrf::field() ?>

    <div class="space-y-6">
        <!-- 站台設定 -->
        <div>
            <h3 class="text-sm font-semibold text-blue-400 uppercase tracking-wide mb-3">站台設定</h3>
            <div class="space-y-3">
                <div>
                    <label class="block text-sm text-gray-300 mb-1">站台名稱</label>
                    <input type="text" name="site_name" value="YS CRM" required
                           class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white focus:border-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm text-gray-300 mb-1">站台網址</label>
                    <div class="w-full bg-slate-800 border border-slate-600 rounded-lg px-3 py-2 text-gray-300 break-all">
                        <?= \YangSheep\CRM\Core\e($detectedUrl ?? '') ?>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">已於資料庫設定階段依部署者指定的主機固定。</p>
                </div>
                <div>
                    <label class="block text-sm text-gray-300 mb-1">時區</label>
                    <div class="w-full bg-slate-800 border border-slate-600 rounded-lg px-3 py-2 text-gray-300">
                        <?= \YangSheep\CRM\Core\e($siteTimezone ?? 'Asia/Taipei') ?>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">已於資料庫設定階段固定，避免既有 timestamp 混用不同時鐘。</p>
                </div>
            </div>
        </div>

        <!-- Turnstile 設定 -->
        <div>
            <h3 class="text-sm font-semibold text-blue-400 uppercase tracking-wide mb-3">
                Cloudflare Turnstile
                <span class="text-gray-500 font-normal text-xs">（選填，稍後可在系統設定中配置）</span>
            </h3>
            <div class="space-y-3">
                <div>
                    <label class="block text-sm text-gray-300 mb-1">Site Key</label>
                    <input type="text" name="turnstile_site_key"
                           class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white focus:border-blue-500 focus:outline-none"
                           placeholder="0x...">
                </div>
                <div>
                    <label class="block text-sm text-gray-300 mb-1">Secret Key</label>
                    <input type="password" name="turnstile_secret_key"
                           class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white focus:border-blue-500 focus:outline-none"
                           placeholder="0x...">
                </div>
            </div>
        </div>
    </div>

    <div class="mt-8 flex items-center justify-between">
        <a href="/install/step-admin" class="text-gray-400 hover:text-white transition">&larr; 返回</a>
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg font-medium transition">
            完成安裝 &rarr;
        </button>
    </div>
</form>
