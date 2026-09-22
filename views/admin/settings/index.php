<?php
/**
 * 系統設定頁面（後台）
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var array $schema  設定結構定義
 * @var array $settings 目前的設定值（按 group 分組）
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;
?>

<?php
/*
 * 【為何做成頁簽】設定原本是十個群組直式堆疊，找一個欄位要一直捲。
 * 分頁之後也順便把相關的東西放在一起：Email 測試與 Email 設定同頁、
 * 訪客 IP 偵測與 CDN 同頁（兩者處理的是同一件事 —— 站台前面有東西）。
 *
 * 頁簽以 Alpine 切換（純前端，不重新載入），但**表單是同一份**：
 * 所有頁簽的欄位都在同一個 <form> 內，按一次「儲存」就全部存檔。
 * 若每個頁簽各自送出，使用者在 A 頁改完切到 B 頁再存，A 的變更就不見了。
 */
$tabs = [];
foreach ($schema as $g => $def) {
    $tabName = (string) ($def['tab'] ?? '其他');
    $tabs[$tabName][] = $g;
}
$tabNames  = array_keys($tabs);
$activeTab = $tabNames[0] ?? '';
?>

<script src="<?= \YangSheep\CRM\Core\asset('/assets/js/settings-einvoice.js') ?>"></script>
<div x-data="{ tab: YsEinvoiceSettings.selectTab(<?= e(json_encode($tabNames, JSON_UNESCAPED_UNICODE)) ?>, <?= e(json_encode($activeTab, JSON_UNESCAPED_UNICODE)) ?>, window.location.search, window.location.hash) }">

    <?php $view->partial('components/module-readiness-link-styles'); ?>
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <a href="/admin/settings/modules" class="ys-btn ys-btn-outline ys-module-link">功能模組盤點</a>
        <span class="text-sm text-slate-600 dark:text-slate-300">只讀清冊，不變更目前功能。</span>
    </div>

    <!-- 頁簽列 -->
    <div class="ys-tabs" role="tablist">
        <?php foreach ($tabNames as $name): ?>
        <button type="button" role="tab"
                @click="tab = <?= e(json_encode($name, JSON_UNESCAPED_UNICODE)) ?>"
                :aria-selected="tab === <?= e(json_encode($name, JSON_UNESCAPED_UNICODE)) ?> ? 'true' : 'false'"
                :class="tab === <?= e(json_encode($name, JSON_UNESCAPED_UNICODE)) ?> ? 'is-active' : ''"
                class="ys-tab">
            <?= e($name) ?>
        </button>
        <?php endforeach; ?>
    </div>

