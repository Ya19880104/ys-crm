<?php
/**
 * 客戶表單（create / edit 共用）。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var string $action        表單送出目標 URL
 * @var string $submitLabel   送出按鈕文字
 * @var array  $assignees     負責人下拉選項 [{id, display_name}]
 * @var array  $statusLabels  狀態代碼 => 中文標籤
 * @var array  $customer      編輯時的客戶資料（新增時為空陣列）
 * @var bool   $showContacts  是否顯示聯絡人多筆區塊（新增時 true）
 */

use function YangSheep\CRM\Core\e;

$customer     = $customer ?? [];
$showContacts = $showContacts ?? false;
$cType        = $customer['type'] ?? 'individual';
$cStatus      = $customer['status'] ?? 'active';
$assignedTo   = (int) ($customer['assigned_to'] ?? 0);
?>

<form method="POST" action="<?= e($action) ?>" class="space-y-6"
      x-data="{
          type: '<?= e($cType) ?>',
          contacts: [{ name: '', role: '', is_primary: true, phone: '', mobile: '', email: '', line_id: '', fb_url: '', threads_url: '', note: '' }],
          addContact() {
              this.contacts.push({ name: '', role: '', is_primary: false, phone: '', mobile: '', email: '', line_id: '', fb_url: '', threads_url: '', note: '' });
          },
          removeContact(i) {
              if (this.contacts.length > 1) { this.contacts.splice(i, 1); }
          },
          setPrimary(i) {
              this.contacts.forEach((c, idx) => c.is_primary = (idx === i));
          }
      }">
    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">

    <!-- 基本資料卡 -->
    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
        <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-5">基本資料</h3>

        <!-- 客戶類型 -->
        <div class="mb-5">
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">客戶類型 <span class="text-red-600 dark:text-red-400">*</span></label>
            <div class="flex gap-3">
                <label class="flex items-center gap-2 px-4 py-2.5 border rounded-lg cursor-pointer transition text-sm flex-1
                              border-slate-300 dark:border-surface-border"
                       :class="type === 'individual' ? 'ring-2 ring-blue-500 border-blue-500 bg-blue-50 dark:bg-blue-500/10' : ''">
                    <input type="radio" name="type" value="individual" x-model="type" class="text-blue-600 focus:ring-blue-500">
                    <span class="text-slate-700 dark:text-slate-200">個人</span>
                </label>
                <label class="flex items-center gap-2 px-4 py-2.5 border rounded-lg cursor-pointer transition text-sm flex-1
                              border-slate-300 dark:border-surface-border"
                       :class="type === 'company' ? 'ring-2 ring-blue-500 border-blue-500 bg-blue-50 dark:bg-blue-500/10' : ''">
                    <input type="radio" name="type" value="company" x-model="type" class="text-blue-600 focus:ring-blue-500">
                    <span class="text-slate-700 dark:text-slate-200">公司</span>
                </label>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <!-- 名稱 -->
            <div>
                <label for="display_name" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                    <span x-text="type === 'company' ? '公司名稱' : '客戶姓名'">客戶姓名</span> <span class="text-red-600 dark:text-red-400">*</span>
                </label>
                <input type="text" id="display_name" name="display_name" required maxlength="150"
                       value="<?= e($customer['display_name'] ?? '') ?>"
                       class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                       placeholder="請輸入名稱">
            </div>

            <!-- 統編（僅公司顯示） -->
            <div x-show="type === 'company'" x-cloak>
                <label for="tax_id" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">統一編號</label>
                <input type="text" id="tax_id" name="tax_id" maxlength="20"
                       value="<?= e($customer['tax_id'] ?? '') ?>"
                       class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                       placeholder="8 碼統一編號">
            </div>

            <!-- 電話 -->
            <div>
                <label for="phone" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">電話</label>
                <input type="text" id="phone" name="phone" maxlength="50"
                       value="<?= e($customer['phone'] ?? '') ?>"
                       class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                       placeholder="02-1234-5678 或 0912-345-678">
            </div>

            <!-- Email -->
            <div>
                <label for="email" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Email</label>
                <input type="email" id="email" name="email" maxlength="255"
                       value="<?= e($customer['email'] ?? '') ?>"
                       class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                       placeholder="contact@example.com">
            </div>

            <!-- 地址 -->
            <div class="md:col-span-2">
                <label for="address" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">地址</label>
                <input type="text" id="address" name="address" maxlength="255"
                       value="<?= e($customer['address'] ?? '') ?>"
                       class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                       placeholder="完整地址">
            </div>

            <!-- 來源 -->
            <div>
                <label for="source" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">客戶來源</label>
                <input type="text" id="source" name="source" maxlength="100"
                       value="<?= e($customer['source'] ?? '') ?>"
                       class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                       placeholder="例如：官網表單、朋友介紹、廣告">
            </div>

            <!-- 狀態 -->
            <div>
                <label for="status" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">狀態 <span class="text-red-600 dark:text-red-400">*</span></label>
                <select id="status" name="status" required
                        class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <?php foreach (($statusLabels ?? []) as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= $cStatus === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 負責人 -->
            <div>
                <label for="assigned_to" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">負責人</label>
                <select id="assigned_to" name="assigned_to"
                        class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <option value="">未指定</option>
                    <?php foreach (($assignees ?? []) as $assignee): ?>
                    <option value="<?= e((string) $assignee['id']) ?>" <?= $assignedTo === (int) $assignee['id'] ? 'selected' : '' ?>>
                        <?= e($assignee['display_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 備註 -->
            <div class="md:col-span-2">
                <label for="notes" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">備註</label>
                <textarea id="notes" name="notes" rows="3"
                          class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                          placeholder="內部備註"><?= e($customer['notes'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <?php if ($showContacts): ?>
    <!-- 聯絡人多筆（僅新增時於此區塊填寫；編輯後請於客戶內頁管理） -->
    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
        <div class="flex items-center justify-between mb-5">
            <div>
                <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100">聯絡人</h3>
                <p class="text-xs text-slate-400 mt-1">個人客戶若不填寫，將自動以客戶姓名建立一筆主要聯絡人。</p>
            </div>
            <button type="button" @click="addContact()"
                    class="text-blue-600 dark:text-blue-400 hover:text-blue-800 text-sm font-medium flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                新增一位聯絡人
            </button>
        </div>

        <template x-for="(contact, i) in contacts" :key="i">
            <div class="border border-slate-200 dark:border-surface-border rounded-lg p-4 mb-4 relative">
                <div class="flex items-center justify-between mb-3">
                    <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input type="radio" :name="'__primary_radio'" :checked="contact.is_primary"
                               @change="setPrimary(i)" class="text-blue-600 focus:ring-blue-500">
                        設為主要聯絡人
                    </label>
                    <button type="button" @click="removeContact(i)" x-show="contacts.length > 1"
                            class="text-red-600 dark:text-red-400 hover:text-red-700 text-xs">移除</button>
                </div>

                <!-- 主要聯絡人 hidden 旗標 -->
                <input type="hidden" :name="'contacts[' + i + '][is_primary]'" :value="contact.is_primary ? '1' : '0'">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">姓名</label>
                        <input type="text" :name="'contacts[' + i + '][name]'" x-model="contact.name" maxlength="100"
                               class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                               placeholder="聯絡人姓名">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">職稱</label>
                        <input type="text" :name="'contacts[' + i + '][role]'" x-model="contact.role" maxlength="100"
                               class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                               placeholder="例如：採購、負責人">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">市話</label>
                        <input type="text" :name="'contacts[' + i + '][phone]'" x-model="contact.phone" maxlength="50"
                               class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">手機</label>
                        <input type="text" :name="'contacts[' + i + '][mobile]'" x-model="contact.mobile" maxlength="50"
                               class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Email</label>
                        <input type="email" :name="'contacts[' + i + '][email]'" x-model="contact.email" maxlength="255"
                               class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">LINE ID</label>
                        <input type="text" :name="'contacts[' + i + '][line_id]'" x-model="contact.line_id" maxlength="100"
                               class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Facebook 連結</label>
                        <input type="text" :name="'contacts[' + i + '][fb_url]'" x-model="contact.fb_url" maxlength="255"
                               class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                               placeholder="https://facebook.com/...">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">脆（Threads）連結</label>
                        <input type="text" :name="'contacts[' + i + '][threads_url]'" x-model="contact.threads_url" maxlength="255"
                               class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                               placeholder="https://threads.net/...">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">聯絡人備註</label>
                        <input type="text" :name="'contacts[' + i + '][note]'" x-model="contact.note"
                               class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    </div>
                </div>
            </div>
        </template>
    </div>
    <?php endif; ?>

    <!-- 送出按鈕 -->
    <div class="flex items-center gap-3">
        <button type="submit"
                class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
            <?= e($submitLabel ?? '儲存') ?>
        </button>
        <a href="/admin/customers"
           class="px-6 py-2.5 rounded-lg text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition">
            取消
        </a>
    </div>
</form>
