<?php
/**
 * 電子發票列表。
 *
 * 【這一頁的重點不是「列出所有發票」】而是把「系統不會再自己處理、正在等人」的那幾張
 * 推到最前面。發票開立失敗是常態，真正危險的是**失敗了卻沒有人知道**。
 * 因此頁首第一個東西是「需人工處理」的張數，而不是總數。
 *
 * 深藍雙主題、嚴禁綠色：已開立用實心藍；失敗用 red；作廢用 red 淡底；處理中用 amber。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $invoices        每筆含 customer_name、payment_no
 * @var int    $total
 * @var int    $page
 * @var int    $perPage
 * @var int    $totalPages
 * @var array  $filters         status / keyword / date_from / date_to
 * @var bool   $needsAttention  是否為「需人工處理」檢視
 * @var array  $counts          status => 張數
 * @var int    $attentionCount
 * @var array  $statusLabels
 * @var bool   $moduleEnabled
 * @var bool   $isConfigured
 * @var int    $maxRetries
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

$curStatus  = (string) ($filters['status'] ?? '');
$curKeyword = (string) ($filters['keyword'] ?? '');
$curFrom    = (string) ($filters['date_from'] ?? '');
$curTo      = (string) ($filters['date_to'] ?? '');
$hasFilter  = $curStatus !== '' || $curKeyword !== '' || $curFrom !== '' || $curTo !== '';

// 狀態 badge 配色（嚴禁綠色）。
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
    return $d !== '' ? substr($d, 0, 16) : '—';
};
?>

<!-- 頁簽：發票 / API 紀錄 -->
<div class="ys-tabs">
    <span class="ys-tab is-active">發票</span>
    <a href="/admin/invoices/logs" class="ys-tab">API 紀錄</a>
</div>

<?php if (!$moduleEnabled): ?>
<!-- 模組未啟用：說清楚「現在會發生什麼」，而不是只說「未啟用」 -->
<div class="mb-5 rounded-xl border border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 px-4 py-3">
    <p class="text-sm text-amber-900 dark:text-amber-200">
        <strong>電子發票模組未啟用。</strong>
        付款成功後不會建立發票紀錄，也不會呼叫 PayNow。
        <a href="/admin/settings#einvoice" class="underline font-medium">前往系統設定啟用</a>
    </p>
</div>
<?php elseif (!$isConfigured): ?>
<div class="mb-5 rounded-xl border border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 px-4 py-3">
    <p class="text-sm text-amber-900 dark:text-amber-200">
        <strong>PayNow JWT Token 尚未設定。</strong>
        這段期間的付款仍會建立發票紀錄並標記為待重試，補上 Token 後由排程自動補開 —— 不會漏單。
        <a href="/admin/settings#einvoice" class="underline font-medium">前往填寫</a>
    </p>
</div>
<?php endif; ?>

<!-- 操作列 -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <p class="text-slate-500 dark:text-slate-400 text-sm">
        <?php if ($needsAttention): ?>
            共 <?= e((string) $total) ?> 張<strong class="text-red-600 dark:text-red-300">需人工處理</strong>的發票
        <?php else: ?>
            共 <?= e((string) $total) ?> 張發票
        <?php endif; ?>
    </p>

    <?php if ($attentionCount > 0): ?>
    <a href="<?= $needsAttention ? '/admin/invoices' : '/admin/invoices?attention=1' ?>"
       class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-sm font-medium transition <?= $needsAttention
           ? 'bg-slate-800 dark:bg-white/10 text-white hover:bg-slate-900 dark:hover:bg-white/20'
           : 'bg-red-600 text-white hover:bg-red-700' ?>">
        <?php if ($needsAttention): ?>
            顯示全部發票
        <?php else: ?>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0l-7.1 12.25A2 2 0 004.99 19z"/>
            </svg>
            <?= e((string) $attentionCount) ?> 張等待人工處理
        <?php endif; ?>
    </a>
    <?php endif; ?>
</div>

<?php if (!$needsAttention): ?>
<!-- 篩選列 -->
<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-4 mb-5">
    <form method="GET" action="/admin/invoices" class="ys-toolbar flex flex-wrap items-end gap-3">
        <div class="min-w-[160px]">
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">狀態</label>
            <select name="status"
                    class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="">全部狀態</option>
                <?php foreach ($statusLabels as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= $curStatus === $code ? 'selected' : '' ?>>
                    <?= e($label) ?><?= isset($counts[$code]) ? '（' . (int) $counts[$code] . '）' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">建立日期</label>
            <div class="flex items-center gap-1.5">
                <input type="date" name="date_from" value="<?= e($curFrom) ?>" max="<?= e(date('Y-m-d')) ?>"
                       aria-label="起始日期" class="px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <span class="text-slate-400 text-sm">～</span>
                <input type="date" name="date_to" value="<?= e($curTo) ?>" max="<?= e(date('Y-m-d')) ?>"
                       aria-label="結束日期" class="px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
        </div>

        <div class="min-w-[200px] flex-1">
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">搜尋（發票號碼 / 訂單編號 / 客戶）</label>
            <input type="text" name="keyword" value="<?= e($curKeyword) ?>" maxlength="100"
                   placeholder="DP24824308 或 PAY-2026-0001"
                   class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div class="flex items-center gap-2">
            <button type="submit"
                    class="px-4 py-2 bg-slate-800 dark:bg-white/10 text-white rounded-lg text-sm font-medium hover:bg-slate-900 dark:hover:bg-white/20 transition">
                篩選
            </button>
            <?php if ($hasFilter): ?>
            <a href="/admin/invoices" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400">清除</a>
            <?php endif; ?>
        </div>

        <p class="w-full text-xs text-slate-500 dark:text-slate-400 mt-1">
            日期依<strong>建立時間</strong>篩選，而非開立時間 ——
            未開立與開立失敗的發票沒有開立時間，用開立時間當條件會讓最需要處理的那幾張消失。
            區間含起訖兩天。
        </p>
    </form>
</div>
<?php endif; ?>

<!-- 列表 -->
<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-sm whitespace-nowrap">
        <thead class="bg-slate-50 dark:bg-white/5 border-b border-slate-200 dark:border-surface-border">
            <tr>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">發票號碼</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">訂單編號</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">客戶</th>
                <th class="text-right px-4 py-3 font-medium text-slate-500 dark:text-slate-400">金額</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">狀態</th>
                <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">開立時間</th>
                <th class="text-right px-4 py-3 font-medium text-slate-500 dark:text-slate-400">操作</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
            <?php if (empty($invoices)): ?>
            <tr>
                <td colspan="7" class="px-4 py-12 text-center text-slate-400">
                    <?= $needsAttention ? '沒有等待人工處理的發票。' : '尚無發票紀錄' ?>
                </td>
            </tr>
            <?php else: ?>
                <?php foreach ($invoices as $inv): ?>
                <?php
                $iid     = (int) $inv['id'];
                $status  = (string) $inv['status'];
                $stalled = $status === 'abandoned';
                ?>
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5 transition">
                    <td class="px-4 py-3">
                        <a href="/admin/invoices/<?= $iid ?>" class="font-mono text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline">
                            <?= e(($inv['invoice_number'] ?? '') !== '' ? (string) $inv['invoice_number'] : '#' . $iid) ?>
                        </a>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300"><?= e((string) ($inv['order_no'] ?? '—')) ?></td>
                    <td class="px-4 py-3">
                        <?php if (($inv['customer_id'] ?? null) && ($inv['customer_name'] ?? '') !== ''): ?>
                            <a href="/admin/customers/<?= (int) $inv['customer_id'] ?>" class="text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400">
                                <?= e((string) $inv['customer_name']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-slate-400">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right font-medium text-slate-800 dark:text-slate-100 tabular-nums">
                        <?= e(number_format((float) ($inv['total_amount'] ?? 0), 0)) ?>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $statusChip[$status] ?? $statusChip['pending'] ?>">
                            <?= e($statusLabels[$status] ?? $status) ?>
                        </span>
                        <?php if ($status === 'failed'): ?>
                        <span class="ml-1.5 text-xs text-slate-400">
                            第 <?= (int) ($inv['retry_count'] ?? 0) ?>/<?= (int) $maxRetries ?> 次
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300 font-mono text-xs"><?= e($fmtDt($inv['issued_at'] ?? null)) ?></td>
                    <td class="px-4 py-3 text-right">
                        <a href="/admin/invoices/<?= $iid ?>" class="text-slate-500 dark:text-slate-400 hover:text-slate-700 text-xs font-medium">檢視</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- 分頁（保留篩選參數） -->
<?php if ($totalPages > 1): ?>
    <?php
    $pageBase = array_filter([
        'status'    => $needsAttention ? '' : $curStatus,
        'keyword'   => $needsAttention ? '' : $curKeyword,
        'date_from' => $needsAttention ? '' : $curFrom,
        'date_to'   => $needsAttention ? '' : $curTo,
        'attention' => $needsAttention ? '1' : '',
    ], static fn ($v) => $v !== '');
    $baseUrl = '/admin/invoices' . ($pageBase ? '?' . http_build_query($pageBase) : '');
    ?>
    <?php $view->partial('pagination', [
        'page'       => $page,
        'totalPages' => $totalPages,
        'baseUrl'    => $baseUrl,
    ]); ?>
<?php endif; ?>
