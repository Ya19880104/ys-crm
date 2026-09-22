<?php
/**
 * 付款結果頁（gateway 瀏覽器導回 / 查無付款；§7.9）。
 * 僅顯示目前付款狀態，實際入帳以 server-to-server webhook 為準。
 *
 * 深藍主題，嚴禁綠色：已付款用實心藍。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var array      $company
 * @var array|null $payment        含 payment_no / amount / currency / status / quote_id；查無為 null
 * @var array      $statusLabels
 */

use function YangSheep\CRM\Core\e;

$found  = is_array($payment);
$status = $found ? (string) ($payment['status'] ?? 'pending') : '';
$cur    = $found ? (string) ($payment['currency'] ?? 'TWD') : 'TWD';
$amount = $found ? number_format((float) ($payment['amount'] ?? 0), 0) : '';

// 狀態視覺（嚴禁綠色）。
$presets = [
    'paid'      => ['ring' => 'border-blue-200 bg-blue-50', 'icon' => 'text-blue-600', 'text' => 'text-blue-700',
                    'svg'  => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z', 'label' => '付款成功'],
    'pending'   => ['ring' => 'border-slate-200 bg-slate-50', 'icon' => 'text-slate-500', 'text' => 'text-slate-700',
                    'svg'  => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'label' => '付款處理中'],
    'failed'    => ['ring' => 'border-red-200 bg-red-50', 'icon' => 'text-red-600', 'text' => 'text-red-700',
                    'svg'  => 'M6 18L18 6M6 6l12 12', 'label' => '付款失敗'],
    'cancelled' => ['ring' => 'border-red-200 bg-red-50', 'icon' => 'text-red-600', 'text' => 'text-red-700',
                    'svg'  => 'M6 18L18 6M6 6l12 12', 'label' => '付款已取消'],
    'refunded'  => ['ring' => 'border-indigo-200 bg-indigo-50', 'icon' => 'text-indigo-600', 'text' => 'text-indigo-700',
                    'svg'  => 'M3 10h11M9 21V3m0 0L3 9m6-6l6 6', 'label' => '已退款'],
];
$p = $presets[$status] ?? $presets['pending'];
?>

<div class="quote-doc mx-auto" style="max-width: 480px;">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-8 text-center">
        <?php if (!$found): ?>
            <svg class="w-12 h-12 text-slate-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <h1 class="text-lg font-bold text-slate-800">找不到付款資料</h1>
            <p class="text-sm text-slate-500 mt-2">查無此付款紀錄，或連結已失效。</p>
        <?php else: ?>
            <div class="w-16 h-16 rounded-full border-2 <?= $p['ring'] ?> flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 <?= $p['icon'] ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $p['svg'] ?>"/>
                </svg>
            </div>
            <h1 class="text-xl font-bold <?= $p['text'] ?>"><?= e($statusLabels[$status] ?? $p['label']) ?></h1>
            <p class="text-3xl font-bold text-slate-900 mt-3 tabular-nums"><?= e($cur) ?> <?= e($amount) ?></p>
            <p class="text-xs text-slate-400 mt-2 font-mono"><?= e($payment['payment_no'] ?? '') ?></p>

            <?php if ($status === 'pending'): ?>
            <p class="text-xs text-slate-500 mt-4 bg-slate-50 rounded-lg px-3 py-2">
                付款結果以金流商通知為準，若已完成付款請稍候重新整理。
            </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