<form method="POST" action="/admin/settings" enctype="multipart/form-data" class="space-y-8">
    <?= \YangSheep\CRM\Core\Csrf::field() ?>

    <?php
        // 共用的輸入框樣式（含深色變體）
        $inputClass = 'w-full rounded-lg border border-slate-300 dark:border-surface-border bg-white dark:bg-navy-topbar text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 shadow-sm focus:border-brand-light focus:ring-brand-light text-sm px-4 py-2.5';
    ?>
    <?php foreach ($schema as $group => $groupDef): ?>
        <?php $groupTab = (string) ($groupDef['tab'] ?? '其他'); ?>
        <div x-show="tab === <?= e(json_encode($groupTab, JSON_UNESCAPED_UNICODE)) ?>"
             x-cloak
             class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden transition-colors">
            <!-- 群組標題 -->
            <div class="px-6 py-4 bg-slate-50 dark:bg-navy-topbar border-b border-slate-200 dark:border-surface-border flex items-center gap-3">
                <svg class="w-5 h-5 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $groupDef['icon'] ?? '' ?>"/>
                </svg>
                <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100"><?= e($groupDef['label']) ?></h3>
            </div>

            <!-- 欄位列表 -->
            <div class="p-6 space-y-5">
                <?php foreach ($groupDef['fields'] as $key => $fieldDef):
                    $inputName = "{$group}__{$key}";
                    $currentValue = $settings[$group][$key] ?? '';
                ?>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 items-start">
                        <label for="<?= e($inputName) ?>" class="text-sm font-medium text-slate-700 dark:text-slate-300 pt-2">
                            <?= e($fieldDef['label']) ?>
                        </label>
                        <div class="sm:col-span-2">
                            <?php if ($fieldDef['type'] === 'textarea'): ?>
                                <textarea id="<?= e($inputName) ?>" name="<?= e($inputName) ?>" rows="3"
                                          class="<?= $inputClass ?>"
                                ><?= e($currentValue) ?></textarea>

                            <?php elseif ($fieldDef['type'] === 'select'): ?>
                                <select id="<?= e($inputName) ?>" name="<?= e($inputName) ?>"
                                        class="<?= $inputClass ?>">
                                    <?php foreach ($fieldDef['options'] as $optVal => $optLabel): ?>
                                        <option value="<?= e($optVal) ?>" <?= $currentValue === (string) $optVal ? 'selected' : '' ?>>
                                            <?= e($optLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                            <?php elseif ($fieldDef['type'] === 'image'): ?>
                                <?php if ($currentValue !== ''): ?>
                                    <div class="mb-3">
                                        <img src="<?= e($currentValue) ?>" alt="<?= e($fieldDef['label']) ?>"
                                             class="max-w-xs rounded-lg border border-slate-200 dark:border-surface-border shadow-sm">
                                    </div>
                                <?php endif; ?>
                                <input type="file" id="<?= e($inputName) ?>" name="<?= e($inputName) ?>"
                                       accept="image/jpeg,image/png,image/webp"
                                       class="w-full text-sm text-slate-500 dark:text-slate-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 dark:file:bg-brand-light/15 file:text-blue-700 dark:file:text-brand-dark hover:file:bg-blue-100 dark:hover:file:bg-brand-light/25">
                                <?php if ($currentValue !== ''): ?>
                                    <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-1">已上傳（重新選擇檔案可覆蓋）</p>
                                <?php else: ?>
                                    <p class="text-xs text-slate-400 mt-1">尚未上傳</p>
                                <?php endif; ?>

                            <?php elseif ($fieldDef['type'] === 'password'): ?>
                                <input type="password" id="<?= e($inputName) ?>" name="<?= e($inputName) ?>"
                                       class="<?= $inputClass ?>"
                                       placeholder="<?= $currentValue !== '' ? '(已設定，留空則不修改)' : '尚未設定' ?>"
                                       autocomplete="off">
                                <?php if ($currentValue !== ''): ?>
                                    <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-1">已設定（留空則不修改）</p>
                                <?php endif; ?>

                            <?php else: ?>
                                <input type="<?= e($fieldDef['type']) ?>" id="<?= e($inputName) ?>" name="<?= e($inputName) ?>"
                                       value="<?= e($currentValue) ?>"
                                       class="<?= $inputClass ?>">
                            <?php endif; ?>

                            <?php /* 說明文字：欄位的取捨與注意事項寫在畫面上，
                                     而不是只留在程式碼註解裡。

                                     🔴 `hint` 與 `help` 兩個鍵都吃。原本 `help` 只在
                                     image 欄位的分支裡渲染，於是 schema 中 20 個非圖片欄位
                                     （整個電子發票群組、金流憑證、週期帳務…）寫了說明卻
                                     **一個字都沒出現** —— 而且不會有任何錯誤，
                                     只是畫面上少了一段話，沒有人會發現。

                                     鍵名打錯是遲早的事，所以由這裡吸收，
                                     而不是要求 21 個欄位定義都記得用對哪一個。 */ ?>
                            <?php $fieldHint = trim((string) ($fieldDef['hint'] ?? $fieldDef['help'] ?? '')); ?>
                            <?php if ($fieldHint !== ''): ?>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1.5 leading-relaxed">
                                <?= e($fieldHint) ?>
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if ($group === 'cdn'): ?>
                <?php /* 訪客 IP 偵測診斷：這是本設定唯一能被「選對」的方式。
                         管理員不必理解各家設備寫哪個 header，只要看哪一列
                         等於自己已知的公網 IP 即可。 */ ?>
                <div class="pt-5 border-t border-slate-200 dark:border-surface-border">
                    <h4 class="text-sm font-semibold text-slate-800 dark:text-slate-100 mb-1">偵測結果（此刻這個請求）</h4>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mb-3 leading-relaxed">
                        請先查出您自己的公網 IP（例如用手機關掉 Wi-Fi 連 4G 開啟本頁，或搜尋「my ip」），
                        再看下表哪一列與它相同 —— 那一列就是正確的偵測方式。
                        <span class="block mt-1">
                            目前生效：<span class="font-mono text-slate-700 dark:text-slate-200"><?= e($currentIp ?? '') ?></span>
                            <?php if (empty($ipIsReal)): ?>
                            <span class="text-amber-700 dark:text-amber-300">（來自 REMOTE_ADDR，非訪客真實位址）</span>
                            <?php else: ?>
                            <span class="text-blue-700 dark:text-blue-300">（來自 header）</span>
                            <?php endif; ?>
                        </span>
                    </p>

                    <?php
                    /* 🔴 目前選用的模式若屬於「單值 header」，先在表格上方講清楚前提。
                       這不是可有可無的提醒：選了這種模式而前端設備又沒有覆寫該欄位時，
                       任何人都能自己送一個值，所有以 IP 為基準的限流與紀錄同時失效，
                       而畫面上不會有任何異常 —— 沒人講就沒人會知道。 */
                    $activeMode    = (string) ($settings['cdn']['client_ip_mode'] ?? 'remote_addr');
                    $activeWarning = '';
                    foreach (($ipDiagnosis ?? []) as $d) {
                        if (($d['mode'] ?? '') === $activeMode && !empty($d['forgeable'])) {
                            $activeWarning = (string) ($d['warning'] ?? '');
                            break;
                        }
                    }
                    ?>
                    <?php if ($activeWarning !== ''): ?>
                    <div class="mb-3 rounded-lg border border-amber-300 dark:border-amber-500/40 bg-amber-50 dark:bg-amber-500/10 px-3 py-2.5">
                        <p class="text-xs text-amber-800 dark:text-amber-200 leading-relaxed">
                            <span class="font-semibold">目前選用的模式可被偽造。</span>
                            <?= e($activeWarning) ?>
                        </p>
                    </div>
                    <?php endif; ?>

                    <div class="overflow-x-auto">
                    <table class="w-full text-xs whitespace-nowrap">
                        <thead class="bg-slate-50 dark:bg-white/5">
                            <tr>
                                <th class="text-left px-3 py-2 font-medium text-slate-500 dark:text-slate-400">偵測方式</th>
                                <th class="text-left px-3 py-2 font-medium text-slate-500 dark:text-slate-400">Header</th>
                                <th class="text-left px-3 py-2 font-medium text-slate-500 dark:text-slate-400">原始值</th>
                                <th class="text-left px-3 py-2 font-medium text-slate-500 dark:text-slate-400">取得的 IP</th>
                                <th class="text-left px-3 py-2 font-medium text-slate-500 dark:text-slate-400">判讀</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
                            <?php foreach (($ipDiagnosis ?? []) as $d): ?>
                            <tr class="align-top">
                                <td class="px-3 py-2 text-slate-700 dark:text-slate-200">
                                    <?= e($d['label']) ?>
                                    <?php if (!empty($d['forgeable'])): ?>
                                    <span class="ml-1 inline-block align-middle rounded px-1.5 py-0.5 text-[10px] font-medium
                                                 bg-amber-100 dark:bg-amber-500/15 text-amber-800 dark:text-amber-200"
                                          title="<?= e((string) ($d['warning'] ?? '')) ?>">可偽造</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2 font-mono text-slate-500 dark:text-slate-400"><?= e($d['header']) ?></td>
                                <td class="px-3 py-2 font-mono text-slate-500 dark:text-slate-400 max-w-[220px] truncate"><?= e($d['raw'] !== '' ? $d['raw'] : '—') ?></td>
                                <td class="px-3 py-2 font-mono <?= $d['usable'] ? 'text-slate-800 dark:text-slate-100 font-semibold' : 'text-slate-400' ?>">
                                    <?= e($d['ip'] !== '' ? $d['ip'] : '—') ?>
                                </td>
                                <td class="px-3 py-2 whitespace-normal max-w-xs <?= str_contains($d['note'], '公網') ? 'text-blue-700 dark:text-blue-300' : 'text-slate-500 dark:text-slate-400' ?>">
                                    <?= e($d['note']) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <p class="text-xs text-amber-700 dark:text-amber-300 mt-3 leading-relaxed">
                        ⚠️ 若所有列都顯示私有位址，代表前端設備（防火牆／CDN）目前<strong>沒有</strong>把訪客 IP
                        寫進任何 header。此時請維持 REMOTE_ADDR —— 選一個沒人在寫的 header，
                        等於讓任何人送一個假的就決定自己被記成哪個 IP，登入速率限制與封鎖都會失效。
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-2 leading-relaxed">
                        標示「可偽造」的模式讀的是單一值的 header，沒有「由右數第 N 段」那道防線
                        （那只對 X-Forwarded-For 成立）。應用層無法分辨那個值是前端設備寫的、
                        還是請求方自己塞的 —— 只有在確認前端設備會<strong>覆寫</strong>（而非附加）該欄位時才可選用。
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- 儲存按鈕 -->
    <div class="flex justify-end">
        <button type="submit"
                class="bg-blue-600 text-white px-6 py-2.5 rounded-lg font-medium text-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-brand-light focus:ring-offset-2 dark:focus:ring-offset-surface-dark transition shadow-sm">
            儲存設定
        </button>
    </div>
</form>

<?php /* 電子發票連線測試：獨立 form，只在「電子發票」頁簽顯示。 */ ?>
<div x-show="tab === <?= e(json_encode('電子發票', JSON_UNESCAPED_UNICODE)) ?>" x-cloak
     class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden mt-8">
    <div class="px-6 py-4 bg-slate-50 dark:bg-navy-topbar border-b border-slate-200 dark:border-surface-border flex items-center gap-3">
        <svg class="w-5 h-5 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100">測試 PayNow 連線</h3>
    </div>
    <div class="p-6" x-data="{ testing: false, result: null }">
        <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">
            先於上方填妥 JWT Token 並按「儲存設定」，再以此驗證與 PayNow 的連線是否正常。
        </p>
        <div class="flex items-center gap-3">
            <button type="button"
                    :disabled="testing"
                    @click="YsEinvoiceSettings.test($data, document.querySelector('meta[name=csrf-token]')?.content || '')"
                    class="bg-blue-600 text-white px-5 py-2.5 rounded-lg font-medium text-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-brand-light transition shadow-sm whitespace-nowrap disabled:opacity-50">
                <span x-show="!testing">測試連線</span>
                <span x-show="testing" x-cloak>測試中…</span>
            </button>
            <div role="status" aria-live="polite" aria-atomic="true" class="text-sm font-medium">
                <span :class="result?.success ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                      x-text="testing ? '測試中…' : (result?.message || '')"></span>
                <template x-if="result?.href">
                    <a :href="result.href" class="underline ml-2" x-text="result.action === 'login' ? '重新登入' : '重新驗證身分'"></a>
                </template>
                <template x-if="result?.action === 'refresh'">
                    <button type="button" @click="window.location.reload()" class="underline ml-2">重新整理</button>
                </template>
            </div>
        </div>
    </div>
</div>

<?php /* SMTP 測試信：獨立 form（HTML 不允許巢狀 form），
         但只在「通知」頁簽顯示 —— 測試與設定放在一起才找得到。 */ ?>
<div x-show="tab === <?= e(json_encode('通知', JSON_UNESCAPED_UNICODE)) ?>" x-cloak
     class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden mt-8">
    <div class="px-6 py-4 bg-slate-50 dark:bg-navy-topbar border-b border-slate-200 dark:border-surface-border flex items-center gap-3">
        <svg class="w-5 h-5 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
        </svg>
        <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100">寄送測試信</h3>
    </div>
    <div class="p-6">
        <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">
            先於上方填妥 SMTP 並按「儲存設定」，再以此驗證寄信是否正常。未設定 SMTP 時會回報提示，不影響系統運作。
        </p>
        <form method="POST" action="/admin/settings/test-email" class="flex flex-wrap items-end gap-3">
            <?= \YangSheep\CRM\Core\Csrf::field() ?>
            <div class="flex-1 min-w-[240px]">
                <label for="test_email_to" class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">測試收件信箱（留空則用通知信箱）</label>
                <input type="email" id="test_email_to" name="test_email_to"
                       placeholder="you@example.com"
                       class="<?= $inputClass ?>">
            </div>
            <button type="submit"
                    class="bg-blue-600 text-white px-5 py-2.5 rounded-lg font-medium text-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-brand-light transition shadow-sm whitespace-nowrap">
                寄送測試信
            </button>
        </form>
    </div>
</div>

</div>
