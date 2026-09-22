<?php
/**
 * 客戶常用發票資料表單（新增 / 編輯共用）。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var int    $customerId
 * @var ?array $profile  編輯時的既有資料（新增時 null）
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

$isEdit = $profile !== null;
$action = $isEdit
    ? "/admin/customers/{$customerId}/invoice-profiles/" . (int) $profile['id']
    : "/admin/customers/{$customerId}/invoice-profiles";

$v = static function (string $key, string $default = '') use ($profile): string {
    return (string) ($profile[$key] ?? $default);
};
?>

<div class="max-w-2xl mx-auto">
    <form method="POST" action="<?= e($action) ?>" class="space-y-6"
          x-data="<?= e(json_encode(['profileType' => $v('profile_type', 'b2c'), 'carrierType' => $v('carrier_type', 'None')], JSON_THROW_ON_ERROR)) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">

        <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6 space-y-5">

            <!-- 標籤 -->
            <div>
                <label for="invoice-profile-label" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">標籤</label>
                <input type="text" id="invoice-profile-label" name="label" value="<?= e($v('label')) ?>"
                       class="w-full rounded-lg border border-slate-300 dark:border-surface-border bg-white dark:bg-surface-card text-slate-800 dark:text-slate-100 px-3 py-2 text-sm"
                       placeholder="例：公司抬頭、個人、捐贈">
            </div>

            <!-- 開票類型 -->
            <div>
                <label for="invoice-profile-profile_type" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">類型</label>
                <select id="invoice-profile-profile_type" name="profile_type" x-model="profileType"
                        class="w-full rounded-lg border border-slate-300 dark:border-surface-border bg-white dark:bg-surface-card text-slate-800 dark:text-slate-100 px-3 py-2 text-sm">
                    <option value="b2c">個人（B2C）</option>
                    <option value="b2b">公司（B2B）</option>
                    <option value="donate">捐贈</option>
                </select>
            </div>

            <!-- 預設 -->
            <div class="flex items-center gap-2">
                <input type="checkbox" name="is_default" value="1" id="is_default"
                       <?= (int) ($profile['is_default'] ?? 0) === 1 ? 'checked' : '' ?>
                       class="rounded border-slate-300 dark:border-surface-border text-blue-600">
                <label for="is_default" class="text-sm text-slate-700 dark:text-slate-300">設為預設</label>
            </div>

            <hr class="border-slate-200 dark:border-surface-border">

            <!-- 買方資料 -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="invoice-profile-buyer_name" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">買方名稱</label>
                    <input type="text" id="invoice-profile-buyer_name" name="buyer_name" value="<?= e($v('buyer_name')) ?>"
                           class="w-full rounded-lg border border-slate-300 dark:border-surface-border bg-white dark:bg-surface-card text-slate-800 dark:text-slate-100 px-3 py-2 text-sm">
                </div>
                <div x-show="profileType === 'b2b'">
                    <label for="invoice-profile-buyer_identifier" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">統一編號</label>
                    <input type="text" id="invoice-profile-buyer_identifier" name="buyer_identifier" value="<?= e($v('buyer_identifier')) ?>"
                           class="w-full rounded-lg border border-slate-300 dark:border-surface-border bg-white dark:bg-surface-card text-slate-800 dark:text-slate-100 px-3 py-2 text-sm font-mono"
                           maxlength="8" pattern="\d{8}" placeholder="8 位數字">
                </div>
                <div>
                    <label for="invoice-profile-buyer_email" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Email</label>
                    <input type="email" id="invoice-profile-buyer_email" name="buyer_email" value="<?= e($v('buyer_email')) ?>"
                           class="w-full rounded-lg border border-slate-300 dark:border-surface-border bg-white dark:bg-surface-card text-slate-800 dark:text-slate-100 px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="invoice-profile-buyer_phone" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">電話</label>
                    <input type="text" id="invoice-profile-buyer_phone" name="buyer_phone" value="<?= e($v('buyer_phone')) ?>"
                           class="w-full rounded-lg border border-slate-300 dark:border-surface-border bg-white dark:bg-surface-card text-slate-800 dark:text-slate-100 px-3 py-2 text-sm">
                </div>
                <div class="sm:col-span-2">
                    <label for="invoice-profile-buyer_address" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">地址</label>
                    <input type="text" id="invoice-profile-buyer_address" name="buyer_address" value="<?= e($v('buyer_address')) ?>"
                           class="w-full rounded-lg border border-slate-300 dark:border-surface-border bg-white dark:bg-surface-card text-slate-800 dark:text-slate-100 px-3 py-2 text-sm">
                </div>
            </div>

            <hr class="border-slate-200 dark:border-surface-border">

            <!-- 載具 / 捐贈 -->
            <div x-show="profileType !== 'donate'" class="space-y-4">
                <div>
                    <label for="invoice-profile-carrier_type" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">載具類型</label>
                    <select id="invoice-profile-carrier_type" name="carrier_type" x-model="carrierType"
                            class="w-full rounded-lg border border-slate-300 dark:border-surface-border bg-white dark:bg-surface-card text-slate-800 dark:text-slate-100 px-3 py-2 text-sm">
                        <option value="None">實體列印</option>
                        <option value="PhoneBarCodeCarrier">手機載具</option>
                        <option value="EasyCardCarrier">悠遊卡</option>
                        <option value="CitizenDigitalCardNo">自然人憑證</option>
                        <option value="BuyerSno">會員載具</option>
                    </select>
                </div>
                <div x-show="['PhoneBarCodeCarrier','EasyCardCarrier','CitizenDigitalCardNo'].includes(carrierType)">
                    <label for="invoice-profile-carrier_id_1" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">載具號碼</label>
                    <input type="text" id="invoice-profile-carrier_id_1" name="carrier_id_1" value="<?= e($v('carrier_id_1')) ?>" x-ref="carrier1"
                           class="w-full rounded-lg border border-slate-300 dark:border-surface-border bg-white dark:bg-surface-card text-slate-800 dark:text-slate-100 px-3 py-2 text-sm font-mono">
                    <input type="hidden" name="carrier_id_2" :value="$refs.carrier1 ? $refs.carrier1.value : <?= e(json_encode($v('carrier_id_2'), JSON_THROW_ON_ERROR)) ?>">
                </div>
            </div>

            <div x-show="profileType === 'donate'">
                <label for="invoice-profile-love_code" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">愛心碼</label>
                <input type="text" id="invoice-profile-love_code" name="love_code" value="<?= e($v('love_code')) ?>"
                       class="w-full rounded-lg border border-slate-300 dark:border-surface-border bg-white dark:bg-surface-card text-slate-800 dark:text-slate-100 px-3 py-2 text-sm font-mono"
                       maxlength="7" placeholder="3~7 位數字">
            </div>
        </div>

        <!-- 送出 -->
        <div class="flex items-center justify-between">
            <a href="/admin/customers/<?= $customerId ?>"
               class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700">取消</a>
            <button type="submit"
                    class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
                <?= $isEdit ? '儲存變更' : '新增' ?>
            </button>
        </div>
    </form>
</div>
