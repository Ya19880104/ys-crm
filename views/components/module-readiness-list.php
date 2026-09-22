<?php
use function YangSheep\CRM\Core\e;

// Presentation of an already-authorized read model. Never query settings or module services here.
$presenceLabels = ['present'=>'來源齊全', 'partial'=>'來源部分缺失', 'absent'=>'來源缺失', 'planned'=>'規劃中'];
$reasonLabels = [
    'legacy_unmanaged'=>'已有功能來源，尚未提供模組啟閉。',
    'source_incomplete'=>'指定來源不完整，請由維護者檢查安裝內容。',
    'planned_not_implemented'=>'尚未實作；選擇方案不會自動建立此功能。',
];
?>
<style>
    .ys-module-list { list-style: none; padding: 0; margin: 0; }
    .ys-module-row { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem;
        padding: 1.25rem; margin-bottom: .75rem; border: 1px solid var(--ys-border);
        border-radius: .75rem; background: var(--ys-surface); color: var(--ys-heading); }
    .ys-module-row > * { min-width: 0; overflow-wrap: anywhere; }
    .ys-module-row h3, .ys-module-row p, .ys-module-row dl { margin: 0; }
    .ys-module-row h3 { font-size: 1rem; font-weight: 600; }
    .ys-module-row p, .ys-module-row dl { font-size: .875rem; line-height: 1.6; }
    .ys-module-row dt { font-weight: 600; }
    .ys-module-row dd { margin: .25rem 0 0; }
    .ys-module-id { display: block; margin-top: .25rem; font-size: .8125rem; overflow-wrap: anywhere; }
    @media (min-width: 1100px) {
        .ys-module-row { grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr) minmax(0, 1.6fr); }
    }
</style>
<?php if ($modules === []): ?>
    <p>目前沒有可顯示的盤點資料，請重新整理或聯絡維護者。</p>
<?php else: ?>
<ul class="ys-module-list" aria-label="功能模組盤點清冊">
    <?php foreach ($modules as $module): ?>
    <li class="ys-module-row" data-module-id="<?= e($module['id']) ?>">
        <div>
            <h3><?= e($module['label']) ?></h3>
            <code class="ys-module-id"><?= e($module['id']) ?></code>
            <p><?= $module['required'] ? '必備模組' : '選用模組' ?> · 僅供盤點</p>
        </div>
        <dl>
            <dt>來源狀態</dt>
            <dd><?= e($presenceLabels[$module['source_presence']] ?? '狀態未知') ?></dd>
            <dt>依賴</dt>
            <dd><?= e($module['depends_on'] === [] ? '無' : implode('、', $module['depends_on'])) ?></dd>
        </dl>
        <div>
            <p><?= e($reasonLabels[$module['reason_code']] ?? '狀態未知；請由維護者確認，不能據此啟用。') ?></p>
            <p>生命週期尚未就緒 · 本頁不可變更</p>
        </div>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
