<?php
/**
 * 客戶主機資產表單（create / edit 共用）。
 *
 * 關聯網站下拉依「選定客戶」於前端動態過濾：後端傳入 websitesByCust 映射
 * （customer_id => [{id,label}]），Alpine 依目前 customer_id 顯示對應網站；
 * 切換客戶時若原關聯網站不屬於新客戶則自動清空。後端 Service 層亦會再次驗證一致性。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var string $action          表單送出目標 URL
 * @var string $submitLabel     送出按鈕文字
 * @var array  $customers       [{id, display_name}]
 * @var array  $websitesByCust  customer_id => [{id, label}]
 * @var array  $typeLabels      type => 中文
 * @var array  $statusLabels    status => 中文
 * @var array  $hosting         編輯時的資料（新增時為空陣列）
 * @var int    $presetCustomer  新增時預選客戶 id（0 = 不預選）
 */

use function YangSheep\CRM\Core\e;

$hosting        = $hosting ?? [];
$presetCustomer = (int) ($presetCustomer ?? 0);
$selCustomer    = (int) ($hosting['customer_id'] ?? $presetCustomer);
$selType        = (string) ($hosting['type'] ?? 'shared');
$selStatus      = (string) ($hosting['status'] ?? 'active');
$selWebsite     = (int) ($hosting['related_website_id'] ?? 0);

$dv = static fn (string $k): string => substr((string) ($hosting[$k] ?? ''), 0, 10);

// 供 Alpine 使用的 JSON（鍵為字串化 customer_id）。json_encode 已轉義，置於 single-quote 屬性安全。
$websitesJson = json_encode(
    $websitesByCust ?? [],
    JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP
);
?>

<style>[x-cloak]{display:none!important;}</style>

<form method="POST" action="<?= e($action) ?>" class="space-y-6"
      x-data="{
          customerId: '<?= e((string) $selCustomer) ?>',
          relatedWebsiteId: '<?= e((string) ($selWebsite ?: '')) ?>',
          websitesByCust: <?= $websitesJson ?: '{}' ?>,
          get currentWebsites() {
              return this.websitesByCust[this.customerId] || [];
          },
          onCustomerChange() {
              // 若目前關聯網站不在新客戶的清單內，清空之
              var ok = this.currentWebsites.some(function (w) { return String(w.id) === String(this.relatedWebsiteId); }, this);
              if (!ok) { this.relatedWebsiteId = ''; }
          }
      }">
    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">

    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
        <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-5">主機資料</h3>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <!-- 客戶 -->
            <div>
                <label for="customer_id" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">客戶 <span class="text-red-600 dark:text-red-400">*</span></label>
                <select id="customer_id" name="customer_id" required x-model="customerId" @change="onCustomerChange()"
                        class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <option value="">請選擇客戶</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= e((string) $c['id']) ?>" <?= $selCustomer === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= e($c['display_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 類型 -->
            <div>
                <label for="type" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">主機類型 <span class="text-red-600 dark:text-red-400">*</span></label>
                <select id="type" name="type" required
                        class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <?php foreach ($typeLabels as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= $selType === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- IP -->
            <div>
                <label for="ip_address" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">IP 位址</label>
                <input type="text" id="ip_address" name="ip_address" maxlength="45"
                       value="<?= e($hosting['ip_address'] ?? '') ?>"
                       class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm font-mono"
                       placeholder="例如 203.0.113.10">
            </div>

            <!-- 帳號信箱 -->
            <div>
                <label for="account_email" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">帳號信箱</label>
                <input type="email" id="account_email" name="account_email" maxlength="255"
                       value="<?= e($hosting['account_email'] ?? '') ?>"
                       class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                       placeholder="主機管理帳號 Email">
            </div>

            <!-- 租用起 -->
            <div>
                <label for="start_date" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">租用開始日</label>
                <input type="date" id="start_date" name="start_date"
                       value="<?= e($dv('start_date')) ?>"
                       class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
            </div>

            <!-- 租用訖 -->
            <div>
                <label for="end_date" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">租用結束日</label>
                <input type="date" id="end_date" name="end_date"
                       value="<?= e($dv('end_date')) ?>"
                       class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
            </div>

            <!-- 關聯網站（依客戶過濾） -->
            <div>
                <label for="related_website_id" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">關聯網站</label>
                <select id="related_website_id" name="related_website_id" x-model="relatedWebsiteId"
                        class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <option value="">不關聯</option>
                    <template x-for="w in currentWebsites" :key="w.id">
                        <option :value="w.id" x-text="w.label"></option>
                    </template>
                </select>
                <p class="text-xs text-slate-400 mt-1" x-show="currentWebsites.length === 0" x-cloak>此客戶目前沒有網站資產可關聯。</p>
            </div>

            <!-- 狀態 -->
            <div>
                <label for="status" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">狀態 <span class="text-red-600 dark:text-red-400">*</span></label>
                <select id="status" name="status" required
                        class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <?php foreach ($statusLabels as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= $selStatus === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-slate-400 mt-1">租用到期後系統會於列表標示逾期；狀態翻轉將由到期排程處理。</p>
            </div>

            <!-- 規格 -->
            <div class="md:col-span-2">
                <label for="spec" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">規格</label>
                <textarea id="spec" name="spec" rows="3"
                          class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                          placeholder="例如：CPU 2 核 / RAM 4GB / SSD 80GB / 流量 1TB"><?= e($hosting['spec'] ?? '') ?></textarea>
            </div>

            <!-- 備註 -->
            <div class="md:col-span-2">
                <label for="notes" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">備註</label>
                <textarea id="notes" name="notes" rows="3"
                          class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                          placeholder="內部備註"><?= e($hosting['notes'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit"
                class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
            <?= e($submitLabel ?? '儲存') ?>
        </button>
        <a href="/admin/hosting"
           class="px-6 py-2.5 rounded-lg text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition">
            取消
        </a>
    </div>
</form>
