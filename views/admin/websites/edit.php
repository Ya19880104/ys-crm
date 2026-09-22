<?php
/**
 * 編輯客戶網站資產頁。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $website
 * @var array  $customers
 * @var array  $caseTypeLabels
 * @var array  $statusLabels
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;
?>

<div class="max-w-3xl">
    <div class="mb-6">
        <a href="/admin/websites" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            返回網站列表
        </a>
    </div>

    <div class="mb-5">
        <h3 class="text-lg font-semibold text-slate-800 dark:text-slate-100">
            編輯網站資產：<?= e($website['customer_name'] ?? ('#' . (int) ($website['customer_id'] ?? 0))) ?>
        </h3>
        <p class="text-sm text-slate-400 mt-1">建立於 <?= e(substr((string) ($website['created_at'] ?? ''), 0, 16)) ?></p>
    </div>

    <?php $view->partial('admin/websites/_form', [
        '_csrf'          => $_csrf,
        'action'         => '/admin/websites/' . (int) $website['id'],
        'submitLabel'    => '儲存變更',
        'customers'      => $customers,
        'caseTypeLabels' => $caseTypeLabels,
        'statusLabels'   => $statusLabels,
        'website'        => $website,
        'presetCustomer' => 0,
    ]); ?>
</div>
