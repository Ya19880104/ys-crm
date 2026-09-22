<?php
/**
 * 新增客戶頁。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $_flash
 * @var array  $assignees
 * @var array  $statusLabels
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;
?>

<!-- Alpine x-cloak FOUC 防護（避免條件欄位於 Alpine 初始化前閃現） -->
<style>[x-cloak]{display:none!important;}</style>

<div class="max-w-3xl">
    <!-- 返回連結 -->
    <div class="mb-6">
        <a href="/admin/customers" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            返回客戶列表
        </a>
    </div>

    <?php $view->partial('admin/customers/_form', [
        '_csrf'        => $_csrf,
        'action'       => '/admin/customers',
        'submitLabel'  => '建立客戶',
        'assignees'    => $assignees,
        'statusLabels' => $statusLabels,
        'customer'     => [],
        'showContacts' => true,
    ]); ?>
</div>
