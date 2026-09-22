<?php
/**
 * 電子發票 API 往來紀錄（全站）。
 *
 * 【何時看這一頁】「整批都開不出來」的時候。單張的紀錄在發票詳情頁就有，
 * 這一頁的用途是判斷「是這一張的資料有問題，還是整個通道壞了」——
 * 憑證過期、字軌用罄、對方系統維護，都會呈現為「最近所有呼叫都是同一個錯」。
 *
 * 🔒 JWT 在寫入時就已被遞迴遮蔽（PayNowRestClient::scrubToken），此處顯示原始
 * payload 不會外洩憑證。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var array  $logs
 * @var int    $total
 * @var int    $page
 * @var int    $perPage
 * @var int    $totalPages
 * @var array  $filters   operation / success / date_from / date_to
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

$curOp      = (string) ($filters['operation'] ?? '');
$curSuccess = (string) ($filters['success'] ?? '');
$curFrom    = (string) ($filters['date_from'] ?? '');
$curTo      = (string) ($filters['date_to'] ?? '');
$hasFilter  = $curOp !== '' || $curSuccess !== '' || $curFrom !== '' || $curTo !== '';

$operations = [
    'issue'  => '開立',
    'cancel' => '作廢',
    'query'  => '查詢',
];

$fmtDt = static function (?string $d): string {
    $d = (string) ($d ?? '');
    return $d !== '' ? substr($d, 0, 19) : '—';
};
?>

<!-- 頁簽 -->
<div class="ys-tabs">
    <a href="/admin/invoices" class="ys-tab">發票</a>
    <span class="ys-tab is-active">API 紀錄</span>
</div>

<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <p class="text-slate-500 dark:text-slate-400 text-sm">共 <?= e((string) $total) ?> 筆呼叫紀錄</p>
</div>

<!-- 篩選 -->
<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-4 mb-5">
    <form method="GET" action="/admin/invoices/logs" class="ys-toolbar flex flex-wrap items-end gap-3">
        <div class="min-w-[150px]">
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">操作</label>
            <select name="operation"
                    class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="">全部操作</option>
                <?php foreach ($operations as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= $curOp === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="min-w-[130px]">
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">結果</label>
            <select name="success"
                    class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="">全部</option>
                <option value="1" <?= $curSuccess === '1' ? 'selected' : '' ?>>成功</option>
                <option value="0" <?= $curSuccess === '0' ? 'selected' : '' ?>>失敗</option>
            </select>
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">日期</label>
            <div class="flex items-center gap-1.5">
                <input type="date" name="date_from" value="<?= e($curFrom) ?>" max="<?= e(date('Y-m-d')) ?>"
                       aria-label="起始日期" class="px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <span class="text-slate-400 text-sm">～</span>
                <input type="date" name="date_to" value="<?= e($curTo) ?>" max="<?= e(date('Y-m-d')) ?>"
                       aria-label="結束日期" class="px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button type="submit"
                    class="px-4 py-2 bg-slate-800 dark:bg-white/10 text-white rounded-lg text-sm font-medium hover:bg-slate-900 dark:hover:bg-white/20 transition">
                篩選
            </button>
            <?php if ($hasFilter): ?>
            <a href="/admin/invoices/logs" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400">清除</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden">
    <?php if (empty($logs)): ?>
    <p class="px-5 py-12 text-center text-sm text-slate-400">尚無 API 呼叫紀錄</p>
    <?php else: ?>
    <div class="divide-y divide-slate-100 dark:divide-surface-border">
        <?php foreach ($logs as $log): ?>
        <?php $ok = (int) ($log['success'] ?? 0) === 1; ?>
        <details class="group">
            <summary class="px-5 py-3 flex flex-wrap items-center gap-3 cursor-pointer hover:bg-slate-50 dark:hover:bg-white/5 transition">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= $ok
                    ? 'bg-blue-600 text-white'
                    : 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300' ?>">
                    <?= $ok ? '成功' : '失敗' ?>
                </span>
                <span class="text-sm font-medium text-slate-700 dark:text-slate-200">
                    <?= e($operations[(string) ($log['operation'] ?? '')] ?? (string) ($log['operation'] ?? '')) ?>
                </span>
                <span class="text-xs font-mono text-slate-400"><?= e($fmtDt($log['created_at'] ?? null)) ?></span>
                <span class="text-xs font-mono text-slate-500 dark:text-slate-400">HTTP <?= e((string) ($log['http_status'] ?? 0)) ?></span>
                <span class="text-xs font-mono text-slate-400"><?= e((string) ($log['duration_ms'] ?? 0)) ?>ms</span>
                <?php if ((string) ($log['environment'] ?? '') !== 'production'): ?>
                <span class="text-xs px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 dark:bg-white/5 dark:text-slate-400">測試</span>
                <?php endif; ?>
                <?php if (($log['invoice_id'] ?? null)): ?>
                <a href="/admin/invoices/<?= (int) $log['invoice_id'] ?>"
                   class="text-xs text-blue-600 dark:text-blue-400 hover:underline ml-auto">檢視發票</a>
                <?php endif; ?>
            </summary>
            <div class="px-5 pb-4 space-y-3">
                <?php if ((string) ($log['error_message'] ?? '') !== ''): ?>
                <p class="text-xs text-red-700 dark:text-red-300"><?= e((string) $log['error_message']) ?></p>
                <?php endif; ?>
                <p class="text-xs font-mono text-slate-400">
                    <?= e((string) ($log['http_method'] ?? '')) ?> <?= e((string) ($log['endpoint'] ?? '')) ?>
                    <?php if ((string) ($log['request_id'] ?? '') !== ''): ?>
                    ｜request_id: <?= e((string) $log['request_id']) ?>
                    <?php endif; ?>
                </p>
                <?php foreach (['request_payload' => '送出', 'response_payload' => '回應'] as $key => $label): ?>
                <?php $body = (string) ($log[$key] ?? ''); ?>
                <?php if ($body !== '' && $body !== '{}'): ?>
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

<?php if ($totalPages > 1): ?>
    <?php
    $pageBase = array_filter(
        ['operation' => $curOp, 'success' => $curSuccess, 'date_from' => $curFrom, 'date_to' => $curTo],
        static fn ($v) => $v !== ''
    );
    $baseUrl  = '/admin/invoices/logs' . ($pageBase ? '?' . http_build_query($pageBase) : '');
    ?>
    <?php $view->partial('pagination', [
        'page'       => $page,
        'totalPages' => $totalPages,
        'baseUrl'    => $baseUrl,
    ]); ?>
<?php endif; ?>
