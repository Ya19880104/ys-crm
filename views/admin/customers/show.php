<?php
/**
 * 客戶內頁。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $_flash
 * @var array  $customer      含 contacts[]、assigned_name、created_by_name
 * @var array  $statusLabels  狀態代碼 => 中文標籤
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;
use YangSheep\CRM\Core\BrandColorPolicy;

$isCompany = ($customer['type'] ?? '') === 'company';
$initial   = mb_substr(trim((string) ($customer['display_name'] ?? '?')), 0, 1) ?: '?';
$contacts  = $customer['contacts'] ?? [];
$cid       = (int) $customer['id'];

$statusBadgeCls = [
    'active'    => 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300',
    'potential' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    'inactive'  => 'bg-slate-100 text-slate-600 dark:bg-slate-500/15 dark:text-slate-300',
];
$sCls   = $statusBadgeCls[$customer['status']] ?? 'bg-slate-100 text-slate-600';
$sLabel = $statusLabels[$customer['status']] ?? '—';

// 取主要聯絡人作為 channel 展示來源
$primary = null;
foreach ($contacts as $c) {
    if (!empty($c['is_primary'])) { $primary = $c; break; }
}
if ($primary === null && $contacts !== []) {
    $primary = $contacts[0];
}

// 下方分頁定義（網站 / 主機已接資產模組；其餘仍佔位）
$tabs = [
    'jobs'     => '工作',
    'quotes'   => '報價單',
    'websites' => '網站',
    'hosting'  => '主機',
    'invoices' => '發票資料',
    'payments' => '付款方式',
];

// 資產模組注入（CustomerController::show 提供；防呆預設空陣列）
$websites          = $websites ?? [];
$hostings          = $hostings ?? [];
$customerJobs      = $customerJobs ?? [];
$customerQuotes    = $customerQuotes ?? [];
$quoteStatusLabels = $quoteStatusLabels ?? [];
$jobPriorityLabels = $jobPriorityLabels ?? ['low' => '低', 'medium' => '中', 'high' => '高', 'critical' => '緊急'];
$assetStatusLabels = $assetStatusLabels ?? [];
$caseTypeLabels    = $caseTypeLabels ?? [];
$hostingTypeLabels = $hostingTypeLabels ?? [];

// 秒數 → 時:分（工作計時 chip 用）
$fmtHm = static function (int $sec): string {
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    return sprintf('%d:%02d', $h, $m);
};

// 仍為佔位的 tab（工作 / 網站 / 主機 / 報價單已改為實際清單；付款仍佔位）
$placeholderTabs = ['payments' => '付款方式'];

// 報價單狀態 badge 配色（與報價列表一致）
$quoteStatusChip = [
    'draft'   => 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300',
    'sent'    => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
    'viewed'  => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300',
    'signed'  => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    'paid'    => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200',
    'expired' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    'void'    => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
];

$fmtDate = static function (?string $d): string {
    $d = (string) ($d ?? '');
    return $d !== '' ? substr($d, 0, 10) : '—';
};
?>

<!-- Alpine x-cloak FOUC 防護 -->
<style>[x-cloak]{display:none!important;}</style>

<div x-data="{ activeTab: 'jobs', showAddContact: false }">

    <!-- 返回 -->
    <div class="mb-5">
        <a href="/admin/customers" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            返回客戶列表
        </a>
    </div>

    <!-- 抬頭：頭像 + 名稱 + 類型 + 狀態 + channel -->
    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6 mb-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-center gap-4 min-w-0">
                <span class="w-14 h-14 rounded-full flex items-center justify-center text-xl font-semibold flex-shrink-0
                             <?= $isCompany
                                   ? 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300'
                                   : 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-200' ?>">
                    <?= e($initial) ?>
                </span>
                <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h2 class="text-xl font-bold text-slate-900 dark:text-slate-100 truncate"><?= e($customer['display_name']) ?></h2>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                     <?= $isCompany
                                           ? 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300'
                                           : 'bg-slate-50 text-slate-500 dark:bg-white/5 dark:text-slate-400' ?>">
                            <?= $isCompany ? '公司' : '個人' ?>
                        </span>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $sCls ?>"><?= e($sLabel) ?></span>
                    </div>
                    <!-- channel 快捷 -->
                    <div class="flex items-center gap-3 mt-2 text-sm text-slate-500 dark:text-slate-400">
                        <?php if ($primary && ($primary['line_id'] ?? '') !== ''): ?>
                            <span class="inline-flex items-center gap-1">LINE：<?= e($primary['line_id']) ?></span>
                        <?php endif; ?>
                        <?php if ($primary && ($primary['fb_url'] ?? '') !== ''): ?>
                            <a href="<?= e($primary['fb_url']) ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 hover:underline">Facebook</a>
                        <?php endif; ?>
                        <?php if ($primary && ($primary['threads_url'] ?? '') !== ''): ?>
                            <a href="<?= e($primary['threads_url']) ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 hover:underline">脆</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="/admin/customers/<?= $cid ?>/edit"
                   class="px-4 py-2 text-sm border border-slate-300 dark:border-surface-border rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/5 transition">
                    編輯客戶
                </a>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <!-- 左：基本資料卡 -->
        <div class="lg:col-span-1 space-y-5">
            <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
                <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-4">基本資料</h3>
                <dl class="space-y-3 text-sm">
                    <?php if ($isCompany): ?>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">統一編號</dt>
                        <dd class="text-slate-800 dark:text-slate-200 text-right"><?= ($customer['tax_id'] ?? '') !== '' ? e($customer['tax_id']) : '—' ?></dd>
                    </div>
                    <?php endif; ?>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">電話</dt>
                        <dd class="text-slate-800 dark:text-slate-200 text-right"><?= ($customer['phone'] ?? '') !== '' ? e($customer['phone']) : '—' ?></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Email</dt>
                        <dd class="text-slate-800 dark:text-slate-200 text-right break-all"><?= ($customer['email'] ?? '') !== '' ? e($customer['email']) : '—' ?></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">地址</dt>
                        <dd class="text-slate-800 dark:text-slate-200 text-right"><?= ($customer['address'] ?? '') !== '' ? e($customer['address']) : '—' ?></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">客戶來源</dt>
                        <dd class="text-slate-800 dark:text-slate-200 text-right"><?= ($customer['source'] ?? '') !== '' ? e($customer['source']) : '—' ?></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">負責人</dt>
                        <dd class="text-slate-800 dark:text-slate-200 text-right"><?= ($customer['assigned_name'] ?? '') !== '' ? e($customer['assigned_name']) : '未指定' ?></dd>
                    </div>
                    <div class="flex justify-between gap-3 pt-3 border-t border-slate-100 dark:border-surface-border">
                        <dt class="text-slate-500 dark:text-slate-400">建立者</dt>
                        <dd class="text-slate-800 dark:text-slate-200 text-right"><?= ($customer['created_by_name'] ?? '') !== '' ? e($customer['created_by_name']) : '—' ?></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">建立時間</dt>
                        <dd class="text-slate-800 dark:text-slate-200 text-right"><?= e($customer['created_at'] ?? '') ?></dd>
                    </div>
                </dl>
                <?php if (($customer['notes'] ?? '') !== ''): ?>
                <div class="mt-4 pt-4 border-t border-slate-100 dark:border-surface-border">
                    <dt class="text-slate-500 dark:text-slate-400 text-sm mb-1">備註</dt>
                    <dd class="text-slate-700 dark:text-slate-300 text-sm whitespace-pre-line"><?= e($customer['notes']) ?></dd>
                </div>
                <?php endif; ?>
            </div>

            <?php
            // ─── 客戶 Portal 登入帳號（P4-2；需 customer_user.manage 權限）───────────
            $canManagePortal = $canManagePortal ?? false;
            $portalUsers     = $portalUsers ?? [];
            $hasOwner = false;
            foreach ($portalUsers as $pu) {
                if (($pu['role'] ?? '') === 'owner') { $hasOwner = true; break; }
            }
            ?>
            <?php if ($canManagePortal): ?>
            <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6" x-data="{ showCreate: false }">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100">客戶登入帳號</h3>
                    <?php if (!$hasOwner): ?>
                    <button type="button" @click="showCreate = !showCreate"
                            class="text-blue-600 dark:text-blue-400 hover:text-blue-800 text-sm font-medium flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        建立登入帳號
                    </button>
                    <?php endif; ?>
                </div>

                <!-- 建立客戶主帳號（owner）表單 -->
                <?php if (!$hasOwner): ?>
                <div x-show="showCreate" x-cloak x-transition class="border border-blue-200 dark:border-blue-500/30 bg-blue-50/50 dark:bg-blue-500/5 rounded-lg p-4 mb-4">
                    <form method="POST" action="/admin/customers/<?= $cid ?>/portal-account" class="space-y-3">
                        <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">登入 Email <span class="text-red-600 dark:text-red-400">*</span></label>
                            <input type="email" name="login_email" required maxlength="255"
                                   class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">顯示名稱</label>
                            <input type="text" name="display_name" maxlength="100" value="<?= e($customer['display_name'] ?? '') ?>"
                                   class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">初始密碼（留空由系統產生）</label>
                            <input type="text" name="password" maxlength="200" placeholder="留空 = 自動產生一次性密碼"
                                   class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <p class="text-[11px] text-slate-400 mt-1">因系統未設定寄信，密碼將顯示於畫面一次，請記下並轉達客戶。</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition">建立帳號</button>
                            <button type="button" @click="showCreate = false" class="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition">取消</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <!-- 帳號列表 -->
                <?php if ($portalUsers === []): ?>
                    <p class="text-sm text-slate-400 py-4 text-center">尚未建立客戶登入帳號</p>
                <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($portalUsers as $pu):
                        $puActive = (int) ($pu['is_active'] ?? 0) === 1;
                        $puOwner  = (string) ($pu['role'] ?? '') === 'owner';
                    ?>
                    <div class="border border-slate-200 dark:border-surface-border rounded-lg p-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate"><?= e($pu['display_name'] ?: $pu['login_email']) ?></span>
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium <?= $puOwner ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300' ?>"><?= $puOwner ? '主帳號' : '子帳號' ?></span>
                                    <?php if (!$puActive): ?><span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300">已停用</span><?php endif; ?>
                                </div>
                                <p class="text-xs text-slate-400 mt-0.5 break-all"><?= e($pu['login_email']) ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 mt-2 pt-2 border-t border-slate-100 dark:border-surface-border">
                            <!-- 停用 / 啟用 -->
                            <form method="POST" action="/admin/customers/<?= $cid ?>/portal-account/<?= (int) $pu['id'] ?>/toggle">
                                <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                                <input type="hidden" name="active" value="<?= $puActive ? '0' : '1' ?>">
                                <button type="submit" class="text-xs font-medium <?= $puActive ? 'text-red-600 dark:text-red-400 hover:text-red-800' : 'text-blue-600 hover:text-blue-800' ?>"><?= $puActive ? '停用' : '啟用' ?></button>
                            </form>
                            <!-- 重設密碼 -->
                            <form method="POST" action="/admin/customers/<?= $cid ?>/portal-account/<?= (int) $pu['id'] ?>/reset-password"
                                  onsubmit="return confirm('確定要重設此帳號密碼？將產生新的一次性密碼。')">
                                <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                                <button type="submit" class="text-xs font-medium text-slate-500 dark:text-slate-400 hover:text-slate-700">重設密碼</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- 右：聯絡人卡 + tabs -->
        <div class="lg:col-span-2 space-y-5">
            <!-- 聯絡人卡 -->
            <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100">聯絡人（<?= count($contacts) ?>）</h3>
                    <button type="button" @click="showAddContact = !showAddContact"
                            class="text-blue-600 dark:text-blue-400 hover:text-blue-800 text-sm font-medium flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        新增聯絡人
                    </button>
                </div>

                <!-- 新增聯絡人 inline 表單 -->
                <div x-show="showAddContact" x-cloak x-transition
                     class="border border-blue-200 dark:border-blue-500/30 bg-blue-50/50 dark:bg-blue-500/5 rounded-lg p-4 mb-4">
                    <form method="POST" action="/admin/customers/<?= $cid ?>/contacts" class="space-y-3">
                        <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">姓名 <span class="text-red-600 dark:text-red-400">*</span></label>
                                <input type="text" name="name" required maxlength="100"
                                       class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">職稱</label>
                                <input type="text" name="role" maxlength="100"
                                       class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">市話</label>
                                <input type="text" name="phone" maxlength="50"
                                       class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">手機</label>
                                <input type="text" name="mobile" maxlength="50"
                                       class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Email</label>
                                <input type="email" name="email" maxlength="255"
                                       class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">LINE ID</label>
                                <input type="text" name="line_id" maxlength="100"
                                       class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Facebook 連結</label>
                                <input type="text" name="fb_url" maxlength="255"
                                       class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">脆（Threads）連結</label>
                                <input type="text" name="threads_url" maxlength="255"
                                       class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <input type="checkbox" name="is_primary" value="1" class="rounded text-blue-600 focus:ring-blue-500">
                            設為主要聯絡人
                        </label>
                        <div class="flex items-center gap-2 pt-1">
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition">儲存聯絡人</button>
                            <button type="button" @click="showAddContact = false" class="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition">取消</button>
                        </div>
                    </form>
                </div>

                <!-- 聯絡人列表 -->
                <?php if ($contacts === []): ?>
                    <p class="text-sm text-slate-400 py-6 text-center">尚無聯絡人</p>
                <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($contacts as $contact): ?>
                    <div class="border border-slate-200 dark:border-surface-border rounded-lg p-4 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-medium text-slate-900 dark:text-slate-100"><?= e($contact['name']) ?></span>
                                <?php if (!empty($contact['is_primary'])): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300">主要</span>
                                <?php endif; ?>
                                <?php if (($contact['role'] ?? '') !== ''): ?>
                                    <span class="text-xs text-slate-400"><?= e($contact['role']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="mt-1.5 text-sm text-slate-500 dark:text-slate-400 space-y-0.5">
                                <?php if (($contact['mobile'] ?? '') !== ''): ?><div>手機：<?= e($contact['mobile']) ?></div><?php endif; ?>
                                <?php if (($contact['phone'] ?? '') !== ''): ?><div>市話：<?= e($contact['phone']) ?></div><?php endif; ?>
                                <?php if (($contact['email'] ?? '') !== ''): ?><div class="break-all">Email：<?= e($contact['email']) ?></div><?php endif; ?>
                                <?php if (($contact['line_id'] ?? '') !== ''): ?><div>LINE：<?= e($contact['line_id']) ?></div><?php endif; ?>
                                <div class="flex items-center gap-3">
                                    <?php if (($contact['fb_url'] ?? '') !== ''): ?>
                                        <a href="<?= e($contact['fb_url']) ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 hover:underline text-xs">Facebook</a>
                                    <?php endif; ?>
                                    <?php if (($contact['threads_url'] ?? '') !== ''): ?>
                                        <a href="<?= e($contact['threads_url']) ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 hover:underline text-xs">脆</a>
                                    <?php endif; ?>
                                </div>
                                <?php if (($contact['note'] ?? '') !== ''): ?><div class="text-xs text-slate-400">備註：<?= e($contact['note']) ?></div><?php endif; ?>
                            </div>
                        </div>
                        <form method="POST" action="/admin/customers/<?= $cid ?>/contacts/<?= (int) $contact['id'] ?>/delete"
                              data-confirm="<?= e('確定要刪除聯絡人「' . $contact['name'] . '」？') ?>" onsubmit="return confirm(this.dataset.confirm)">
                            <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                            <button type="submit" class="text-red-600 dark:text-red-400 hover:text-red-700 text-xs whitespace-nowrap">刪除</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- 下方 tabs（目前皆佔位） -->
            <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden">
                <div class="border-b border-slate-200 dark:border-surface-border flex overflow-x-auto">
                    <?php foreach ($tabs as $key => $label): ?>
                    <button type="button" @click="activeTab = '<?= e($key) ?>'"
                            class="px-5 py-3 text-sm font-medium whitespace-nowrap border-b-2 transition"
                            :class="activeTab === '<?= e($key) ?>'
                                    ? 'border-blue-600 text-blue-600 dark:text-blue-400'
                                    : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200'">
                        <?= e($label) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <div class="p-6">
                    <!-- 工作 tab：本客戶工作卡片清單 -->
                    <div x-show="activeTab === 'jobs'" x-cloak>
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="text-sm font-semibold text-slate-700 dark:text-slate-300">工作（<?= count($customerJobs) ?>）</h4>
                            <a href="/admin/jobs/create?customer_id=<?= $cid ?>"
                               class="text-blue-600 dark:text-blue-400 hover:text-blue-800 text-sm font-medium flex items-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                </svg>
                                新增工作
                            </a>
                        </div>
                        <?php if ($customerJobs === []): ?>
                            <p class="text-sm text-slate-400 py-6 text-center">尚無工作</p>
                        <?php else: ?>
                        <div class="space-y-2">
                            <?php foreach ($customerJobs as $j): ?>
                            <?php
                                $jRunning  = (int) ($j['running_count'] ?? 0) > 0;
                                $jTotalSec = (int) ($j['total_seconds'] ?? 0);
                                $jPrio     = (string) ($j['priority'] ?? 'medium');
                            ?>
                            <a href="/admin/jobs/<?= (int) $j['id'] ?>"
                               class="flex items-center justify-between gap-3 p-3 border border-slate-200 dark:border-surface-border rounded-lg hover:bg-slate-50 dark:hover:bg-white/5 transition">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="w-2 h-2 rounded-full flex-shrink-0" style="background-color: <?= e(BrandColorPolicy::forDisplay($j['column_color'] ?? '#1E40AF')) ?>;"></span>
                                    <span class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate"><?= e($j['title']) ?></span>
                                    <?php if (($j['completed_at'] ?? null) !== null): ?>
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">完成</span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center gap-2 flex-shrink-0 text-xs">
                                    <span class="text-slate-400"><?= e($j['column_name'] ?? '') ?></span>
                                    <?php if ($jRunning): ?>
                                    <span class="inline-flex items-center gap-1 text-red-600 dark:text-red-400 font-medium">
                                        <span class="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse"></span><?= e($fmtHm($jTotalSec)) ?>
                                    </span>
                                    <?php elseif ($jTotalSec > 0): ?>
                                    <span class="text-slate-400"><?= e($fmtHm($jTotalSec)) ?></span>
                                    <?php endif; ?>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- 報價單 tab：本客戶報價單清單 -->
                    <div x-show="activeTab === 'quotes'" x-cloak>
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="text-sm font-semibold text-slate-700 dark:text-slate-300">報價單（<?= count($customerQuotes) ?>）</h4>
                            <a href="/admin/quotes/create?customer_id=<?= $cid ?>"
                               class="text-blue-600 dark:text-blue-400 hover:text-blue-800 text-sm font-medium flex items-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                </svg>
                                新增報價單
                            </a>
                        </div>
                        <?php if ($customerQuotes === []): ?>
                            <p class="text-sm text-slate-400 py-6 text-center">尚無報價單</p>
                        <?php else: ?>
                        <div class="overflow-x-auto">
                        <table class="w-full text-sm whitespace-nowrap">
                            <thead class="bg-slate-50 dark:bg-white/5 text-slate-500 dark:text-slate-400">
                                <tr>
                                    <th class="text-left px-3 py-2 font-medium">編號</th>
                                    <th class="text-left px-3 py-2 font-medium">標題</th>
                                    <th class="text-right px-3 py-2 font-medium">金額</th>
                                    <th class="text-left px-3 py-2 font-medium">狀態</th>
                                    <th class="text-left px-3 py-2 font-medium">有效期限</th>
                                    <th class="text-right px-3 py-2 font-medium">操作</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
                                <?php foreach ($customerQuotes as $q): ?>
                                <?php $qStatus = (string) $q['status']; ?>
                                <tr>
                                    <td class="px-3 py-2">
                                        <a href="/admin/quotes/<?= (int) $q['id'] ?>" class="font-mono text-xs text-blue-600 dark:text-blue-400 hover:underline"><?= e($q['quote_number']) ?></a>
                                    </td>
                                    <td class="px-3 py-2 max-w-[200px] truncate text-slate-700 dark:text-slate-300" title="<?= e($q['title']) ?>"><?= e($q['title']) ?></td>
                                    <td class="px-3 py-2 text-right text-slate-800 dark:text-slate-100 font-medium tabular-nums"><?= e((string) ($q['currency'] ?? 'TWD')) ?> <?= e(number_format((float) $q['total'], 0)) ?></td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= $quoteStatusChip[$qStatus] ?? $quoteStatusChip['draft'] ?>"><?= e($quoteStatusLabels[$qStatus] ?? $qStatus) ?></span>
                                    </td>
                                    <td class="px-3 py-2 text-slate-600 dark:text-slate-300"><?= e($fmtDate($q['valid_until'] ?? null)) ?></td>
                                    <td class="px-3 py-2 text-right">
                                        <a href="/admin/quotes/<?= (int) $q['id'] ?>" class="text-slate-500 dark:text-slate-400 hover:text-slate-700 text-xs font-medium">檢視</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- 付款方式：仍為佔位 -->
                    <?php foreach ($placeholderTabs as $key => $label): ?>
                    <div x-show="activeTab === '<?= e($key) ?>'" x-cloak class="text-center py-6">
                        <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-slate-100 dark:bg-white/5 text-slate-400 mb-3">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>
                            </svg>
                        </div>
                        <p class="text-slate-500 dark:text-slate-400 text-sm"><?= e($label) ?>模組建置中</p>
                        <p class="text-slate-400 dark:text-slate-500 text-xs mt-1">此區塊將於對應模組完成後顯示與本客戶相關的<?= e($label) ?>。</p>
                    </div>
                    <?php endforeach; ?>

                    <!-- 網站 tab：本客戶網站資產清單 -->
                    <div x-show="activeTab === 'websites'" x-cloak>
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="text-sm font-semibold text-slate-700 dark:text-slate-300">網站資產（<?= count($websites) ?>）</h4>
                            <a href="/admin/websites/create?customer_id=<?= $cid ?>"
                               class="text-blue-600 dark:text-blue-400 hover:text-blue-800 text-sm font-medium flex items-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                </svg>
                                新增網站
                            </a>
                        </div>
                        <?php if ($websites === []): ?>
                            <p class="text-sm text-slate-400 py-6 text-center">尚無網站資產</p>
                        <?php else: ?>
                        <div class="overflow-x-auto">
                        <table class="w-full text-sm whitespace-nowrap">
                            <thead class="bg-slate-50 dark:bg-white/5 text-slate-500 dark:text-slate-400">
                                <tr>
                                    <th class="text-left px-3 py-2 font-medium">網址</th>
                                    <th class="text-left px-3 py-2 font-medium">案件類型</th>
                                    <th class="text-left px-3 py-2 font-medium">合約起訖</th>
                                    <th class="text-left px-3 py-2 font-medium">狀態</th>
                                    <th class="text-right px-3 py-2 font-medium">操作</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
                                <?php foreach ($websites as $w): ?>
                                <?php $url = trim((string) ($w['url'] ?? '')); ?>
                                <tr>
                                    <td class="px-3 py-2 max-w-[220px] truncate">
                                        <?php if ($url !== ''): ?>
                                            <a href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 hover:underline" title="<?= e($url) ?>"><?= e($url) ?></a>
                                        <?php else: ?><span class="text-slate-400">—</span><?php endif; ?>
                                    </td>
                                    <td class="px-3 py-2 text-slate-600 dark:text-slate-300"><?= e($caseTypeLabels[$w['case_type']] ?? (string) $w['case_type']) ?></td>
                                    <td class="px-3 py-2 text-slate-600 dark:text-slate-300"><?= e($fmtDate($w['contract_start'] ?? null)) ?> ~ <?= e($fmtDate($w['contract_end'] ?? null)) ?></td>
                                    <td class="px-3 py-2">
                                        <?php $view->partial('asset-status-badge', ['status' => (string) $w['status'], 'dueDate' => $w['contract_end'] ?? null, 'statusLabels' => $assetStatusLabels]); ?>
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <a href="/admin/websites/<?= (int) $w['id'] ?>/edit" class="text-slate-500 dark:text-slate-400 hover:text-slate-700 text-xs font-medium">編輯</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- 主機 tab：本客戶主機資產清單 -->
                    <div x-show="activeTab === 'hosting'" x-cloak>
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="text-sm font-semibold text-slate-700 dark:text-slate-300">主機資產（<?= count($hostings) ?>）</h4>
                            <a href="/admin/hosting/create?customer_id=<?= $cid ?>"
                               class="text-blue-600 dark:text-blue-400 hover:text-blue-800 text-sm font-medium flex items-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                </svg>
                                新增主機
                            </a>
                        </div>
                        <?php if ($hostings === []): ?>
                            <p class="text-sm text-slate-400 py-6 text-center">尚無主機資產</p>
                        <?php else: ?>
                        <div class="overflow-x-auto">
                        <table class="w-full text-sm whitespace-nowrap">
                            <thead class="bg-slate-50 dark:bg-white/5 text-slate-500 dark:text-slate-400">
                                <tr>
                                    <th class="text-left px-3 py-2 font-medium">類型</th>
                                    <th class="text-left px-3 py-2 font-medium">IP 位址</th>
                                    <th class="text-left px-3 py-2 font-medium">租用起訖</th>
                                    <th class="text-left px-3 py-2 font-medium">狀態</th>
                                    <th class="text-right px-3 py-2 font-medium">操作</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
                                <?php foreach ($hostings as $h): ?>
                                <?php $ip = trim((string) ($h['ip_address'] ?? '')); ?>
                                <tr>
                                    <td class="px-3 py-2 text-slate-600 dark:text-slate-300"><?= e($hostingTypeLabels[$h['type']] ?? (string) $h['type']) ?></td>
                                    <td class="px-3 py-2 text-slate-600 dark:text-slate-300 font-mono text-xs"><?= $ip !== '' ? e($ip) : '<span class="text-slate-400 font-sans">—</span>' ?></td>
                                    <td class="px-3 py-2 text-slate-600 dark:text-slate-300"><?= e($fmtDate($h['start_date'] ?? null)) ?> ~ <?= e($fmtDate($h['end_date'] ?? null)) ?></td>
                                    <td class="px-3 py-2">
                                        <?php $view->partial('asset-status-badge', ['status' => (string) $h['status'], 'dueDate' => $h['end_date'] ?? null, 'statusLabels' => $assetStatusLabels]); ?>
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <a href="/admin/hosting/<?= (int) $h['id'] ?>/edit" class="text-slate-500 dark:text-slate-400 hover:text-slate-700 text-xs font-medium">編輯</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- 發票資料 tab：常用開票抬頭 CRUD -->
                    <div x-show="activeTab === 'invoices'" x-cloak>
                        <?php
                        $invoiceProfiles = $invoiceProfiles ?? [];
                        $profileTypeLabels = ['b2c' => '個人（B2C）', 'b2b' => '公司（B2B）', 'donate' => '捐贈'];
                        $carrierTypeLabels = [
                            ''     => '—',
                            'None' => '實體列印',
                            'PhoneBarCodeCarrier'    => '手機載具',
                            'EasyCardCarrier'        => '悠遊卡',
                            'CitizenDigitalCardNo'   => '自然人憑證',
                            'BuyerSno'               => '會員載具',
                        ];
                        ?>
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="text-sm font-semibold text-slate-700 dark:text-slate-300">常用發票資料（<?= count($invoiceProfiles) ?>）</h4>
                            <?php if ($canManageInvoiceProfiles ?? false): ?>
                            <a href="/admin/customers/<?= $cid ?>/invoice-profiles/create"
                               class="text-blue-600 dark:text-blue-400 hover:text-blue-800 text-sm font-medium flex items-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                </svg>
                                新增
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php if ($invoiceProfiles === []): ?>
                            <p class="text-sm text-slate-400 py-6 text-center">尚無常用發票資料。新增後可在開立發票時自動帶入。</p>
                        <?php else: ?>
                        <div class="overflow-x-auto">
                        <table class="w-full text-sm whitespace-nowrap">
                            <thead class="bg-slate-50 dark:bg-white/5 text-slate-500 dark:text-slate-400">
                                <tr>
                                    <th class="text-left px-3 py-2 font-medium">標籤</th>
                                    <th class="text-left px-3 py-2 font-medium">類型</th>
                                    <th class="text-left px-3 py-2 font-medium">抬頭</th>
                                    <th class="text-left px-3 py-2 font-medium">統編</th>
                                    <th class="text-left px-3 py-2 font-medium">載具</th>
                                    <th class="text-left px-3 py-2 font-medium">預設</th>
                                    <?php if ($canManageInvoiceProfiles ?? false): ?>
                                    <th class="text-right px-3 py-2 font-medium">操作</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
                                <?php foreach ($invoiceProfiles as $p): ?>
                                <tr>
                                    <td class="px-3 py-2 text-slate-600 dark:text-slate-300"><?= e((string) ($p['label'] ?? '')) ?: '<span class="text-slate-400">—</span>' ?></td>
                                    <td class="px-3 py-2 text-slate-600 dark:text-slate-300"><?= e($profileTypeLabels[$p['profile_type'] ?? ''] ?? (string) ($p['profile_type'] ?? '')) ?></td>
                                    <td class="px-3 py-2 text-slate-600 dark:text-slate-300"><?= e((string) ($p['buyer_name'] ?? '')) ?: '—' ?></td>
                                    <td class="px-3 py-2 text-slate-600 dark:text-slate-300 font-mono text-xs"><?= e((string) ($p['buyer_identifier'] ?? '')) ?: '—' ?></td>
                                    <td class="px-3 py-2 text-slate-600 dark:text-slate-300"><?= e($carrierTypeLabels[$p['carrier_type'] ?? ''] ?? (string) ($p['carrier_type'] ?? '')) ?></td>
                                    <td class="px-3 py-2">
                                        <?php if ((int) ($p['is_default'] ?? 0) === 1): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-600 text-white">預設</span>
                                        <?php elseif ($canManageInvoiceProfiles ?? false): ?>
                                            <form method="POST" action="/admin/customers/<?= $cid ?>/invoice-profiles/<?= (int) $p['id'] ?>/set-default" class="inline">
                                                <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                                                <button type="submit" class="text-xs text-slate-400 hover:text-blue-600">設為預設</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($canManageInvoiceProfiles ?? false): ?>
                                    <td class="px-3 py-2 text-right space-x-2">
                                        <a href="/admin/customers/<?= $cid ?>/invoice-profiles/<?= (int) $p['id'] ?>/edit"
                                           class="text-slate-500 dark:text-slate-400 hover:text-slate-700 text-xs font-medium">編輯</a>
                                        <form method="POST" action="/admin/customers/<?= $cid ?>/invoice-profiles/<?= (int) $p['id'] ?>/delete" class="inline"
                                              data-confirm="<?= e('確定刪除「' . (string) ($p['label'] ?? '') . '」？此操作無法復原。') ?>" onsubmit="return confirm(this.dataset.confirm)">
                                            <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                                            <button type="submit" class="text-red-500 hover:text-red-700 text-xs font-medium">刪除</button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
