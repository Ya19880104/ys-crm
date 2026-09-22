<?php $view->layout('install'); ?>

<div class="text-center">
    <div class="mb-6">
        <svg class="w-16 h-16 mx-auto text-emerald-400" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
        </svg>
    </div>

    <h2 class="text-2xl font-bold text-white mb-4">系統安裝完成！</h2>
    <p class="text-gray-400 mb-8">YS CRM 已成功安裝並準備就緒。</p>

    <!-- 安裝摘要 -->
    <div class="bg-slate-700/50 rounded-lg p-6 text-left mb-8">
        <h3 class="text-sm font-semibold text-blue-400 uppercase tracking-wide mb-4">安裝摘要</h3>
        <div class="space-y-3 text-sm">
            <div class="flex justify-between">
                <span class="text-gray-400">資料庫</span>
                <span class="text-gray-200"><?= \YangSheep\CRM\Core\e($dbName) ?>（14 張資料表）</span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-400">管理員帳號</span>
                <span class="text-gray-200"><?= \YangSheep\CRM\Core\e($adminUsername) ?></span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-400">管理員 Email</span>
                <span class="text-gray-200"><?= \YangSheep\CRM\Core\e($adminEmail) ?></span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-400">安裝時間</span>
                <span class="text-gray-200"><?= date('Y-m-d H:i:s') ?></span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-400">.env 位置</span>
                <span class="text-gray-200 flex items-center gap-2">
                    <?php if ($envSecure ?? false): ?>
                        <svg class="w-4 h-4 text-emerald-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/>
                        </svg>
                        <span class="text-emerald-400">安全（web root 外）</span>
                    <?php else: ?>
                        <svg class="w-4 h-4 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92z" clip-rule="evenodd"/>
                        </svg>
                        <span class="text-yellow-400">專案目錄內</span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>

    <?php if (!($envSecure ?? true)): ?>
    <!-- .env 搬移建議 -->
    <div class="bg-yellow-900/30 border border-yellow-700/50 rounded-lg p-5 text-left mb-8">
        <h3 class="text-sm font-semibold text-yellow-400 flex items-center gap-2 mb-3">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/>
            </svg>
            安全性建議
        </h3>
        <p class="text-sm text-gray-300 mb-3">
            目前 <code class="bg-slate-700 px-1.5 py-0.5 rounded text-yellow-300">.env</code> 存放在專案目錄內。
            建議將它搬到上一層目錄以提高安全性，系統會自動偵測新位置：
        </p>
        <div class="bg-slate-800 rounded-lg p-3 font-mono text-sm text-gray-300 overflow-x-auto">
            mv <?= \YangSheep\CRM\Core\e($basePath ?? '') ?>/.env <?= \YangSheep\CRM\Core\e($parentPath ?? '') ?>/.env
        </div>
        <p class="text-xs text-gray-500 mt-2">搬移後無需修改任何設定，系統會自動偵測 .env 的位置。</p>
    </div>
    <?php endif; ?>

    <!-- 操作按鈕 -->
    <div class="flex gap-4 justify-center">
        <a href="/login" class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-lg font-medium transition">
            前往後台登入
        </a>
        <a href="/" class="bg-slate-600 hover:bg-slate-500 text-white px-8 py-3 rounded-lg font-medium transition">
            查看公開首頁
        </a>
    </div>
</div>
