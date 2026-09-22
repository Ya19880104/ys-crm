<?php
/**
 * 通知記錄（對應架構設計 §7.10）。
 * 兩個 tab：站內通知 / 寄信佇列。可重送失敗信、標記通知已讀。
 *
 * 配色規範（嚴禁綠色）：到期/警告=amber、危險/已到期/失敗=red、成功/已讀=實心藍、待處理=slate。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $_flash
 * @var string $tab               'notifications' | 'emails'
 * @var array  $notifications     站內通知列
 * @var array  $emails            寄信佇列列
 * @var int    $total
 * @var int    $page
 * @var int    $perPage
 * @var int    $totalPages
 * @var array  $notifFilters      type / is_read / recipient_type
 * @var array  $emailFilters      status
 * @var array  $typeLabels        type => 中文
 * @var array  $emailStatusLabels status => 中文
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

$fmtDateTime = static function (?string $d): string {
    $d = (string) ($d ?? '');
    return $d !== '' ? substr($d, 0, 16) : '—';
};

// 通知 type → badge 配色（語意：到期=amber、待收/待簽=amber、已收/已簽=實心藍、待人工=red、其他=slate）。
$typeChip = static function (string $type): string {
    return match ($type) {
        'payment.received', 'quote.signed'        => 'bg-blue-600 text-white dark:bg-blue-600 dark:text-white',
        'recurring.generated'                     => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
        'hosting.expiring', 'website.expiring',
        'quote.pending_sign', 'payment.due'       => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
        'recurring.autocharge_manual'             => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
        default                                   => 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300',
    };
};

// 寄信狀態 → badge 配色（已寄=實心藍、失敗=red、寄送中=amber、待寄=slate）。
$emailChip = [
    'queued'  => 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300',
    'sending' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    'indeterminate' => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
    'sent'    => 'bg-blue-600 text-white dark:bg-blue-600 dark:text-white',
    'failed'  => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
];

// 頁簽統一為底線式（.ys-tab）—— 本頁原本是全站唯一的藥丸式寫法。
// 藥丸式保留給「分段控制」（同一份清單的篩選值，例如 全部／個人／公司），
// 兩者刻意長得不一樣，讓「切換檢視」與「切換篩選」一眼可分。
?>

<!-- 頂部 tab：站內通知 / 寄信佇列 -->
<div class="ys-tabs">
    <a href="/admin/notifications?tab=notifications" class="ys-tab<?= $tab === 'notifications' ? ' is-active' : '' ?>">站內通知</a>
    <a href="/admin/notifications?tab=emails" class="ys-tab<?= $tab === 'emails' ? ' is-active' : '' ?>">寄信佇列</a>
    <span class="ys-tabs-aside">共 <?= e((string) $total) ?> 筆</span>
</div>

<?php if ($tab === 'emails'): ?>
    <!-- ─────────── 寄信佇列 ─────────── -->
    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-4 mb-5">
        <form method="GET" action="/admin/notifications" class="ys-toolbar flex flex-wrap items-end gap-3">
            <input type="hidden" name="tab" value="emails">
            <div class="min-w-[160px]">
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">狀態</label>
                <select name="status"
                        class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">全部狀態</option>
                    <?php foreach ($emailStatusLabels as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= (string) ($emailFilters['status'] ?? '') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-slate-800 dark:bg-white/10 text-white rounded-lg text-sm font-medium hover:bg-slate-900 dark:hover:bg-white/20 transition">篩選</button>
        </form>
    </div>

    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm whitespace-nowrap">
            <thead class="bg-slate-50 dark:bg-white/5 border-b border-slate-200 dark:border-surface-border">
                <tr>
                    <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">收件者</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">主旨</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">狀態</th>
                    <th class="text-center px-4 py-3 font-medium text-slate-500 dark:text-slate-400">嘗試</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">建立 / 寄出</th>
                    <th class="text-right px-4 py-3 font-medium text-slate-500 dark:text-slate-400">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
                <?php if (empty($emails)): ?>
                <tr><td colspan="6" class="px-4 py-12 text-center text-slate-400">寄信佇列為空</td></tr>
                <?php else: ?>
                    <?php foreach ($emails as $m): ?>
                    <?php $st = (string) $m['status']; ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-white/5 transition">
                        <td class="px-4 py-3 text-slate-700 dark:text-slate-200 max-w-[200px] truncate" title="<?= e($m['to_email']) ?>"><?= e($m['to_email']) ?></td>
                        <td class="px-4 py-3 text-slate-700 dark:text-slate-200 max-w-[280px] truncate" title="<?= e($m['subject']) ?>"><?= e($m['subject']) ?></td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $emailChip[$st] ?? $emailChip['queued'] ?>">
                                <?= e($emailStatusLabels[$st] ?? $st) ?>
                            </span>
                            <?php if (in_array($st, ['failed', 'indeterminate'], true) && ($m['last_error'] ?? '') !== ''): ?>
                            <span class="block text-xs text-red-600 dark:text-red-400 mt-1 max-w-[280px] truncate" title="<?= e($m['last_error']) ?>"><?= e($m['last_error']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center text-slate-500 dark:text-slate-400"><?= (int) ($m['attempts'] ?? 0) ?></td>
                        <td class="px-4 py-3 text-slate-500 dark:text-slate-400 text-xs">
                            <?= e($fmtDateTime($m['created_at'] ?? null)) ?>
                            <?php if (($m['sent_at'] ?? '') !== ''): ?>
                            <span class="block text-blue-600 dark:text-blue-400">寄出：<?= e($fmtDateTime($m['sent_at'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <?php if ($st === 'failed'): ?>
                            <form method="POST" action="/admin/notifications/emails/<?= (int) $m['id'] ?>/resend" class="inline">
                                <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                                <button type="submit" class="text-blue-600 hover:text-blue-800 text-xs font-medium">重送</button>
                            </form>
                            <?php else: ?>
                            <span class="text-slate-400 text-xs">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
        <?php
        $pageBase = array_filter(['tab' => 'emails', 'status' => (string) ($emailFilters['status'] ?? '')], static fn ($v) => $v !== '');
        $baseUrl = '/admin/notifications?' . http_build_query($pageBase);
        ?>
        <?php $view->partial('pagination', ['page' => $page, 'totalPages' => $totalPages, 'baseUrl' => $baseUrl]); ?>
    <?php endif; ?>

<?php else: ?>
    <!-- ─────────── 站內通知 ─────────── -->
    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-4 mb-5">
        <form method="GET" action="/admin/notifications" class="ys-toolbar flex flex-wrap items-end gap-3">
            <input type="hidden" name="tab" value="notifications">
            <div class="min-w-[180px]">
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">類型</label>
                <select name="type"
                        class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">全部類型</option>
                    <?php foreach ($typeLabels as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= (string) ($notifFilters['type'] ?? '') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="min-w-[140px]">
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">已讀狀態</label>
                <select name="is_read"
                        class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">全部</option>
                    <option value="0" <?= (string) ($notifFilters['is_read'] ?? '') === '0' ? 'selected' : '' ?>>未讀</option>
                    <option value="1" <?= (string) ($notifFilters['is_read'] ?? '') === '1' ? 'selected' : '' ?>>已讀</option>
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-slate-800 dark:bg-white/10 text-white rounded-lg text-sm font-medium hover:bg-slate-900 dark:hover:bg-white/20 transition">篩選</button>
        </form>
    </div>

    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 dark:bg-white/5 border-b border-slate-200 dark:border-surface-border">
                <tr>
                    <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400 whitespace-nowrap">類型</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400">內容</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400 whitespace-nowrap">管道</th>
                    <th class="text-left px-4 py-3 font-medium text-slate-500 dark:text-slate-400 whitespace-nowrap">時間</th>
                    <th class="text-right px-4 py-3 font-medium text-slate-500 dark:text-slate-400 whitespace-nowrap">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
                <?php if (empty($notifications)): ?>
                <tr><td colspan="5" class="px-4 py-12 text-center text-slate-400">尚無通知</td></tr>
                <?php else: ?>
                    <?php foreach ($notifications as $n): ?>
                    <?php
                    $type = (string) $n['type'];
                    $isRead = (int) ($n['is_read'] ?? 0) === 1;
                    ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-white/5 transition <?= $isRead ? '' : 'bg-amber-50/40 dark:bg-amber-500/5' ?>">
                        <td class="px-4 py-3 whitespace-nowrap align-top">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $typeChip($type) ?>">
                                <?= e($typeLabels[$type] ?? $type) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 align-top max-w-[480px]">
                            <p class="font-medium text-slate-800 dark:text-slate-100"><?= e($n['title']) ?></p>
                            <?php if (($n['body'] ?? '') !== ''): ?>
                            <p class="text-slate-500 dark:text-slate-400 text-xs mt-0.5"><?= e($n['body']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap align-top">
                            <span class="text-xs text-slate-500 dark:text-slate-400"><?= $n['channel'] === 'email' ? 'Email' : '站內' ?></span>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap align-top text-xs text-slate-500 dark:text-slate-400"><?= e($fmtDateTime($n['created_at'] ?? null)) ?></td>
                        <td class="px-4 py-3 whitespace-nowrap align-top text-right">
                            <?php if (!$isRead): ?>
                            <form method="POST" action="/admin/notifications/<?= (int) $n['id'] ?>/read" class="inline">
                                <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                                <button type="submit" class="text-blue-600 hover:text-blue-800 text-xs font-medium">標為已讀</button>
                            </form>
                            <?php else: ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-blue-600 text-white">已讀</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
        <?php
        $pageBase = array_filter([
            'tab'     => 'notifications',
            'type'    => (string) ($notifFilters['type'] ?? ''),
            'is_read' => (string) ($notifFilters['is_read'] ?? ''),
        ], static fn ($v) => $v !== '');
        $baseUrl = '/admin/notifications?' . http_build_query($pageBase);
        ?>
        <?php $view->partial('pagination', ['page' => $page, 'totalPages' => $totalPages, 'baseUrl' => $baseUrl]); ?>
    <?php endif; ?>
<?php endif; ?>
