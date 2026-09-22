<?php
use function YangSheep\CRM\Core\e;
/** @var array $summary  @var string $provenance */
?>
<section aria-label="發票買受人資料" class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-5">
    <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100 mb-1">發票買受人資料</h3>
    <p class="text-xs text-slate-600 dark:text-slate-300 mb-3"><?= e($provenance) ?></p>
    <p class="text-sm font-medium text-slate-800 dark:text-slate-100 mb-3"><?= e($summary['label']) ?></p>
    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
        <?php foreach (['name'=>'名稱／公司抬頭', 'identifier'=>'統一編號', 'email'=>'Email', 'phone'=>'手機',
            'donation'=>'捐贈愛心碼', 'carrier'=>'載具類型', 'carrier_id1'=>'載具明碼', 'carrier_id2'=>'載具隱碼'] as $key=>$label): ?>
        <?php if (in_array($key, ['donation','carrier','carrier_id1','carrier_id2'], true) && $summary[$key] === '') { continue; } ?>
        <?php if (str_starts_with($key, 'carrier') && in_array($summary['type'], ['b2b','donate'], true)) { continue; } ?>
        <div class="min-w-0">
            <dt class="text-xs text-slate-600 dark:text-slate-300"><?= e($label) ?></dt>
            <dd class="text-sm text-slate-800 dark:text-slate-100 break-words"><?= e($summary[$key] !== '' ? $summary[$key] : '—') ?></dd>
        </div>
        <?php endforeach; ?>
    </dl>
</section>
