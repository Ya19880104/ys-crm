<?php
/**
 * 編輯報價單頁。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $quote
 * @var array  $items
 * @var array  $customers
 * @var array  $visLabels
 * @var float  $defaultTaxRate
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;
?>

<div class="mb-6">
    <a href="/admin/quotes/<?= (int) $quote['id'] ?>" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 flex items-center gap-1">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
        </svg>
        返回報價單檢視
    </a>
</div>

<div class="mb-5">
    <h3 class="text-lg font-semibold text-slate-800 dark:text-slate-100">
        編輯報價單：<span class="font-mono text-base"><?= e($quote['quote_number']) ?></span>
    </h3>
    <p class="text-sm text-slate-400 mt-1">建立於 <?= e(substr((string) ($quote['created_at'] ?? ''), 0, 16)) ?></p>
</div>

<?php $view->partial('admin/quotes/_form', [
    '_csrf'          => $_csrf,
    'action'         => '/admin/quotes/' . (int) $quote['id'],
    'submitLabel'    => '儲存變更',
    'isEdit'         => true,
    'quote'          => $quote,
    'items'          => $items,
    'customers'      => $customers,
    'visLabels'      => $visLabels,
    'presetCustomer' => 0,
    'defaultTaxRate' => $defaultTaxRate,
    'shareView'      => $shareView ?? [],
    'shareInput'     => $shareInput ?? null,
    'shareError'     => $shareError ?? null,
]); ?>
