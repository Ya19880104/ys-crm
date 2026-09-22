<?php
/**
 * 付款詳情（對應架構設計 §7.9）。
 * 付款全貌 + 關聯報價/客戶 + 原始請求/回呼 + 手動標記已付款。
 *
 * 深藍雙主題、嚴禁綠色：已付款用實心藍。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $payment         含 quote_number / quote_title / customer_name / raw_request / raw_callback
 * @var array  $statusLabels
 * @var array  $providerLabels
 * @var bool   $canManage
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

$pid      = (int) $payment['id'];
$status   = (string) $payment['status'];
$provider = (string) $payment['provider'];
$cur      = (string) ($payment['currency'] ?? 'TWD');

$statusChip = [
    'pending'            => 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300',
    'paid'               => 'bg-blue-600 text-white dark:bg-blue-600 dark:text-white',
    'failed'             => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
    'cancelled'          => 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-300',
    'partially_refunded' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
    'refunded'           => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300',
];

// 退款餘額：部分退款後仍可繼續退，UI 必須以「可退餘額」為準而非原付款金額。
$paidAmount      = (int) round((float) ($payment['amount'] ?? 0));
$refundedAmount  = (int) round((float) ($payment['refunded_amount'] ?? 0));
$refundableLeft  = max(0, $paidAmount - $refundedAmount);
$refundLocked    = trim((string) ($payment['refund_claim_token'] ?? '')) !== '';

$money = static fn ($v): string => number_format((float) $v, 0);
$fmtDt = static function (?string $d): string {
    $d = (string) ($d ?? '');
    return $d !== '' ? substr($d, 0, 19) : '—';
};

// 美化 JSON 原始內容（請求 / 回呼）。
$prettyJson = static function (?string $raw): ?string {
    $raw = (string) ($raw ?? '');
    if ($raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if ($decoded === null) {
        return $raw; // 非 JSON 原樣顯示
    }
    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
$rawRequest  = $prettyJson($payment['raw_request'] ?? null);
$rawCallback = $prettyJson($payment['raw_callback'] ?? null);
?>

<div>
    <!-- 返回 -->
    <div class="mb-5">
        <a href="/admin/payments" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            返回付款記錄
        </a>
    </div>

    <!-- 抬頭：編號 + 狀態 + 操作 -->
    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6 mb-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="font-mono text-sm font-semibold text-blue-600 dark:text-blue-400"><?= e($payment['payment_no']) ?></span>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $statusChip[$status] ?? $statusChip['pending'] ?>"><?= e($statusLabels[$status] ?? $status) ?></span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-500 dark:bg-white/5 dark:text-slate-400"><?= e($providerLabels[$provider] ?? $provider) ?></span>
                </div>
                <h2 class="text-2xl font-bold text-slate-900 dark:text-slate-100 mt-2 tabular-nums"><?= e($cur) ?> <?= e($money($payment['amount'])) ?></h2>
                <p class="text-sm text-slate-400 mt-1">建立於 <?= e($fmtDt($payment['created_at'] ?? '')) ?></p>
            </div>

            <?php /* 電子發票：這一頁是操作者確認「錢收到了」的地方，
                     發票的下一步就該在這裡，而不是要人自己去發票列表找。

                     🔴 沒有這一塊之前，「已入帳的付款要開發票」在後台是做不到的 ——
                     除非事前打開「付款後自動開立」。已開的顯示連結與號碼，
                     沒開的顯示按鈕，狀態不明的顯示原因，三種都講得出來。 */ ?>
            <?php if (!empty($invoiceEnabled) && in_array($status, ['paid', 'partially_refunded'], true)): ?>
            <div class="flex items-center gap-2">
                <?php if (!empty($invoice)): ?>
                    <?php if (!empty($canViewInvoice)): ?>
                    <?php
                    $invStatus = (string) ($invoice['status'] ?? '');
                    $invNo     = trim((string) ($invoice['invoice_number'] ?? ''));
                    $invChip   = match ($invStatus) {
                        'issued'    => 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
                        'cancelled' => 'bg-slate-100 text-slate-500 dark:bg-white/10 dark:text-slate-400',
                        'abandoned' => 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300',
                        default     => 'bg-amber-50 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
                    };
                    ?>
                    <a href="/admin/invoices/<?= (int) $invoice['id'] ?>"
                       class="inline-flex items-center gap-2 px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-surface-border
                              text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-white/5 transition">
                        <span>發票</span>
                        <span class="font-mono text-xs"><?= e($invNo !== '' ? $invNo : '#' . (int) $invoice['id']) ?></span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= $invChip ?>">
                            <?= e(match ($invStatus) {
                                'issued'    => '已開立',
                                'cancelled' => '已作廢',
                                'abandoned' => '已放棄',
                                'issuing'   => '開立中',
                                'failed'    => '開立失敗',
                                default     => '待開立',
                            }) ?>
                        </span>
                    </a>
                    <?php else: ?>
                    <span class="text-sm text-slate-600 dark:text-slate-300">發票紀錄已建立（需發票檢視權限）</span>
                    <?php endif; ?>
                <?php elseif (!empty($canIssueInvoice)): ?>
                    <form method="POST" action="/admin/payments/<?= $pid ?>/issue-invoice"
                          onsubmit="return confirm('確定要為這筆付款開立電子發票？<?= ($invoiceEnv ?? '') === 'production' ? '這會開出具法律效力的發票，且開立後只能作廢不能刪除。' : '目前為測試環境，開出的發票不具法律效力。' ?>')">
                        <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                        <button type="submit"
                                class="px-4 py-2 text-sm rounded-lg border border-slate-300 dark:border-surface-border
                                       text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-white/5 transition">
                            開立電子發票<?= ($invoiceEnv ?? '') !== 'production' ? '（測試）' : '' ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- 手動標記已付款（僅 pending + 有權限） -->
            <?php if ($canManage && $status === 'pending'): ?>
            <form method="POST" action="/admin/payments/<?= $pid ?>/mark-paid"
                  onsubmit="return confirm('確定要手動將此付款標記為「已付款」？此操作將同步更新關聯報價狀態，且會寫入稽核紀錄。')">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                <button type="submit" class="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition">
                    手動標記為已付款
                </button>
            </form>
            <?php endif; ?>

            <?php
            // 退款：已入帳（含部分退款後仍有餘額）且金流商支援線上退款時顯示。
            //
            // 🔴 條件必須包含 partially_refunded。原本只判 status === 'paid'，
            // 造成第一次部分退款之後，剩餘金額在正常 UI 上再也退不了 ——
            // Service 明明支援，但按鈕消失了，只能下 SQL。
            //
            // 目前僅 PayUni 實作 RefundableProviderInterface；sandbox / SHOPLINE 不顯示此區，
            // 避免操作者以為按了就會退款（那類「看似可用」的按鈕是帳務事故的常見來源）。
            $refundableProviders = ['payuni'];
            $refundableStatus    = in_array($status, ['paid', 'partially_refunded'], true);
            $providerRefundable  = in_array($provider, $refundableProviders, true);
            $canRefund = $canManage && $refundableStatus && $providerRefundable
                && $refundableLeft > 0 && !$refundLocked;
            ?>
            <?php if ($canRefund): ?>
            <form method="POST" action="/admin/payments/<?= $pid ?>/refund"
                  onsubmit="return confirm('確定要對此筆付款發動退款？此操作會實際向金流商送出退款請求，且會寫入稽核紀錄。')"
                  class="flex flex-col items-end gap-1">
                <div class="flex items-center gap-2">
                    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                    <?php // F04 冪等識別字：本次 render 專屬。重送同一張表單＝同一個 id＝只退一次。 ?>
                    <input type="hidden" name="refund_request_id" value="<?= e(bin2hex(random_bytes(16))) ?>">
                    <label for="refund-amount" class="sr-only">退款金額</label>
                    <input type="number"
                           id="refund-amount"
                           name="amount"
                           min="1"
                           max="<?= $refundableLeft ?>"
                           step="1"
                           placeholder="全額"
                           title="留空為全額退款；部分退款請填金額"
                           class="w-24 px-3 py-2 text-sm border border-slate-300 dark:border-surface-border rounded-lg bg-white dark:bg-navy-topbar text-slate-800 dark:text-slate-100">
                    <button type="submit" class="px-4 py-2 text-sm bg-red-100 text-red-700 rounded-lg font-medium hover:bg-red-200 transition whitespace-nowrap">
                        退款
                    </button>
                </div>
                <?php if ($refundedAmount > 0): ?>
                <span class="text-xs text-slate-400 tabular-nums">
                    已退 <?= e($money($refundedAmount)) ?>／可退 <?= e($money($refundableLeft)) ?>
                </span>
                <?php endif; ?>
            </form>
            <?php elseif ($canManage && $refundableStatus && $providerRefundable && $refundLocked): ?>
            <span class="text-xs text-amber-700 dark:text-amber-300 max-w-[240px] text-right">
                此筆付款有一次退款結果未確認，已鎖定以避免重複退款。
                請至金流商後台確認實際結果後，依 <span class="font-mono">docs/GO-LIVE.md §7</span> 解除。
            </span>
            <?php elseif ($canManage && $refundableStatus && !$providerRefundable): ?>
            <span class="text-xs text-slate-400 max-w-[220px] text-right">
                此金流商不支援線上退款，請至金流商後台操作後再手動調整狀態。
            </span>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <!-- 左：付款資訊 + 原始內容 -->
        <div class="lg:col-span-2 space-y-5">
            <!-- 付款明細 -->
            <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-4">付款資訊</h3>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div class="flex justify-between gap-2 border-b border-slate-100 dark:border-surface-border pb-2">
                        <dt class="text-slate-400">付款編號</dt>
                        <dd class="font-mono text-slate-700 dark:text-slate-200"><?= e($payment['payment_no']) ?></dd>
                    </div>
                    <div class="flex justify-between gap-2 border-b border-slate-100 dark:border-surface-border pb-2">
                        <dt class="text-slate-400">金額</dt>
                        <dd class="font-medium text-slate-800 dark:text-slate-100 tabular-nums"><?= e($cur) ?> <?= e($money($payment['amount'])) ?></dd>
                    </div>
                    <div class="flex justify-between gap-2 border-b border-slate-100 dark:border-surface-border pb-2">
                        <dt class="text-slate-400">金流商</dt>
                        <dd class="text-slate-700 dark:text-slate-200"><?= e($providerLabels[$provider] ?? $provider) ?></dd>
                    </div>
                    <div class="flex justify-between gap-2 border-b border-slate-100 dark:border-surface-border pb-2">
                        <dt class="text-slate-400">付款方式</dt>
                        <dd class="text-slate-700 dark:text-slate-200"><?= e($payment['method'] ?? '—') ?></dd>
                    </div>
                    <div class="flex justify-between gap-2 border-b border-slate-100 dark:border-surface-border pb-2">
                        <dt class="text-slate-400">交易序號</dt>
                        <dd class="font-mono text-xs text-slate-700 dark:text-slate-200 break-all text-right"><?= e($payment['provider_txn_id'] ?? '—') ?></dd>
                    </div>
                    <div class="flex justify-between gap-2 border-b border-slate-100 dark:border-surface-border pb-2">
                        <dt class="text-slate-400">付款時間</dt>
                        <dd class="font-mono text-xs text-slate-700 dark:text-slate-200"><?= e($fmtDt($payment['paid_at'] ?? '')) ?></dd>
                    </div>
                    <div class="flex justify-between gap-2 border-b border-slate-100 dark:border-surface-border pb-2 sm:col-span-2">
                        <dt class="text-slate-400">冪等鍵</dt>
                        <dd class="font-mono text-xs text-slate-500 dark:text-slate-400 break-all text-right"><?= e($payment['idempotency_key'] ?? '—') ?></dd>
                    </div>
                </dl>
            </div>

            <?php if (($canViewInvoice ?? false) && is_array($invoiceSummary ?? null)): ?>
                <?php $view->partial('invoice-profile', ['summary' => $invoiceSummary, 'provenance' => $invoiceProvenance]); ?>
            <?php endif; ?>

            <!-- 原始請求 -->
            <?php if ($rawRequest !== null): ?>
            <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-3">送往金流商的請求（raw_request）</h3>
                <pre class="text-xs bg-slate-50 dark:bg-surface-dark border border-slate-200 dark:border-surface-border rounded-lg p-3 overflow-x-auto text-slate-600 dark:text-slate-300 leading-relaxed"><?= e($rawRequest) ?></pre>
            </div>
            <?php endif; ?>

            <!-- 原始回呼 -->
            <?php if ($rawCallback !== null): ?>
            <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-3">金流商回呼內容（raw_callback）</h3>
                <pre class="text-xs bg-slate-50 dark:bg-surface-dark border border-slate-200 dark:border-surface-border rounded-lg p-3 overflow-x-auto text-slate-600 dark:text-slate-300 leading-relaxed"><?= e($rawCallback) ?></pre>
            </div>
            <?php endif; ?>
        </div>

        <!-- 右：關聯 -->
        <div class="space-y-5">
            <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-5">
                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-3">關聯</h3>
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-slate-400 text-xs mb-1">報價單</dt>
                        <dd>
                            <?php if (($payment['quote_id'] ?? null) && ($payment['quote_number'] ?? '') !== ''): ?>
                                <a href="/admin/quotes/<?= (int) $payment['quote_id'] ?>" class="text-blue-600 dark:text-blue-400 hover:underline font-mono text-xs">
                                    <?= e($payment['quote_number']) ?>
                                </a>
                                <?php if (($payment['quote_title'] ?? '') !== ''): ?>
                                <span class="block text-xs text-slate-500 dark:text-slate-400 mt-0.5"><?= e($payment['quote_title']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-slate-400">—</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-400 text-xs mb-1">客戶</dt>
                        <dd>
                            <?php if (($payment['customer_id'] ?? null) && ($payment['customer_name'] ?? '') !== ''): ?>
                                <a href="/admin/customers/<?= (int) $payment['customer_id'] ?>" class="text-blue-600 dark:text-blue-400 hover:underline">
                                    <?= e($payment['customer_name']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-slate-400">—</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</div>
