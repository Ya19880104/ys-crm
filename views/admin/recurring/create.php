<?php
/**
 * 建立週期排程（對應架構設計 §7.8）。選一張既有報價作範本，設定週期/提前/扣款/付款方式。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $quotes      候選報價 [{id, quote_number, title, total, currency, customer_name}]
 * @var array  $unitLabels  day/month/year => 中文
 * @var array  $modeLabels  payment_mode => 中文
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

$inputClass = 'w-full rounded-lg border border-slate-300 dark:border-surface-border bg-white dark:bg-navy-topbar text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 shadow-sm focus:border-brand-light focus:ring-brand-light text-sm px-4 py-2.5';
$labelClass = 'block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5';
?>

<div class="max-w-2xl">
    <a href="/admin/recurring" class="inline-flex items-center gap-1 text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 mb-4">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        返回週期帳務
    </a>

    <form method="POST" action="/admin/recurring" class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6 space-y-5">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">

        <div>
            <label for="quote_id" class="<?= $labelClass ?>">來源報價單 <span class="text-red-600 dark:text-red-400">*</span></label>
            <select id="quote_id" name="quote_id" required class="<?= $inputClass ?>">
                <option value="">— 請選擇 —</option>
                <?php foreach ($quotes as $q): ?>
                <option value="<?= e((string) $q['id']) ?>">
                    <?= e($q['quote_number']) ?> · <?= e(mb_substr((string) $q['title'], 0, 30)) ?>
                    <?= ($q['customer_name'] ?? '') !== '' ? '（' . e($q['customer_name']) . '）' : '' ?>
                    · <?= e(($q['currency'] ?: 'TWD') . ' ' . number_format((float) $q['total'], 0)) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">每期將複製此報價的明細產生新帳單（金額由系統依明細重算）。</p>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="interval_value" class="<?= $labelClass ?>">週期數值 <span class="text-red-600 dark:text-red-400">*</span></label>
                <input type="number" id="interval_value" name="interval_value" value="1" min="1" required class="<?= $inputClass ?>">
            </div>
            <div>
                <label for="interval_unit" class="<?= $labelClass ?>">週期單位 <span class="text-red-600 dark:text-red-400">*</span></label>
                <select id="interval_unit" name="interval_unit" class="<?= $inputClass ?>">
                    <?php foreach ($unitLabels as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= $code === 'month' ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div>
            <label for="first_run_at" class="<?= $labelClass ?>">首次產生日期</label>
            <input type="date" id="first_run_at" name="first_run_at" value="<?= e(date('Y-m-d')) ?>" class="<?= $inputClass ?>">
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">留空則為今日。排程會在此日期前 N 天（提前產生天數）自動產生帳單。</p>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="advance_generate_days" class="<?= $labelClass ?>">提前產生天數</label>
                <input type="number" id="advance_generate_days" name="advance_generate_days" min="0" placeholder="留空＝用系統預設" class="<?= $inputClass ?>">
            </div>
            <div>
                <label for="auto_charge_after_days" class="<?= $labelClass ?>">產生後自動扣款延遲天數</label>
                <input type="number" id="auto_charge_after_days" name="auto_charge_after_days" min="0" placeholder="留空＝用系統預設" class="<?= $inputClass ?>">
            </div>
        </div>

        <div>
            <label for="payment_mode" class="<?= $labelClass ?>">付款方式</label>
            <select id="payment_mode" name="payment_mode" class="<?= $inputClass ?>">
                <?php foreach ($modeLabels as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= $code === 'manual_atm' ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">「綁卡自動扣款」需客戶於專區綁卡（P4-2 上線）；目前選此將以「待人工」提醒處理，不會自動扣款。</p>
        </div>

        <div class="flex justify-end gap-3 pt-2">
            <a href="/admin/recurring" class="px-5 py-2.5 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition">取消</a>
            <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg font-medium text-sm hover:bg-blue-700 transition shadow-sm">建立排程</button>
        </div>
    </form>
</div>
