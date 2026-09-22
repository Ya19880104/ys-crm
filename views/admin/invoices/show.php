<?php
/**
 * 電子發票詳情。
 *
 * 【這一頁要回答的三個問題】
 *   1. 這張發票現在什麼狀態？還會不會自己動？
 *   2. 如果失敗了，為什麼？（PayNow 的 422 會逐欄列出錯誤，全在 API 紀錄裡）
 *   3. 我現在能做什麼？（開立 / 重試 / 作廢 / 重開，且每個都寫清楚後果）
 *
 * 深藍雙主題、嚴禁綠色。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $invoice
 * @var array  $snapshot      開立當下的資料快照
 * @var array  $logs          這張發票的 API 往來紀錄
 * @var array  $statusLabels
 * @var bool   $isExhausted
 * @var bool   $retryExhausted
 * @var int    $maxRetries
 * @var bool   $canIssue
 * @var bool   $canCancel
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

$id       = (int) $invoice['id'];
$status   = (string) $invoice['status'];
$number   = (string) ($invoice['invoice_number'] ?? '');
$retries  = (int) ($invoice['retry_count'] ?? 0);
$nextTry  = $invoice['next_retry_at'] ?? null;
$stalled  = $status === 'abandoned';   // 系統已放棄，等人工介入（單一判準，見 InvoiceRepository::STATUS_ABANDONED）

$statusChip = [
    'pending'   => 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300',
    'scheduled' => 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300',
    'issuing'   => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
    'issued'    => 'bg-blue-600 text-white dark:bg-blue-600 dark:text-white',
    'failed'    => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
    'abandoned' => 'bg-red-600 text-white dark:bg-red-600 dark:text-white',
    'cancelled' => 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-300',
];

$fmtDt = static function (?string $d): string {
    $d = (string) ($d ?? '');
    return $d !== '' ? substr($d, 0, 19) : '—';
};


// 基本資料
$fields = [
    ['發票號碼',   $number !== '' ? $number : '—', true],
    ['隨機碼',     (string) ($invoice['random_code'] ?? '') ?: '—', true],
    ['發票日期',   (string) ($invoice['invoice_date'] ?? '') ?: '—', true],
    ['訂單編號',   (string) ($invoice['order_no'] ?? '—'), true],
    ['總金額',     number_format((float) ($invoice['total_amount'] ?? 0), 0), false],
    ['稅額',       number_format((float) ($invoice['tax_amount'] ?? 0), 0), false],
    ['開立方式',   ((string) ($invoice['issue_type'] ?? '') === 'manual' ? '手動' : '自動'), false],
    ['建立時間',   $fmtDt($invoice['created_at'] ?? null), true],
    ['開立時間',   $fmtDt($invoice['issued_at'] ?? null), true],
    ['作廢時間',   $fmtDt($invoice['cancelled_at'] ?? null), true],
];

$invoiceSummary = \YangSheep\CRM\EInvoice\InvoiceDisplay::summary($snapshot);
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

    <!-- 左：狀態 + 基本資料 -->
    <div class="lg:col-span-2 space-y-5">

        <?php if ($stalled): ?>
        <!-- 這是整頁最重要的一塊：說清楚「系統已經放棄了，在等你」 -->
        <div class="rounded-xl border border-red-200 dark:border-red-500/30 bg-red-50 dark:bg-red-500/10 px-4 py-3.5">
            <p class="text-sm text-red-900 dark:text-red-200 font-medium mb-1">這張發票不會再自動重試，需要人工處理。</p>
            <p class="text-xs text-red-800 dark:text-red-300 leading-relaxed">
                <?php if ($retryExhausted): ?>
                    已連續失敗 <?= $retries ?> 次（上限 <?= (int) $maxRetries ?> 次），系統停止自動重試。
                <?php else: ?>
                    這一筆在送出前就被本站的資料檢查擋下，或對方回覆了終局拒絕。
                    請先看下方「API 紀錄」確認 —— 若完全沒有 API 紀錄，代表根本沒送出去，
                    問題在發票資料本身。
                <?php endif; ?>
                請先看下方「API 紀錄」找出實際原因（PayNow 的 422 會逐欄列出哪個欄位不合法），
                修正後再按「重試開立」。
            </p>
        </div>
        <?php endif; ?>

        <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-5">
            <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mb-1">發票號碼</p>
                    <p class="text-xl font-mono font-semibold text-slate-800 dark:text-slate-100">
                        <?= e($number !== '' ? $number : '尚未開立') ?>
                    </p>
                </div>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?= $statusChip[$status] ?? $statusChip['pending'] ?>">
                    <?= e($statusLabels[$status] ?? $status) ?>
                </span>
            </div>

            <dl class="grid grid-cols-2 gap-x-6 gap-y-3">
                <?php foreach ($fields as [$label, $value, $mono]): ?>
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400"><?= e($label) ?></dt>
                    <dd class="text-sm text-slate-800 dark:text-slate-100 <?= $mono ? 'font-mono' : '' ?>"><?= e($value) ?></dd>
                </div>
                <?php endforeach; ?>

                <?php if (($invoice['payment_id'] ?? null)): ?>
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400">付款紀錄</dt>
                    <dd class="text-sm">
                        <a href="/admin/payments/<?= (int) $invoice['payment_id'] ?>"
                           class="font-mono text-blue-600 dark:text-blue-400 hover:underline">檢視付款</a>
                    </dd>
                </div>
                <?php endif; ?>
            </dl>
        </div>

        <?php $view->partial('invoice-profile', [
            'summary' => $invoiceSummary,
            'provenance' => $snapshot !== []
                ? '開立請求快照；不隨客戶主檔修改。是否成功開立請以上方狀態為準。'
                : '沒有可讀取的開立快照；不以目前客戶資料代替歷史內容。',
        ]); ?>

        <?php if ((string) ($invoice['last_error'] ?? '') !== ''): ?>
        <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-5">
            <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100 mb-3">最後一次錯誤</h3>
            <pre class="text-xs font-mono text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-500/10 rounded-lg p-3 whitespace-pre-wrap break-all"><?= e((string) $invoice['last_error']) ?></pre>
        </div>
        <?php endif; ?>

        <!-- API 往來紀錄：診斷 422 的唯一依據 -->
        <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-200 dark:border-surface-border">
                <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100">API 紀錄</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    PayNow 回 422 時會在回應中逐欄列出哪個欄位不合法 —— 那是查修的起點。
                </p>
            </div>

            <?php if (empty($logs)): ?>
            <p class="px-5 py-10 text-center text-sm text-slate-400">尚無 API 往來紀錄</p>
            <?php else: ?>
            <div class="divide-y divide-slate-100 dark:divide-surface-border">
                <?php foreach ($logs as $log): ?>
                <?php $ok = (int) ($log['success'] ?? 0) === 1; ?>
                <details class="group">
                    <summary class="px-5 py-3 flex items-center gap-3 cursor-pointer hover:bg-slate-50 dark:hover:bg-white/5 transition">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= $ok
                            ? 'bg-blue-600 text-white'
                            : 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300' ?>">
                            <?= $ok ? '成功' : '失敗' ?>
                        </span>
                        <span class="text-sm font-medium text-slate-700 dark:text-slate-200"><?= e((string) ($log['operation'] ?? '')) ?></span>
                        <span class="text-xs font-mono text-slate-400"><?= e($fmtDt($log['created_at'] ?? null)) ?></span>
                        <?php if ((string) ($log['http_status'] ?? '') !== ''): ?>
                        <span class="text-xs font-mono text-slate-500 dark:text-slate-400">HTTP <?= e((string) $log['http_status']) ?></span>
                        <?php endif; ?>
                    </summary>
                    <div class="px-5 pb-4 space-y-3">
                        <?php foreach (['request_payload' => '送出', 'response_payload' => '回應'] as $key => $label): ?>
                        <?php $body = (string) ($log[$key] ?? ''); ?>
                        <?php if ($body !== ''): ?>
                        <div>
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 mb-1"><?= e($label) ?></p>
                            <pre class="text-xs font-mono text-slate-700 dark:text-slate-300 bg-slate-50 dark:bg-white/5 rounded-lg p-3 overflow-x-auto"><?php
                                $decoded = json_decode($body, true);
                                echo e(is_array($decoded)
                                    ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                    : $body);
                            ?></pre>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 右：操作 -->
    <div class="space-y-5">
        <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-5">
            <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100 mb-1">操作</h3>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
                以下每個動作都會產生或改變<strong>國稅局留存的法定憑證</strong>，並寫入稽核紀錄。
            </p>

            <?php if (in_array($status, ['failed', 'abandoned', 'pending', 'scheduled'], true)): ?>
                <?php if ($canIssue): ?>
                <form method="POST" action="/admin/invoices/<?= $id ?>/issue" class="mb-3"
                      onsubmit="return confirm('確定要開立這張發票？將立即向 PayNow 送出，成功後會產生正式發票號碼。')">
                    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                    <button type="submit"
                            class="w-full px-4 py-2.5 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">
                        <?= in_array($status, ['failed', 'abandoned'], true) ? '重試開立' : '立即開立' ?>
                    </button>
                </form>
                <?php if (in_array($status, ['failed', 'abandoned'], true)): ?>
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-4 leading-relaxed">
                    已重試 <?= $retries ?> 次<?= $nextTry !== null ? '，下次自動重試：' . e($fmtDt($nextTry)) : '' ?>。
                    若失敗原因是發票資料錯誤，請先到客戶或報價單修正資料再重試。
                </p>
                <?php endif; ?>
                <?php else: ?>
                <p class="text-xs text-slate-400 mb-3">您沒有開立發票的權限（需 invoice.issue）。</p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($status === 'issued'): ?>
                <form method="POST" action="/admin/invoices/<?= $id ?>/print-url" target="_blank" class="mb-3">
                    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                    <button type="submit"
                            class="w-full px-4 py-2.5 border border-blue-300 dark:border-blue-500/40 text-blue-700 dark:text-blue-300 rounded-lg text-sm font-medium hover:bg-blue-50 dark:hover:bg-blue-500/10 transition">
                        查看官方發票
                    </button>
                </form>
                <?php if ($canCancel): ?>
                <form method="POST" action="/admin/invoices/<?= $id ?>/cancel" class="mb-3"
                      onsubmit="return confirm('確定要作廢發票 <?= e($number) ?>？\n\n作廢無法復原，國稅局會留下作廢紀錄，且這組號碼不能再使用。')">
                    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                    <button type="submit"
                            class="w-full px-4 py-2.5 border border-red-300 dark:border-red-500/40 text-red-700 dark:text-red-300 rounded-lg text-sm font-medium hover:bg-red-50 dark:hover:bg-red-500/10 transition">
                        作廢發票
                    </button>
                </form>

                <form method="POST" action="/admin/invoices/<?= $id ?>/reissue" class="mb-2"
                      onsubmit="return confirm('確定要作廢並重開？\n\n會先作廢 <?= e($number) ?>，再用新的訂單編號開立一張新發票。適用於抬頭、統編或載具開錯的情況。')">
                    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                    <button type="submit"
                            class="w-full px-4 py-2.5 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 transition">
                        作廢並重開
                    </button>
                </form>
                <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                    重開用於「開錯了」：抬頭錯、統編錯、載具錯。
                    重開前請先修正客戶的發票資料，否則新的一張會錯得一模一樣。
                </p>
                <?php else: ?>
                <p class="text-xs text-slate-400">您沒有作廢發票的權限（需 invoice.cancel）。</p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($status === 'issuing'): ?>
            <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                正在開立中。若超過 30 分鐘仍停在此狀態，排程會自動回收並排入重試 ——
                這通常代表送出後程序被中斷（部署重啟、逾時）。
            </p>
            <?php endif; ?>

            <?php if ($status === 'cancelled'): ?>
            <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                這張發票已作廢。若要重新開立，請到對應的付款紀錄操作。
            </p>
            <?php endif; ?>
        </div>

        <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-5">
            <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100 mb-3">重試狀態</h3>
            <dl class="space-y-2">
                <div class="flex justify-between">
                    <dt class="text-xs text-slate-500 dark:text-slate-400">已重試次數</dt>
                    <dd class="text-sm font-mono text-slate-800 dark:text-slate-100"><?= $retries ?> / <?= (int) $maxRetries ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-xs text-slate-500 dark:text-slate-400">下次自動重試</dt>
                    <dd class="text-sm font-mono text-slate-800 dark:text-slate-100"><?= e($fmtDt($nextTry)) ?></dd>
                </div>
            </dl>
        </div>
    </div>
</div>
