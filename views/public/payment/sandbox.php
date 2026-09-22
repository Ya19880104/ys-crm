<?php
/**
 * 沙盒付款確認頁（測試用，§7.9）。
 * 讓使用者按「模擬付款成功 / 失敗」→ 送出後由 PublicPaymentController::sandboxSubmit
 * 以伺服器端沙盒簽章模擬 gateway 回呼走 confirmPaid。
 *
 * 此頁僅在 payment.provider=sandbox 時可達；不接觸真實金錢，純供 e2e 驗證付款流程。
 * 深藍主題，嚴禁綠色（成功用實心藍）。
 *
 * 表單 POST /pay/sandbox/{payment_no}（在 web.php CSRF 群組外，故附 CSRF token 由 Controller 自驗）。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var array  $company
 * @var array  $payment   含 payment_no / amount / currency / status
 * @var string $sign      沙盒簽章（顯示用，實際入帳由伺服器端重算）
 * @var string $csrf
 */

use function YangSheep\CRM\Core\e;

$paymentNo = (string) ($payment['payment_no'] ?? '');
$cur       = (string) ($payment['currency'] ?? 'TWD');
$status    = (string) ($payment['status'] ?? 'pending');
$amount    = number_format((float) ($payment['amount'] ?? 0), 0);
$isPaid    = $status === 'paid';
$methodLabels = ['credit' => '信用卡', 'atm' => '虛擬 ATM'];
$methodLabel  = $methodLabels[(string) ($payment['method'] ?? '')] ?? '—';
?>

<div class="quote-doc mx-auto" style="max-width: 520px;">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <!-- 抬頭 -->
        <div class="bg-navy-900 text-white px-6 py-5">
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-white/15 text-white">沙盒測試</span>
                <h1 class="text-lg font-bold">付款確認</h1>
            </div>
            <p class="text-slate-300 text-xs mt-1">SANDBOX · 此為測試付款，不會實際扣款</p>
        </div>

        <div class="px-6 py-6">
            <!-- 付款摘要 -->
            <dl class="space-y-3 text-sm mb-6">
                <div class="flex justify-between border-b border-slate-100 pb-2">
                    <dt class="text-slate-500">付款編號</dt>
                    <dd class="font-mono text-slate-800"><?= e($paymentNo) ?></dd>
                </div>
                <div class="flex justify-between border-b border-slate-100 pb-2">
                    <dt class="text-slate-500">付款方式</dt>
                    <dd class="text-slate-800"><?= e($methodLabel) ?></dd>
                </div>
                <div class="flex justify-between items-center pt-1">
                    <dt class="text-slate-500">應付金額</dt>
                    <dd class="text-2xl font-bold text-slate-900 tabular-nums"><?= e($cur) ?> <?= e($amount) ?></dd>
                </div>
            </dl>

            <?php if ($isPaid): ?>
                <!-- 已付款 -->
                <div class="rounded-lg border-2 border-blue-200 bg-blue-50 p-5 text-center">
                    <svg class="w-10 h-10 text-blue-600 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p class="text-base font-semibold text-blue-700">此付款已完成</p>
                    <a href="/pay/return/sandbox?payment_no=<?= e(rawurlencode($paymentNo)) ?>" class="inline-block mt-3 text-sm text-blue-600 hover:underline">查看付款結果 →</a>
                </div>
            <?php else: ?>
                <!-- 模擬付款動作：成功 / 失敗 -->
                <p class="text-sm text-slate-500 mb-4 text-center">請選擇模擬的付款結果：</p>
                <div class="grid grid-cols-2 gap-3">
                    <!-- 模擬成功（實心藍，非綠） -->
                    <form method="POST" action="/pay/sandbox/<?= e(rawurlencode($paymentNo)) ?>">
                        <input type="hidden" name="_csrf_token" value="<?= e($csrf ?? '') ?>">
                        <input type="hidden" name="result" value="success">
                        <button type="submit" class="w-full px-4 py-3 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                            模擬付款成功
                        </button>
                    </form>
                    <!-- 模擬失敗 -->
                    <form method="POST" action="/pay/sandbox/<?= e(rawurlencode($paymentNo)) ?>">
                        <input type="hidden" name="_csrf_token" value="<?= e($csrf ?? '') ?>">
                        <input type="hidden" name="result" value="fail">
                        <button type="submit" class="w-full px-4 py-3 bg-white border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">
                            模擬付款失敗
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <p class="text-center text-xs text-slate-400 mt-4">
        簽章（sandbox HMAC）：<span class="font-mono"><?= e(substr((string) ($sign ?? ''), 0, 16)) ?>…</span>
    </p>
</div>
