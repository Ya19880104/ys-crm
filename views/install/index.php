<?php $view->layout('install'); ?>

<h2 class="text-xl font-bold text-white mb-6">Step 1：環境檢測</h2>
<p class="text-gray-400 mb-6">系統將檢查伺服器環境是否符合執行需求。</p>

<?php if (!empty($structureMoved)): ?>
<div class="bg-blue-900/30 border border-blue-700/50 text-blue-300 px-4 py-3 rounded-lg mb-6">
    應用程式檔案已成功搬移至 web root 外，安全性已提升。
</div>
<?php endif; ?>

<?php if (\YangSheep\CRM\Core\Session::hasFlash('error')): ?>
<div class="bg-red-900/50 border border-red-700 text-red-300 px-4 py-3 rounded-lg mb-6">
    <?= \YangSheep\CRM\Core\e(\YangSheep\CRM\Core\Session::getFlash('error')) ?>
</div>
<?php endif; ?>

<div class="space-y-3">
    <?php foreach ($results as $key => $item): ?>
    <div class="flex items-center justify-between py-3 px-4 rounded-lg <?= !empty($item['warning']) ? 'bg-yellow-900/30 border border-yellow-700/50' : 'bg-slate-700/50' ?>">
        <div class="flex items-center gap-3">
            <?php if (!empty($item['warning'])): ?>
                <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
            <?php elseif ($item['pass']): ?>
                <svg class="w-5 h-5 text-emerald-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
            <?php else: ?>
                <svg class="w-5 h-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                </svg>
            <?php endif; ?>
            <span class="text-gray-200 font-medium"><?= \YangSheep\CRM\Core\e($item['label']) ?></span>
        </div>
        <span class="text-sm <?= !empty($item['warning']) ? 'text-yellow-400' : ($item['pass'] ? 'text-gray-400' : 'text-red-400') ?>">
            <?= \YangSheep\CRM\Core\e($item['detail']) ?>
        </span>
    </div>

    <?php // 可自動搬移 → 顯示按鈕 ?>
    <?php if (!empty($item['can_move'])): ?>
    <div class="ml-8 bg-yellow-900/20 rounded-lg p-4 border border-yellow-700/30">
        <p class="text-sm text-yellow-300 mb-3">偵測到敏感檔案在 web root 內，建議搬移到上層目錄以提高安全性。</p>
        <form method="POST" action="/install/move-structure">
            <input type="hidden" name="_csrf_token" value="<?= \YangSheep\CRM\Core\e($csrf) ?>">
            <button type="submit" class="bg-yellow-600 hover:bg-yellow-500 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                搬移至安全位置
            </button>
        </form>
    </div>
    <?php endif; ?>

    <?php // 無法自動搬移 → 顯示手動指令 ?>
    <?php if (!empty($item['manual_commands'])): ?>
    <div class="ml-8 bg-slate-800 rounded-lg p-4 border border-yellow-700/30">
        <p class="text-sm text-yellow-300 mb-2">請透過 SSH 執行以下指令將敏感檔案搬到 web root 外：</p>
        <pre class="text-xs text-gray-300 font-mono overflow-x-auto whitespace-pre"><?= \YangSheep\CRM\Core\e($item['manual_commands']) ?></pre>
        <p class="text-xs text-gray-500 mt-2">搬移後重新整理此頁面，系統會自動偵測新位置。</p>
    </div>
    <?php endif; ?>

    <?php endforeach; ?>
</div>

<div class="mt-8 flex justify-end">
    <?php if ($allPassed): ?>
        <a href="/install/step-db" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg font-medium transition">
            下一步：資料庫設定 &rarr;
        </a>
    <?php else: ?>
        <div class="text-red-400 text-sm mr-4 mt-2">請先修正上述問題後再繼續</div>
        <a href="/install" class="bg-slate-600 hover:bg-slate-500 text-white px-6 py-2.5 rounded-lg font-medium transition">
            重新檢測
        </a>
    <?php endif; ?>
</div>
