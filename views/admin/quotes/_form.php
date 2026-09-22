<?php
/**
 * 報價單編輯器（create / edit 共用）。
 *
 * 結構：報價抬頭（標題 / 客戶 / 報價日期 / 有效期限）
 *      + 明細表（Alpine repeater，可動態增列；前端即時算小計 / 稅 / 總計）
 *      + 發佈設定卡（可見性 radio + 密碼欄 + 付款 toggle）
 *      + 動作（儲存草稿 / 預覽 / 送出）。
 *
 * 重要：前端即時計算僅供「顯示預覽」；實際金額一律由後端 QuoteService 重算（不信前端傳值）。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var string $action          表單送出目標 URL
 * @var string $submitLabel     主送出按鈕文字
 * @var bool   $isEdit          是否為編輯（影響密碼欄提示、預覽連結）
 * @var array  $quote           編輯時的報價主檔（新增時為空陣列）
 * @var array  $items           編輯時的明細（新增時為空陣列）
 * @var array  $customers       [{id, display_name}]
 * @var array  $visLabels       visibility => 中文
 * @var int    $presetCustomer  新增時預選客戶 id（0 = 不預選）
 * @var float  $defaultTaxRate  預設稅率
 * @var array  $shareView       已儲存的分享狀態（QuoteService::shareView；新增時為 not_shared）
 * @var ?array $shareInput      分享設定驗證失敗重新顯示時，使用者剛才送出的值
 * @var ?array $shareError      {field, message}：分享設定驗證錯誤
 */

use function YangSheep\CRM\Core\e;

$quote          = $quote ?? [];
$items          = $items ?? [];
$isEdit         = $isEdit ?? false;
$presetCustomer = (int) ($presetCustomer ?? 0);
$defaultTaxRate = (float) ($defaultTaxRate ?? 5);

$selCustomer = (int) ($quote['customer_id'] ?? $presetCustomer);
$selVis      = (string) ($quote['visibility'] ?? 'private');
$taxRate     = isset($quote['tax_rate']) ? (float) $quote['tax_rate'] : $defaultTaxRate;
$validUntil  = substr((string) ($quote['valid_until'] ?? ''), 0, 10);
$paymentOn   = (int) ($quote['payment_enabled'] ?? 0) === 1;
$publicToken = (string) ($quote['access_token'] ?? '');

