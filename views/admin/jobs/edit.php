<?php
/**
 * 編輯工作頁。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $job
 * @var array  $columns
 * @var array  $customers
 * @var array  $assignees
 * @var array  $priorityLabels
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

$jid = (int) $job['id'];
?>

<div class="max-w-3xl">
    <div class="mb-6">
        <a href="/admin/jobs/<?= $jid ?>" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            返回工作詳情
        </a>
    </div>

    <?php $view->partial('admin/jobs/_form', [
        '_csrf'          => $_csrf,
        'action'         => '/admin/jobs/' . $jid,
        'submitLabel'    => '儲存變更',
        'columns'        => $columns,
        'customers'      => $customers,
        'assignees'      => $assignees,
        'priorityLabels' => $priorityLabels,
        'job'            => $job,
        'presetColumn'   => 0,
        'presetCustomer' => 0,
    ]); ?>
</div>
