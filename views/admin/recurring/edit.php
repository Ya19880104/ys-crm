<?php
/**
 * 編輯週期排程（對應架構設計 §7.8）。可改週期/下次產生日/提前/扣款/付款方式/啟停。
 * 來源報價不可改（如需換來源請新建排程）。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $schedule    含 quote_number / quote_title / customer_name
 * @var array  $unitLabels
 * @var array  $modeLabels
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

$inputClass = 'w-full rounded-lg border border-slate-300 dark:border-surface-border bg-white dark:bg-navy-topbar text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 shadow-sm focus:border-brand-light focus:ring-brand-light text-sm px-4 py-2.5';
$labelClass = 'block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5';

$sid    = (int) $schedule['id'];
$active = (int) ($schedule['is_active'] ?? 0) === 1;
$next   = substr((string) ($schedule['next_run_at'] ?? ''), 0, 10);
?>

<div class="max-w-2xl">
    <a href="/admin/recurring/<?= $sid ?>" class="inline-flex items-center gap-1 text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 mb-4">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        返回排程詳情
    </a>

    <form method="POST" action="/admin/recurring/<?= $sid ?>" class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6 space-y-5">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">

        <div class="bg-slate-50 dark:bg-white/5 rounded-lg px-4 py-3 text-sm">
            <span class="text-slate-500 dark:text-slate-400">來源報價：</span>
            <span class="font-mono text-blue-600 dark:text-blue-400"><?= e((string) ($schedule['quote_number'] ?? '（已刪除）')) ?></span>
            <?php if (($schedule['customer_name'] ?? '') !== ''): ?>
            <span class="text-slate-500 dark:text-slate-400 ml-2"><?= e($schedule['customer_name']) ?></span>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="interval_value" class="<?= $labelClass ?>">週期數值</label>
                <input type="number" id="interval_value" name="interval_value" value="<?= e((string) $schedule['interval_value']) ?>" min="1" class="<?= $inputClass ?>">
            </div>
            <div>
                <label for="interval_unit" class="<?= $labelClass ?>">週期單位</label>
                <select id="interval_unit" name="interval_unit" class="<?= $inputClass ?>">
                    <?php foreach ($unitLabels as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= (string) $schedule['interval_unit'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div>
            <label for="next_run_at" class="<?= $labelClass ?>">下次產生日期</label>
            <input type="date" id="next_run_at" name="next_run_at" value="<?= e($next) ?>" class="<?= $inputClass ?>">
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="advance_generate_days" class="<?= $labelClass ?>">提前產生天數</label>
                <input type="number" id="advance_generate_days" name="advance_generate_days" min="0"
                       value="<?= $schedule['advance_generate_days'] !== null ? e((string) $schedule['advance_generate_days']) : '' ?>"
                       placeholder="留空＝用系統預設" class="<?= $inputClass ?>">
            </div>
            <div>
                <label for="auto_charge_after_days" class="<?= $labelClass ?>">自動扣款延遲天數</label>
                <input type="number" id="auto_charge_after_days" name="auto_charge_after_days" min="0"
                       value="<?= $schedule['auto_charge_after_days'] !== null ? e((string) $schedule['auto_charge_after_days']) : '' ?>"
                       placeholder="留空＝用系統預設" class="<?= $inputClass ?>">
            </div>
        </div>

        <div>
            <label for="payment_mode" class="<?= $labelClass ?>">付款方式</label>
            <select id="payment_mode" name="payment_mode" class="<?= $inputClass ?>">
                <?php foreach ($modeLabels as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= (string) $schedule['payment_mode'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="is_active" value="1" <?= $active ? 'checked' : '' ?>
                       class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">啟用此排程（停用後 cron 不會再產生帳單）</span>
            </label>
        </div>

        <div class="flex justify-end gap-3 pt-2">
            <a href="/admin/recurring/<?= $sid ?>" class="px-5 py-2.5 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition">取消</a>
            <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg font-medium text-sm hover:bg-blue-700 transition shadow-sm">儲存變更</button>
        </div>
    </form>

    <!-- 刪除（獨立 form）-->
    <form method="POST" action="/admin/recurring/<?= $sid ?>/delete" class="mt-4"
          onsubmit="return confirm('確定刪除此週期排程？已產生的帳單將保留，但不再自動產生新帳單。')">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
        <button type="submit" class="text-red-600 dark:text-red-400 hover:text-red-800 text-sm font-medium">刪除此排程</button>
    </form>
</div>