// 明細初始 JSON（供 Alpine 還原；新增時預設一空列）
$itemsJson = [];
foreach ($items as $it) {
    $itemsJson[] = [
        'name'        => (string) ($it['name'] ?? ''),
        'description' => (string) ($it['description'] ?? ''),
        'qty'         => (float) ($it['qty'] ?? 1),
        'unit'        => (string) ($it['unit'] ?? ''),
        'unit_price'  => (float) ($it['unit_price'] ?? 0),
    ];
}
if ($itemsJson === []) {
    $itemsJson[] = ['name' => '', 'description' => '', 'qty' => 1, 'unit' => '式', 'unit_price' => 0];
}
$itemsJsonStr = json_encode($itemsJson, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
?>

<style>[x-cloak]{display:none!important;}</style>

<form method="POST" action="<?= e($action) ?>" class="space-y-6"
      x-data="quoteEditor(<?= e($itemsJsonStr) ?>, <?= e((string) $taxRate) ?>, '<?= e($selVis) ?>')">
    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
    <!-- 實際送出的動作（save / send / preview 由按鈕設定） -->
    <input type="hidden" name="action" x-model="action">

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

        <!-- 左：抬頭 + 明細（2 欄寬） -->
        <div class="xl:col-span-2 space-y-6">

            <!-- 報價抬頭 -->
            <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
                <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-5">報價抬頭</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <!-- 標題 -->
                    <div class="md:col-span-2">
                        <label for="title" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">報價標題 <span class="text-red-600 dark:text-red-400">*</span></label>
                        <input type="text" id="title" name="title" required maxlength="255"
                               value="<?= e($quote['title'] ?? '') ?>"
                               class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                               placeholder="例如：官方網站設計與建置報價">
                    </div>

                    <!-- 客戶 + 帶入資料 -->
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label for="customer_id" class="block text-sm font-medium text-slate-700 dark:text-slate-300">客戶</label>
                            <a href="/admin/customers/create" target="_blank" rel="noopener"
                               class="text-xs text-blue-600 dark:text-blue-400 hover:underline">＋ 快速新增客戶</a>
                        </div>
                        <select id="customer_id" name="customer_id" x-ref="customerSelect"
                                class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                            <option value="">（未指定客戶）</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?= e((string) $c['id']) ?>" <?= $selCustomer === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= e($c['display_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" @click="fillFromCustomer()"
                                class="mt-2 text-xs text-blue-600 dark:text-blue-400 hover:underline">
                            以所選客戶名稱帶入標題
                        </button>
                    </div>

                    <!-- 有效期限 -->
                    <div>
                        <label for="valid_until" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">有效期限</label>
                        <input type="date" id="valid_until" name="valid_until"
                               value="<?= e($validUntil) ?>"
                               class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                        <p class="text-xs text-slate-400 mt-1">逾期後將於列表與公開頁標示「已逾期」。</p>
                    </div>
                </div>
            </div>

            <!-- 明細表 -->
            <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100">報價明細</h3>
                    <button type="button" @click="addItem()"
                            class="text-sm text-blue-600 dark:text-blue-400 hover:underline flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        新增明細列
                    </button>
                </div>

                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-surface-border">
                            <th class="text-left font-medium pb-2 pr-2 min-w-[200px]">項目 / 說明</th>
                            <th class="text-right font-medium pb-2 px-2 w-24">數量</th>
                            <th class="text-left font-medium pb-2 px-2 w-20">單位</th>
                            <th class="text-right font-medium pb-2 px-2 w-32">單價</th>
                            <th class="text-right font-medium pb-2 px-2 w-32">金額</th>
                            <th class="pb-2 w-10"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(item, i) in items" :key="i">
                            <tr class="border-b border-slate-100 dark:border-surface-border align-top">
                                <!-- 項目 + 說明 -->
                                <td class="py-2 pr-2">
                                    <input type="text" x-model="item.name" :name="`items[${i}][name]`" maxlength="255"
                                           placeholder="項目名稱"
                                           class="w-full px-2 py-1.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 mb-1">
                                    <input type="text" x-model="item.description" :name="`items[${i}][description]`"
                                           placeholder="說明（選填）"
                                           class="w-full px-2 py-1 border border-slate-200 dark:border-surface-border dark:bg-surface-dark dark:text-slate-400 rounded text-xs focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                </td>
                                <!-- 數量 -->
                                <td class="py-2 px-2">
                                    <input type="number" step="0.01" min="0" x-model.number="item.qty" :name="`items[${i}][qty]`"
                                           @input="recalc()"
                                           class="w-full px-2 py-1.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded text-sm text-right focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                </td>
                                <!-- 單位 -->
                                <td class="py-2 px-2">
                                    <input type="text" x-model="item.unit" :name="`items[${i}][unit]`" maxlength="32"
                                           placeholder="式"
                                           class="w-full px-2 py-1.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                </td>
                                <!-- 單價 -->
                                <td class="py-2 px-2">
                                    <input type="number" step="0.01" min="0" x-model.number="item.unit_price" :name="`items[${i}][unit_price]`"
                                           @input="recalc()"
                                           class="w-full px-2 py-1.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded text-sm text-right focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                </td>
                                <!-- 金額（唯讀，前端算） -->
                                <td class="py-2 px-2 text-right text-slate-800 dark:text-slate-100 font-medium tabular-nums"
                                    x-text="money(rowAmount(item))"></td>
                                <!-- 刪除 -->
                                <td class="py-2 text-center">
                                    <button type="button" @click="removeItem(i)"
                                            class="text-slate-400 hover:text-red-600 dark:hover:text-red-400 transition" title="刪除此列">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                    </button>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="items.length === 0">
                            <td colspan="6" class="py-6 text-center text-slate-400 text-sm">尚無明細，請點「新增明細列」。</td>
                        </tr>
                    </tbody>
                </table>
                </div>

                <!-- 合計區 -->
                <div class="mt-5 flex justify-end">
                    <div class="w-full sm:w-72 space-y-2 text-sm">
                        <div class="flex items-center justify-between text-slate-600 dark:text-slate-300">
                            <span>小計</span>
                            <span class="tabular-nums font-medium" x-text="money(subtotal)"></span>
                        </div>
                        <div class="flex items-center justify-between text-slate-600 dark:text-slate-300">
                            <label class="flex items-center gap-2">
                                稅率
                                <input type="number" step="0.01" min="0" max="100" name="tax_rate" x-model.number="taxRate"
                                       @input="recalc()"
                                       class="w-16 px-2 py-1 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded text-sm text-right focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                %
                            </label>
                            <span class="tabular-nums" x-text="money(tax)"></span>
                        </div>
                        <div class="flex items-center justify-between pt-2 border-t border-slate-200 dark:border-surface-border text-base font-bold text-slate-900 dark:text-slate-100">
                            <span>總計</span>
                            <span class="tabular-nums" x-text="'TWD ' + money(total)"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 條款 / 備註 -->
            <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
                <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-5">條款與備註</h3>
                <div class="space-y-5">
                    <div>
                        <label for="terms" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">報價條款</label>
                        <textarea id="terms" name="terms" rows="4"
                                  class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                                  placeholder="付款方式、交付時程、保固範圍等條款（顯示於公開報價頁）"><?= e($quote['terms'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <label for="notes" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">內部備註</label>
                        <textarea id="notes" name="notes" rows="2"
                                  class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                                  placeholder="僅後台可見，不顯示於公開頁"><?= e($quote['notes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- 右：發佈設定卡 -->
        <div class="space-y-6">
            <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6 xl:sticky xl:top-20">
                <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-5">發佈設定</h3>

                <!-- 可見性 radio -->
                <fieldset class="space-y-2 mb-5">
                    <legend class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">可見性</legend>
                    <?php
                    $visHelp = [
                        'private'       => '僅後台可見，不產生公開連結。',
                        'public'        => '任何取得連結者皆可檢視。',
                        'password'      => '需輸入密碼才能檢視。',
                        'customer_only' => '須以該客戶帳號登入（客戶專區建置中）。',
                    ];
                    foreach ($visLabels as $code => $label):
                    ?>
                    <label class="flex items-start gap-2 p-2.5 rounded-lg border cursor-pointer transition"
                           :class="visibility === '<?= e($code) ?>' ? 'border-blue-500 bg-blue-50/60 dark:bg-blue-500/10' : 'border-slate-200 dark:border-surface-border hover:bg-slate-50 dark:hover:bg-white/5'">
                        <input type="radio" name="visibility" value="<?= e($code) ?>" x-model="visibility"
                               <?= $selVis === $code ? 'checked' : '' ?>
                               class="mt-0.5 text-blue-600 focus:ring-blue-500">
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-slate-800 dark:text-slate-100"><?= e($label) ?></span>
                            <span class="block text-xs text-slate-400 mt-0.5"><?= e($visHelp[$code] ?? '') ?></span>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </fieldset>

                <?php $view->partial('admin/quotes/_share_fields', [
                    'shareView'  => $shareView ?? [],
                    'shareInput' => $shareInput ?? null,
                    'shareError' => $shareError ?? null,
                    'visibility' => $selVis,
                ]); ?>

                <!-- 密碼欄（visibility=password 才顯示） -->
                <div x-show="visibility === 'password'" x-cloak class="mb-5">
                    <label for="access_password" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        存取密碼 <?php if (!$isEdit): ?><span class="text-red-600 dark:text-red-400">*</span><?php endif; ?>
                    </label>
                    <input type="text" id="access_password" name="access_password" maxlength="100" autocomplete="off"
                           class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                           placeholder="<?= $isEdit ? '留空表示不變更現有密碼' : '設定客戶檢視所需密碼' ?>">
                    <p class="text-xs text-slate-400 mt-1"><?= $isEdit ? '編輯時留空＝沿用既有密碼。' : '客戶開啟公開連結時需輸入此密碼。' ?></p>
                </div>

                <!-- 線上付款 toggle（純存旗標；導引留 P4-5） -->
                <div class="mb-5 pt-5 border-t border-slate-200 dark:border-surface-border">
                    <label class="flex items-center justify-between cursor-pointer">
                        <span class="min-w-0 pr-3">
                            <span class="block text-sm font-medium text-slate-800 dark:text-slate-100">啟用線上付款</span>
                            <span class="block text-xs text-slate-400 mt-0.5">簽署後於公開頁顯示付款入口（依系統設定的金流商）。</span>
                        </span>
                        <span class="relative inline-flex flex-shrink-0">
                            <input type="checkbox" name="payment_enabled" value="1" class="sr-only peer" <?= $paymentOn ? 'checked' : '' ?> x-ref="paymentToggle">
                            <span class="w-11 h-6 bg-slate-200 dark:bg-white/10 rounded-full peer-checked:bg-blue-600 transition"></span>
                            <span class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full transition peer-checked:translate-x-5"></span>
                        </span>
                    </label>
                </div>

                <?php if ($isEdit && $publicToken !== '' && $selVis !== 'private'): ?>
                <!-- 編輯時提供預覽公開頁 -->
                <a href="/q/<?= e($publicToken) ?>" target="_blank" rel="noopener"
                   class="block w-full text-center px-4 py-2 mb-3 rounded-lg text-sm font-medium border border-slate-300 dark:border-surface-border text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/5 transition">
                    預覽公開頁 ↗
                </a>
                <?php endif; ?>

                <!-- 動作 -->
                <div class="space-y-2">
                    <button type="submit" @click="action = 'save'"
                            class="w-full bg-slate-800 dark:bg-white/10 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-slate-900 dark:hover:bg-white/20 transition">
                        <?= e($submitLabel ?? '儲存草稿') ?>
                    </button>
                    <button type="submit" @click="action = 'send'"
                            class="w-full bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
                        儲存並送出
                    </button>
                    <a href="/admin/quotes"
                       class="block w-full text-center px-4 py-2.5 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition">
                        取消
                    </a>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
function quoteEditor(initialItems, initialTaxRate, initialVisibility) {
    return {
        items: Array.isArray(initialItems) ? initialItems : [],
        taxRate: Number(initialTaxRate) || 0,
        visibility: initialVisibility || 'private',
        action: 'save',
        subtotal: 0,
        tax: 0,
        total: 0,

        init() {
            this.recalc();
        },
        rowAmount(item) {
            const qty = Number(item.qty) || 0;
            const price = Number(item.unit_price) || 0;
            return Math.round(qty * price * 100) / 100;
        },
        recalc() {
            let sub = 0;
            for (const it of this.items) {
                sub += this.rowAmount(it);
            }
            sub = Math.round(sub * 100) / 100;
            let rate = Number(this.taxRate) || 0;
            if (rate < 0) rate = 0;
            if (rate > 100) rate = 100;
            this.subtotal = sub;
            this.tax = Math.round(sub * rate / 100 * 100) / 100;
            this.total = Math.round((this.subtotal + this.tax) * 100) / 100;
        },
        addItem() {
            this.items.push({ name: '', description: '', qty: 1, unit: '式', unit_price: 0 });
            this.recalc();
        },
        removeItem(i) {
            this.items.splice(i, 1);
            this.recalc();
        },
        money(v) {
            const n = Number(v) || 0;
            return n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        },
        fillFromCustomer() {
            const sel = this.$refs.customerSelect;
            const titleEl = document.getElementById('title');
            if (sel && sel.value && titleEl) {
                const name = sel.options[sel.selectedIndex].text.trim();
                if (name) {
                    titleEl.value = name + ' 報價單';
                }
            }
        }
    };
}
</script>
