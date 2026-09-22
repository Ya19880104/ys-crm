<?php
/**
 * 編輯客戶頁（僅基本資料；聯絡人於客戶內頁管理）。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $_flash
 * @var array  $customer
 * @var array  $assignees
 * @var array  $statusLabels
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;
?>

<!-- Alpine x-cloak FOUC 防護 -->
<style>[x-cloak]{display:none!important;}</style>

<div class="max-w-3xl">
    <!-- 返回連結 -->
    <div class="mb-6">
        <a href="/admin/customers/<?= e((string) $customer['id']) ?>"
           class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            返回客戶內頁
        </a>
    </div>

    <div class="mb-5">
        <h3 class="text-lg font-semibold text-slate-800 dark:text-slate-100">編輯客戶：<?= e($customer['display_name'] ?? '') ?></h3>
        <p class="text-sm text-slate-400 mt-1">建立於 <?= e($customer['created_at'] ?? '') ?></p>
    </div>

    <?php $view->partial('admin/customers/_form', [
        '_csrf'        => $_csrf,
        'action'       => '/admin/customers/' . (int) $customer['id'],
        'submitLabel'  => '儲存變更',
        'assignees'    => $assignees,
        'statusLabels' => $statusLabels,
        'customer'     => $customer,
        'showContacts' => false,
    ]); ?>
</div>
