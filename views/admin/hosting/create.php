<?php
/**
 * 新增客戶主機資產頁。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $customers
 * @var array  $websitesByCust
 * @var array  $typeLabels
 * @var array  $statusLabels
 * @var int    $presetCustomer
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;
?>

<div class="max-w-3xl">
    <div class="mb-6">
        <a href="/admin/hosting" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            返回主機列表
        </a>
    </div>

    <?php $view->partial('admin/hosting/_form', [
        '_csrf'          => $_csrf,
        'action'         => '/admin/hosting',
        'submitLabel'    => '建立主機資產',
        'customers'      => $customers,
        'websitesByCust' => $websitesByCust,
        'typeLabels'     => $typeLabels,
        'statusLabels'   => $statusLabels,
        'hosting'        => [],
        'presetCustomer' => $presetCustomer,
    ]); ?>
</div>
