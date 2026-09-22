<?php
/**
 * 報價單後台檢視（對應架構設計 §7.8）。
 * 報價全貌 + 公開連結（visibility≠private）+ 瀏覽軌跡 + 簽署狀態 + 狀態動作。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $quote          含 items / views / view_count / signatures
 * @var array  $company        我方公司資訊（name/tax_id/address/phone/email/contact/seal/logo）
 * @var string|null $publicUrl 公開報價 URL（private 為 null）
 * @var array  $shareView      匿名分享狀態（QuoteService::shareView）
 * @var ?array $shareInput     分享設定驗證失敗重新顯示時，使用者剛才送出的值
 * @var ?array $shareError     {field, message}
 * @var array  $statusLabels
 * @var array  $visLabels
 * @var bool   $canEdit
 * @var bool   $canDelete
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

$qid     = (int) $quote['id'];
$status  = (string) $quote['status'];
$vis     = (string) $quote['visibility'];
$cur     = (string) ($quote['currency'] ?? 'TWD');
$items   = $quote['items'] ?? [];
$views   = $quote['views'] ?? [];
$sigs    = $quote['signatures'] ?? [];
$isLocked = in_array($status, ['signed', 'paid'], true);

$statusChip = [
    'draft'   => 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300',
    'sent'    => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
    'viewed'  => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300',
    'signed'  => 'bg-blue-600 text-white dark:bg-blue-600 dark:text-white',
    'paid'    => 'bg-blue-800 text-white dark:bg-blue-700 dark:text-white',
    'expired' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    'void'    => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
];

$money = static fn ($v): string => number_format((float) $v, 0);
$fmtDate = static function (?string $d): string {
    $d = (string) ($d ?? '');
    return $d !== '' ? substr($d, 0, 10) : '—';
};
$fmtDt = static function (?string $d): string {
    $d = (string) ($d ?? '');
    return $d !== '' ? substr($d, 0, 16) : '—';
};
?>

<style>[x-cloak]{display:none!important;}</style>

<div x-data="{ copied: false }">
    <!-- 返回 -->
    <div class="mb-5">
        <a href="/admin/quotes" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            返回報價單列表
        </a>
    </div>

    <!-- 抬頭：編號 + 標題 + 狀態 + 操作 -->
    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6 mb-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="font-mono text-sm font-semibold text-blue-600 dark:text-blue-400"><?= e($quote['quote_number']) ?></span>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $statusChip[$status] ?? $statusChip['draft'] ?>"><?= e($statusLabels[$status] ?? $status) ?></span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-500 dark:bg-white/5 dark:text-slate-400"><?= e($visLabels[$vis] ?? $vis) ?></span>
                </div>
                <h2 class="text-xl font-bold text-slate-900 dark:text-slate-100 mt-2"><?= e($quote['title']) ?></h2>
                <p class="text-sm text-slate-400 mt-1">
                    建立於 <?= e($fmtDt($quote['created_at'] ?? '')) ?>
                    <?php if (($quote['created_by_name'] ?? '') !== ''): ?>· <?= e($quote['created_by_name']) ?><?php endif; ?>
                    · 瀏覽 <?= e((string) ($quote['view_count'] ?? 0)) ?> 次
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <?php if ($canEdit && !$isLocked): ?>
                <a href="/admin/quotes/<?= $qid ?>/edit"
                   class="px-4 py-2 text-sm border border-slate-300 dark:border-surface-border rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/5 transition">
                    編輯
                </a>
                <?php endif; ?>

                <?php if ($canEdit && in_array($status, ['draft', 'expired'], true)): ?>
                <form method="POST" action="/admin/quotes/<?= $qid ?>/send" class="inline">
                    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                    <button type="submit" class="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition">標記已送出</button>
                </form>
                <?php endif; ?>

                <!-- 變更狀態（作廢 / 標記已付款）：合法性由後端狀態機把關 -->
                <?php if ($canEdit): ?>
                <div x-data="{ open: false }" class="relative">
                    <button @click="open = !open" type="button"
                            class="px-4 py-2 text-sm border border-slate-300 dark:border-surface-border rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/5 transition flex items-center gap-1">
                        變更狀態
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open" @click.outside="open = false" x-cloak
                         class="absolute right-0 mt-1 w-44 bg-white dark:bg-surface-card border border-slate-200 dark:border-surface-border rounded-lg shadow-lg z-20 py-1">
                        <?php if ($status === 'signed'): ?>
                        <form method="POST" action="/admin/quotes/<?= $qid ?>/status">
                            <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                            <input type="hidden" name="status" value="paid">
                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-blue-700 dark:text-blue-300 hover:bg-slate-50 dark:hover:bg-white/5">標記為已付款</button>
                        </form>
                        <?php endif; ?>
                        <?php if (!in_array($status, ['void'], true)): ?>
                        <form method="POST" action="/admin/quotes/<?= $qid ?>/status"
                              onsubmit="return confirm('確定要將此報價單標記為作廢？')">
                            <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                            <input type="hidden" name="status" value="void">
                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-slate-50 dark:hover:bg-white/5">標記為作廢</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($canDelete): ?>
                <form method="POST" action="/admin/quotes/<?= $qid ?>/delete" class="inline"
                      onsubmit="return confirm('確定要刪除報價單 <?= e($quote['quote_number']) ?>？此操作將一併刪除明細、瀏覽軌跡與簽署紀錄，無法復原。')">
                    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                    <button type="submit" class="px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-lg font-medium transition">刪除</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isLocked): ?>
        <div class="mt-4 text-xs text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-white/5 rounded-lg px-3 py-2">
            此報價單已<?= $status === 'paid' ? '付款' : '簽署' ?>，內容已鎖定不可編輯，以保全簽署存證一致性。
        </div>
        <?php endif; ?>
    </div>

    <!-- 公開連結卡（visibility ≠ private） -->
    <?php if ($publicUrl !== null): ?>
    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-5 mb-5">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div class="min-w-0">
                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">公開連結</h3>
                <p class="text-xs text-slate-400">
                    <?php if ($vis === 'password'): ?>需密碼檢視。<?php elseif ($vis === 'customer_only'): ?>須客戶登入檢視（客戶專區建置中）。<?php else: ?>任何取得連結者皆可檢視。<?php endif; ?>
                </p>
            </div>
            <div class="ys-toolbar flex items-center gap-2 min-w-0 flex-1 sm:flex-none">
                <input type="text" readonly value="<?= e($publicUrl) ?>" x-ref="publicUrl"
                       class="flex-1 sm:w-80 px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-300 rounded-lg text-xs font-mono">
                <button type="button"
                        @click="navigator.clipboard.writeText($refs.publicUrl.value); copied = true; setTimeout(() => copied = false, 1500)"
                        class="px-3 py-2 text-sm bg-slate-800 dark:bg-white/10 text-white rounded-lg font-medium hover:bg-slate-900 dark:hover:bg-white/20 transition whitespace-nowrap">
                    <span x-show="!copied">複製</span>
                    <span x-show="copied" x-cloak>已複製 ✓</span>
                </button>
                <a href="<?= e($publicUrl) ?>" target="_blank" rel="noopener"
                   class="px-3 py-2 text-sm border border-slate-300 dark:border-surface-border rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/5 transition whitespace-nowrap">開啟 ↗</a>

                <?php /* 列印走專用頁（/print）：獨立 A4 版面，自動觸發 window.print()。
                         後台頁雖然也有基本列印樣式，但版面是為螢幕設計的，
                         不適合當成交付給客戶的文件。 */ ?>
                <a href="<?= e($publicUrl) ?>/print" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-1.5 px-3 py-2 text-sm text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                    </svg>
                    列印報價單
                </a>

                <a href="<?= e($publicUrl) ?>/pdf"
                   class="inline-flex items-center gap-1.5 px-3 py-2 text-sm border border-slate-300 dark:border-surface-border rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/5 transition whitespace-nowrap">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/>
                    </svg>
                    下載 PDF
                </a>
            </div>
        </div>

        <?php /* 匿名分享（public／password）的期限狀態與就地設定（Q3）。
                 已簽署／已付款的報價不能再編輯內容，但仍可在這裡延長、關閉或重新公開——
                 「等客戶付款」正是這個階段。唯讀者只看狀態。 */ ?>
        <?php if (in_array($vis, ['public', 'password'], true)):
            $sv      = is_array($shareView ?? null) ? $shareView : [];
            $svState = (string) ($sv['state'] ?? '');
            $svTz    = (string) ($sv['timezone'] ?? '');
            $svOpen  = !empty($shareError);
        ?>
        <div id="share-status" class="mt-4 pt-4 border-t border-slate-200 dark:border-surface-border">
            <h4 class="text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1.5">分享狀態</h4>
            <?php if ($svState === 'active' && !empty($sv['auto_expire'])): ?>
            <p class="text-sm text-slate-700 dark:text-slate-200 leading-relaxed">
                <span class="inline-block rounded px-1.5 py-0.5 text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200">公開中</span>
                將於 <span class="font-medium tabular-nums"><?= e((string) ($sv['expires_at_label'] ?? '')) ?></span>（<?= e($svTz) ?>）自動關閉
                <span class="block text-xs text-slate-400 mt-0.5">自 <?= e((string) ($sv['enabled_at_label'] ?? '')) ?> 公開起算 <?= (int) ($sv['days'] ?? 0) ?> 天；瀏覽、重寄或修改內容都不會延長。</span>
            </p>
            <?php elseif ($svState === 'active'): ?>
            <p class="text-sm text-red-700 dark:text-red-300 leading-relaxed">
                <span class="font-semibold">⛔ 公開中｜未設定自動關閉</span>——連結會持續有效，直到手動關閉。
            </p>
            <?php elseif ($svState === 'expired'): ?>
            <p class="text-sm text-slate-600 dark:text-slate-300">已於 <span class="tabular-nums"><?= e((string) ($sv['expires_at_label'] ?? '')) ?></span>（<?= e($svTz) ?>）到期關閉，訪客目前看不到報價。</p>
            <?php elseif ($svState === 'closed'): ?>
            <p class="text-sm text-slate-600 dark:text-slate-300">已於 <span class="tabular-nums"><?= e((string) ($sv['closed_at_label'] ?? '')) ?></span>（<?= e($svTz) ?>）手動關閉，訪客目前看不到報價。</p>
            <?php else: ?>
            <p class="text-sm text-slate-600 dark:text-slate-300">尚未設定分享期限，訪客目前看不到報價。</p>
            <?php endif; ?>

            <?php if ($canEdit): ?>
            <?php if ($svState === 'active'): ?>
            <form method="POST" action="/admin/quotes/<?= $qid ?>/share/close" class="mt-3"
                  onsubmit="return confirm('確定立即關閉公開連結？訪客將無法再開啟；重新公開時會產生新連結。');">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                <button type="submit"
                        class="px-3 py-1.5 text-sm font-medium rounded-lg border border-red-300 dark:border-red-500/40 text-red-700 dark:text-red-300 hover:bg-red-50 dark:hover:bg-red-500/10 transition">
                    立即關閉連結
                </button>
            </form>
            <?php endif; ?>

            <details class="mt-3" <?= $svOpen ? 'open' : '' ?>>
                <summary class="cursor-pointer text-sm text-blue-600 dark:text-blue-400 hover:underline">修改分享設定</summary>
                <form method="POST" action="/admin/quotes/<?= $qid ?>/share" class="mt-3 max-w-md"
                      x-data="{ visibility: <?= e((string) json_encode($vis)) ?> }">
                    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                    <?php $view->partial('admin/quotes/_share_fields', [
                        'shareView'  => $sv,
                        'shareInput' => $shareInput ?? null,
                        'shareError' => $shareError ?? null,
                        'visibility' => $vis,
                    ]); ?>
                    <button type="submit"
                            class="px-4 py-2 text-sm font-medium rounded-lg bg-slate-800 dark:bg-white/10 text-white hover:bg-slate-900 dark:hover:bg-white/20 transition">
                        儲存分享設定
                    </button>
                </form>
            </details>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <!-- 報價內容（2 欄寬） -->
        <div class="lg:col-span-2 space-y-5">
            <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
                <!-- 抬頭：我方 / 客戶 -->
                <div class="flex flex-wrap justify-between gap-6 pb-5 border-b border-slate-200 dark:border-surface-border">
                    <div class="min-w-0">
                        <p class="text-xs text-slate-400 mb-1">報價方</p>
                        <p class="font-semibold text-slate-800 dark:text-slate-100"><?= e($company['name'] !== '' ? $company['name'] : 'YANGSHEEP DESIGN') ?></p>
                        <?php if ($company['tax_id'] !== ''): ?><p class="text-xs text-slate-500 dark:text-slate-400">統編：<?= e($company['tax_id']) ?></p><?php endif; ?>
                        <?php if ($company['address'] !== ''): ?><p class="text-xs text-slate-500 dark:text-slate-400"><?= e($company['address']) ?></p><?php endif; ?>
                        <?php if ($company['phone'] !== ''): ?><p class="text-xs text-slate-500 dark:text-slate-400">電話：<?= e($company['phone']) ?></p><?php endif; ?>
                    </div>
                    <div class="min-w-0 text-right">
                        <p class="text-xs text-slate-400 mb-1">報價對象</p>
                        <?php if (($quote['customer_name'] ?? '') !== ''): ?>
                            <p class="font-semibold text-slate-800 dark:text-slate-100"><?= e($quote['customer_name']) ?></p>
                            <?php if (($quote['customer_tax_id'] ?? '') !== ''): ?><p class="text-xs text-slate-500 dark:text-slate-400">統編：<?= e($quote['customer_tax_id']) ?></p><?php endif; ?>
                            <?php if (($quote['customer_address'] ?? '') !== ''): ?><p class="text-xs text-slate-500 dark:text-slate-400"><?= e($quote['customer_address']) ?></p><?php endif; ?>
                        <?php else: ?>
                            <p class="text-sm text-slate-400">未指定客戶</p>
                        <?php endif; ?>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">有效期限：<?= e($fmtDate($quote['valid_until'] ?? null)) ?></p>
                    </div>
                </div>

                <!-- 明細表 -->
                <div class="overflow-x-auto mt-5">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-surface-border">
                            <th class="text-left font-medium py-2">項目</th>
                            <th class="text-right font-medium py-2 px-2 w-20">數量</th>
                            <th class="text-left font-medium py-2 px-2 w-16">單位</th>
                            <th class="text-right font-medium py-2 px-2 w-28">單價</th>
                            <th class="text-right font-medium py-2 pl-2 w-28">金額</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
                        <?php if ($items === []): ?>
                        <tr><td colspan="5" class="py-6 text-center text-slate-400">無明細</td></tr>
                        <?php else: ?>
                            <?php foreach ($items as $it): ?>
                            <tr>
                                <td class="py-2.5">
                                    <span class="text-slate-800 dark:text-slate-100"><?= e($it['name']) ?></span>
                                    <?php if (($it['description'] ?? '') !== ''): ?>
                                    <span class="block text-xs text-slate-400 mt-0.5"><?= e($it['description']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2.5 px-2 text-right text-slate-600 dark:text-slate-300 tabular-nums"><?= e(rtrim(rtrim(number_format((float) $it['qty'], 2), '0'), '.')) ?></td>
                                <td class="py-2.5 px-2 text-slate-600 dark:text-slate-300"><?= e($it['unit']) ?></td>
                                <td class="py-2.5 px-2 text-right text-slate-600 dark:text-slate-300 tabular-nums"><?= e($money($it['unit_price'])) ?></td>
                                <td class="py-2.5 pl-2 text-right text-slate-800 dark:text-slate-100 font-medium tabular-nums"><?= e($money($it['amount'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>

                <!-- 合計 -->
                <div class="mt-5 flex justify-end">
                    <div class="w-full sm:w-64 space-y-2 text-sm">
                        <div class="flex justify-between text-slate-600 dark:text-slate-300"><span>小計</span><span class="tabular-nums"><?= e($money($quote['subtotal'])) ?></span></div>
                        <div class="flex justify-between text-slate-600 dark:text-slate-300"><span>稅額（<?= e(rtrim(rtrim(number_format((float) $quote['tax_rate'], 2), '0'), '.')) ?>%）</span><span class="tabular-nums"><?= e($money($quote['tax'])) ?></span></div>
                        <div class="flex justify-between pt-2 border-t border-slate-200 dark:border-surface-border text-base font-bold text-slate-900 dark:text-slate-100"><span>總計</span><span class="tabular-nums"><?= e($cur) ?> <?= e($money($quote['total'])) ?></span></div>
                    </div>
                </div>

                <!-- 條款 -->
                <?php if (($quote['terms'] ?? '') !== ''): ?>
                <div class="mt-6 pt-5 border-t border-slate-200 dark:border-surface-border">
                    <h4 class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">報價條款</h4>
                    <p class="text-sm text-slate-600 dark:text-slate-400 whitespace-pre-line leading-relaxed"><?= e($quote['terms']) ?></p>
                </div>
                <?php endif; ?>

                <!-- 內部備註 -->
                <?php if (($quote['notes'] ?? '') !== ''): ?>
                <div class="mt-5 bg-amber-50 dark:bg-amber-500/5 border border-amber-100 dark:border-amber-500/20 rounded-lg p-3">
                    <p class="text-xs font-medium text-amber-700 dark:text-amber-300 mb-1">內部備註（不顯示於公開頁）</p>
                    <p class="text-sm text-amber-800 dark:text-amber-200 whitespace-pre-line"><?= e($quote['notes']) ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 右：簽署狀態 + 瀏覽軌跡 -->
        <div class="space-y-5">
            <!-- 簽署狀態 -->
            <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-5">
                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-3">簽署狀態</h3>
                <?php if ($sigs === []): ?>
                    <div class="text-center py-4">
                        <p class="text-sm text-slate-400">尚未簽署</p>
                        <?php if ($vis !== 'private'): ?>
                        <p class="text-xs text-slate-400 mt-1">客戶可於公開頁線上簽署。</p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($sigs as $sig): ?>
                        <div class="border border-slate-200 dark:border-surface-border rounded-lg p-3">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-medium text-slate-800 dark:text-slate-100"><?= e($sig['signer_name']) ?></span>
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300">
                                    <?= $sig['signer_type'] === 'customer' ? '客戶' : '訪客' ?>
                                </span>
                            </div>
                            <!-- 簽名圖 + 我方印章 -->
                            <div class="flex items-center gap-3 mb-2">
                                <?php if (($sig['signature_image_path'] ?? '') !== ''): ?>
                                <div class="flex-1 min-w-0">
                                    <p class="text-[11px] text-slate-400 mb-1">客戶簽名</p>
                                    <img src="<?= e($sig['signature_image_path']) ?>" alt="簽名" class="h-16 bg-white rounded border border-slate-200 dark:border-surface-border object-contain w-full">
                                </div>
                                <?php endif; ?>
                                <?php if (($sig['our_seal_path'] ?? '') !== ''): ?>
                                <div class="flex-shrink-0">
                                    <p class="text-[11px] text-slate-400 mb-1">我方用印</p>
                                    <img src="<?= e($sig['our_seal_path']) ?>" alt="公司印章" class="h-16 w-16 object-contain">
                                </div>
                                <?php endif; ?>
                            </div>
                            <dl class="text-[11px] text-slate-500 dark:text-slate-400 space-y-0.5">
                                <div class="flex justify-between gap-2"><dt>簽署時間</dt><dd class="text-right"><?= e($fmtDt($sig['signed_at'] ?? '')) ?></dd></div>
                                <div class="flex justify-between gap-2"><dt>來源 IP</dt><dd class="text-right font-mono"><?= e($sig['signed_ip'] ?? '') ?></dd></div>
                                <div class="pt-1">
                                    <dt class="mb-0.5">內容雜湊（存證）</dt>
                                    <dd class="font-mono text-[10px] break-all text-slate-400 leading-tight"><?= e($sig['document_hash'] ?? '') ?></dd>
                                </div>
                            </dl>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- 付款狀態（§7.9；payment_enabled 時顯示） -->
            <?php if ((int) ($quote['payment_enabled'] ?? 0) === 1):
                $pay = $payment ?? null;
                $payStatus = is_array($pay) ? (string) ($pay['status'] ?? '') : '';
                $payChip = [
                    'pending'   => 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300',
                    'paid'      => 'bg-blue-600 text-white dark:bg-blue-600 dark:text-white',
                    'failed'    => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
                    'cancelled' => 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-300',
                    'refunded'  => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300',
                ];
            ?>
            <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-5">
                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-3">付款狀態</h3>
                <?php if (is_array($pay)): ?>
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <a href="/admin/payments/<?= (int) $pay['id'] ?>" class="font-mono text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline"><?= e($pay['payment_no']) ?></a>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $payChip[$payStatus] ?? $payChip['pending'] ?>">
                            <?= e($paymentStatusLabels[$payStatus] ?? $payStatus) ?>
                        </span>
                    </div>
                    <dl class="text-[11px] text-slate-500 dark:text-slate-400 space-y-0.5">
                        <div class="flex justify-between gap-2"><dt>金額</dt><dd class="text-right tabular-nums"><?= e((string) ($pay['currency'] ?? 'TWD')) ?> <?= e($money($pay['amount'] ?? 0)) ?></dd></div>
                        <div class="flex justify-between gap-2"><dt>金流商</dt><dd class="text-right"><?= e((string) ($pay['provider'] ?? '')) ?></dd></div>
                        <?php if (($pay['paid_at'] ?? '') !== ''): ?>
                        <div class="flex justify-between gap-2"><dt>付款時間</dt><dd class="text-right font-mono"><?= e($fmtDt($pay['paid_at'] ?? '')) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                    <a href="/admin/payments/<?= (int) $pay['id'] ?>" class="inline-block mt-2 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-700">查看付款詳情 →</a>
                <?php else: ?>
                    <p class="text-sm text-slate-400 text-center py-3">已啟用線上付款，尚無付款紀錄。</p>
                    <p class="text-xs text-slate-400 text-center">客戶簽署後可於公開頁前往付款。</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- 瀏覽軌跡 -->
            <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-5">
                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-3">瀏覽軌跡（<?= e((string) ($quote['view_count'] ?? 0)) ?>）</h3>
                <?php if ($views === []): ?>
                    <p class="text-sm text-slate-400 text-center py-4">尚無瀏覽紀錄</p>
                <?php else: ?>
                    <div class="space-y-2 max-h-80 overflow-y-auto">
                        <?php foreach ($views as $v): ?>
                        <div class="flex items-center justify-between gap-2 text-xs py-1.5 border-b border-slate-100 dark:border-surface-border last:border-0">
                            <span class="text-slate-600 dark:text-slate-300 font-mono"><?= e($fmtDt($v['viewed_at'] ?? '')) ?></span>
                            <span class="text-slate-400 font-mono truncate" title="<?= e((string) ($v['user_agent'] ?? '')) ?>"><?= e($v['ip'] ?? '') ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
