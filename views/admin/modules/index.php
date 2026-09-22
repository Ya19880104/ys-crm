<?php
use function YangSheep\CRM\Core\e;
$view->layout('admin');
?>
<?php $view->partial('components/module-readiness-link-styles'); ?>
<section aria-labelledby="module-inventory-heading">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
            <h2 id="module-inventory-heading" class="text-xl font-semibold text-slate-800 dark:text-slate-100">功能模組盤點</h2>
            <p class="text-sm text-slate-600 dark:text-slate-300 mt-2">清冊版本 <?= e($report['catalog_revision']) ?> · 共 <?= e(count($report['modules'])) ?> 項</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a class="ys-btn ys-btn-outline ys-module-link" href="/admin/settings">返回系統設定</a>
            <a class="ys-btn ys-btn-outline ys-module-link" href="/admin/settings/modules">重新整理盤點</a>
        </div>
    </div>
    <div class="rounded-xl border border-slate-200 dark:border-surface-border bg-white dark:bg-surface-card p-4 mb-5 text-slate-700 dark:text-slate-200">
        <p class="font-semibold">僅供盤點，尚不提供啟閉</p>
        <p class="text-sm leading-relaxed mt-2">來源齊全只代表指定檔案存在，不表示設定、資料庫或模組生命週期已就緒。這個頁面不會更改現有功能、安裝套件或刪除資料。</p>
        <p class="text-sm leading-relaxed mt-2">「必備」及依賴關係是架構清冊，不是目前已套用的方案；方案選擇與個別啟用將在後續完成安全驗收後提供。</p>
    </div>
    <?php $view->partial('components/module-readiness-list', ['modules' => $report['modules']]); ?>
</section>
