<?php
/**
 * 資產模組頂部互切 tab（網站 ⇄ 主機）。
 * 網站與主機為分別頁面，以此列互相切換（對應架構設計 §7.7「兩個是分別頁面或 TAB」）。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $active 當前頁：'websites' | 'hosting'
 */

use function YangSheep\CRM\Core\e;

$active = $active ?? 'websites';

$tabs = [
    'websites' => ['label' => '客戶網站', 'url' => '/admin/websites'],
    'hosting'  => ['label' => '客戶主機', 'url' => '/admin/hosting'],
];
?>
<div class="mb-5 border-b border-slate-200 dark:border-surface-border">
    <nav class="flex gap-1 -mb-px" aria-label="資產類別切換">
        <?php foreach ($tabs as $key => $tab): ?>
        <a href="<?= e($tab['url']) ?>"
           class="px-5 py-3 text-sm font-medium border-b-2 transition
                  <?= $active === $key
                        ? 'border-blue-600 text-blue-600 dark:text-blue-400'
                        : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:border-slate-300 dark:hover:border-surface-border' ?>">
            <?= e($tab['label']) ?>
        </a>
        <?php endforeach; ?>
    </nav>
</div>
