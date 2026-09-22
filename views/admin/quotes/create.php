<?php
/**
 * 新增報價單頁。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $customers
 * @var array  $visLabels
 * @var int    $presetCustomer
 * @var float  $defaultTaxRate
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;
?>

<div class="mb-6">
    <a href="/admin/quotes" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 flex items-center gap-1">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
        </svg>
        返回報價單列表
    </a>
</div>

<?php $view->partial('admin/quotes/_form', [
    '_csrf'          => $_csrf,
    'action'         => '/admin/quotes',
    'submitLabel'    => '儲存草稿',
    'isEdit'         => false,
    'quote'          => [],
    'items'          => [],
    'customers'      => $customers,
    'visLabels'      => $visLabels,
    'presetCustomer' => $presetCustomer,
    'defaultTaxRate' => $defaultTaxRate,
    'shareView'      => $shareView ?? [],
    'shareInput'     => $shareInput ?? null,
    'shareError'     => $shareError ?? null,
]); ?>
