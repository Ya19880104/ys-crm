<?php
/**
 * 客戶網站資產表單（create / edit 共用）。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var string $action          表單送出目標 URL
 * @var string $submitLabel     送出按鈕文字
 * @var array  $customers       [{id, display_name}]
 * @var array  $caseTypeLabels  case_type => 中文
 * @var array  $statusLabels    status => 中文
 * @var array  $website         編輯時的資料（新增時為空陣列）
 * @var int    $presetCustomer  新增時預選客戶 id（0 = 不預選）
 */

use function YangSheep\CRM\Core\e;

$website        = $website ?? [];
$presetCustomer = (int) ($presetCustomer ?? 0);
$selCustomer    = (int) ($website['customer_id'] ?? $presetCustomer);
$selCaseType    = (string) ($website['case_type'] ?? 'build');
$selStatus      = (string) ($website['status'] ?? 'active');

$dv = static fn (string $k): string => substr((string) ($website[$k] ?? ''), 0, 10);
?>

<style>[x-cloak]{display:none!important;}</style>

<form method="POST" action="<?= e($action) ?>" class="space-y-6"
      x-data="{ caseType: '<?= e($selCaseType) ?>' }">
    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">

    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
        <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-5">網站資料</h3>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <!-- 客戶 -->
            <div>
                <label for="customer_id" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">客戶 <span class="text-red-600 dark:text-red-400">*</span></label>
                <select id="customer_id" name="customer_id" required
                        class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <option value="">請選擇客戶</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= e((string) $c['id']) ?>" <?= $selCustomer === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= e($c['display_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 案件類型 -->
            <div>
                <label for="case_type" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">案件類型 <span class="text-red-600 dark:text-red-400">*</span></label>
                <select id="case_type" name="case_type" required x-model="caseType"
                        class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <?php foreach ($caseTypeLabels as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= $selCaseType === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 網址 -->
            <div class="md:col-span-2">
                <label for="url" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">網址</label>
                <input type="text" id="url" name="url" maxlength="255"
                       value="<?= e($website['url'] ?? '') ?>"
                       class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                       placeholder="https://example.com">
            </div>

            <!-- 合約起 -->
            <div>
                <label for="contract_start" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">合約開始日</label>
                <input type="date" id="contract_start" name="contract_start"
                       value="<?= e($dv('contract_start')) ?>"
                       class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
            </div>

            <!-- 合約訖 -->
            <div>
                <label for="contract_end" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">合約結束日</label>
                <input type="date" id="contract_end" name="contract_end"
                       value="<?= e($dv('contract_end')) ?>"
                       class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
            </div>

            <!-- 維護起訖（僅「含維護」類型顯示） -->
            <template x-if="caseType === 'maintenance_hosting' || caseType === 'maintenance'">
                <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-5" x-cloak>
                    <div>
                        <label for="maintenance_start" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">維護開始日</label>
                        <input type="date" id="maintenance_start" name="maintenance_start"
                               value="<?= e($dv('maintenance_start')) ?>"
                               class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    </div>
                    <div>
                        <label for="maintenance_end" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">維護結束日</label>
                        <input type="date" id="maintenance_end" name="maintenance_end"
                               value="<?= e($dv('maintenance_end')) ?>"
                               class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    </div>
                </div>
            </template>

            <!-- 狀態 -->
            <div>
                <label for="status" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">狀態 <span class="text-red-600 dark:text-red-400">*</span></label>
                <select id="status" name="status" required
                        class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <?php foreach ($statusLabels as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= $selStatus === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-slate-400 mt-1">合約到期後系統會於列表標示逾期；狀態翻轉將由到期排程處理。</p>
            </div>

            <!-- 備註 -->
            <div class="md:col-span-2">
                <label for="notes" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">備註</label>
                <textarea id="notes" name="notes" rows="3"
                          class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                          placeholder="內部備註"><?= e($website['notes'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit"
                class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
            <?= e($submitLabel ?? '儲存') ?>
        </button>
        <a href="/admin/websites"
           class="px-6 py-2.5 rounded-lg text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition">
            取消
        </a>
    </div>
</form>
