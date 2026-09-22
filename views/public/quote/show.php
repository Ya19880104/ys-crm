<?php
/**
 * 公開報價單檢視頁（外部訪客，§7.8）。
 *
 * 版型對齊 Node 版 CRM `views/quote/_document.ejs` 的「雙欄正式估價單」：
 *   - 左側欄 .quo-side：品牌 / 報價對象（公司含「鈞啟」）/ 報價資訊（編號 / 日期 / 有效期限）/ 公司資料。
 *   - 右主欄 .quo-main：標題「報價單」+ 裝飾 rule、幣別/稅別 meta、品項表、合計（小計/稅額/總計）、
 *                       備註、付款狀態、簽章區（我方用印 + 客戶簽章雙欄）。
 *   - 米色棕色文件風（主色 #6b4f30、底 #f7f0e6），響應式（桌機雙欄、手機單欄堆疊），含列印樣式。
 *
 * 付款區（§7.9）改為「付款方式選擇器」（信用卡 / 虛擬 ATM 兩個獨立表單，POST /q/{token}/pay）。
 *
 * 🔴 線上簽署表單 <form id="ys-sign-form"> 與其後的純 JS 簽名板 <script> 為剛修好之區塊，內容原封保留。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $quote          含 items（已含 access_token / status / 金額 / 客戶資料）
 * @var array  $company        我方公司資訊（name/tax_id/address/phone/email/contact/seal/logo）
 * @var array  $statusLabels
 * @var bool   $adminPreview   已驗證的管理者預覽（繞過可見性與分享期限；頁面須明確標示）
 * @var ?array $shareView      管理者預覽時的匿名分享狀態（QuoteService::shareView）
 */

use function YangSheep\CRM\Core\e;

$token      = (string) ($quote['access_token'] ?? '');
$status     = (string) ($quote['status'] ?? 'draft');
$cur        = (string) ($quote['currency'] ?? 'TWD');
$items      = $quote['items'] ?? [];
$isSigned   = in_array($status, ['signed', 'paid'], true);
$isVoid     = $status === 'void';
$sealPath   = (string) ($company['seal'] ?? '');
$companyName = (string) ($company['name'] ?? '') !== '' ? (string) $company['name'] : 'YANGSHEEP DESIGN';
$companyLogo = (string) ($company['logo'] ?? '');

// 客戶為公司行號時，於報價對象顯示「鈞啟」敬語（對齊 Node 版 customer_company 鈞啟）。
$isCustomerCompany = ($quote['customer_type'] ?? '') === 'company';

// 是否逾期（valid_until < 今日）。
$isExpired  = false;
$validUntil = substr((string) ($quote['valid_until'] ?? ''), 0, 10);
if ($validUntil !== '') {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $validUntil);
    $isExpired = $dt !== false && $dt < new DateTimeImmutable('today');
}

$money   = static fn ($v): string => number_format((float) $v, 0);
$qtyFmt  = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
$createdAt = substr((string) ($quote['created_at'] ?? ''), 0, 10);

// 付款狀態（§7.9）：
//   $isPaid  已完成付款（payment_status=paid 或 status=paid）→ 顯示「已完成付款」（藍）。
//   $canPay  已啟用付款 + 已簽署 + 尚未付款 → 顯示付款方式選擇器（信用卡 / 虛擬 ATM，POST /q/{token}/pay）。
$paymentEnabled = (int) ($quote['payment_enabled'] ?? 0) === 1;
$paymentStatus  = (string) ($quote['payment_status'] ?? 'unpaid');
$isPaid = $paymentStatus === 'paid' || $status === 'paid';
$canPay = $paymentEnabled && $isSigned && !$isPaid;

// 客戶簽章資料（公開頁查詢預設未 join 簽名，故全部以 ?? 防呆；存在才渲染圖+metadata，否則退回確認狀態）。
$sigData   = (string) ($quote['signature_data'] ?? '');
$sigSigner = (string) ($quote['signer_name'] ?? ($quote['customer_name'] ?? ''));
$sigAt     = substr((string) ($quote['signed_at'] ?? ''), 0, 19);
// 欄位名是 document_hash（migration 033）。原本還有一個 ?? signature_hash 的
// fallback —— 那個 key 從來不存在於任何 migration，是死碼；而它的存在
// 讓 PDF 端誤以為 signature_hash 是真欄位並只讀它，導致 PDF 上的雜湊永遠不顯示。
$sigHash   = (string) ($quote['document_hash'] ?? '');
?>

<div class="quote-doc-outer mx-auto">

    <?php /* 管理者預覽：明確標示，並說明訪客此刻實際會看到什麼（連結可能已到期或關閉）。 */ ?>
    <?php if (!empty($adminPreview)):
        $pv      = is_array($shareView ?? null) ? $shareView : [];
        $pvState = (string) ($pv['state'] ?? '');
        $pvTz    = (string) ($pv['timezone'] ?? '');
        if ($pvState === 'active') {
            $pvText = !empty($pv['auto_expire'])
                ? '訪客目前可開啟此連結，將於 ' . ($pv['expires_at_label'] ?? '') . '（' . $pvTz . '）自動關閉。'
                : '訪客目前可開啟此連結，且未設定自動關閉。';
        } elseif ($pvState === 'expired') {
            $pvText = '此公開連結已於 ' . ($pv['expires_at_label'] ?? '') . '（' . $pvTz . '）到期，訪客開啟會看到「連結無法使用」。';
        } elseif ($pvState === 'closed') {
            $pvText = '此公開連結已於 ' . ($pv['closed_at_label'] ?? '') . '（' . $pvTz . '）關閉，訪客開啟會看到「連結無法使用」。';
        } elseif ($pvState === 'not_shared') {
            $pvText = '此報價不是匿名公開連結，訪客依可見性規則存取。';
        } else {
            $pvText = '此公開連結尚未設定期限，訪客開啟會看到「連結無法使用」。';
        }
    ?>
    <div class="no-print mb-4 rounded-lg border border-indigo-300 bg-indigo-50 px-4 py-3 text-sm text-indigo-900 leading-relaxed" role="status">
        <span class="font-semibold">管理者預覽</span>——<?= e($pvText) ?>本次開啟不會記為客戶瀏覽。
    </div>
    <?php endif; ?>

    <!-- 動作列（列印時隱藏） -->
    <div class="no-print flex items-center justify-between gap-3 mb-4 flex-wrap">
        <div class="flex items-center gap-2">
            <span class="text-xs text-slate-500">報價單編號</span>
            <span class="font-mono text-sm font-semibold text-slate-700"><?= e($quote['quote_number']) ?></span>
            <?php if ($isSigned): ?>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">已簽署</span>
            <?php elseif ($isVoid): ?>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">已作廢</span>
            <?php elseif ($isExpired): ?>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">已逾期</span>
            <?php endif; ?>
        </div>
        <a href="/q/<?= e($token) ?>/print" target="_blank" rel="noopener"
           class="ys-print-btn inline-flex items-center gap-2 px-4 py-2 bg-white rounded-lg text-sm font-medium transition shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
            </svg>
            列印報價單
        </a>

        <?php /* 下載 PDF：與列印是兩種不同的需求。
                 列印＝我現在要一張紙；PDF＝我要一份可以寄給客戶的檔案。
                 PDF 版的頁碼由我們自己畫，也不會夾帶網址與日期
                 （瀏覽器列印的頁首頁尾由使用者偏好控制，網頁關不掉）。 */ ?>
        <a href="/q/<?= e($token) ?>/pdf"
           class="ys-print-btn inline-flex items-center gap-2 px-4 py-2 bg-white rounded-lg text-sm font-medium transition shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/>
            </svg>
            下載 PDF
        </a>
    </div>

    <!-- 報價文件本體：雙欄正式估價單（左側欄 + 右主欄） -->
    <article class="quotation-doc">

        <!-- 左側欄：品牌 / 報價對象 / 報價資訊 / 公司資料 -->
        <aside class="quo-side">
            <?php
            /*
             * 品牌區顯示方式由設定控制（logo_and_name / logo_only / name_only）。
             *
             * 🔴 選了「只顯示 Logo」但沒上傳 Logo 時，一定要退回顯示名稱 ——
             * 否則左上角會是一片空白，而使用者只會覺得「報價單壞了」，
             * 不會聯想到是設定與素材不一致。
             */
            $brandMode = $quoteTheme['brand_display'] ?? 'logo_and_name';
            $hasLogo   = $companyLogo !== '';
            if ($brandMode === 'logo_only' && !$hasLogo) {
                $brandMode = 'name_only';
            }
            $showLogo = $hasLogo && $brandMode !== 'name_only';
            $showName = $brandMode !== 'logo_only';
            ?>
            <section class="quo-side-brand">
                <?php if ($showLogo): ?>
                    <img src="<?= e($companyLogo) ?>" alt="<?= e($companyName) ?>" class="quo-side-logo-img">
                <?php elseif (!$showName): ?>
                    <span class="quo-side-logo">羊</span>
                <?php elseif (!$hasLogo): ?>
                    <span class="quo-side-logo">羊</span>
                <?php endif; ?>
                <?php if ($showName): ?>
                    <strong><?= e($companyName) ?></strong>
                <?php endif; ?>
            </section>

            <section class="quo-side-section">
                <h2>報價對象</h2>
                <?php if (($quote['customer_name'] ?? '') !== ''): ?>
                    <p class="quo-side-strong"><?= e($quote['customer_name']) ?><?= $isCustomerCompany ? ' 鈞啟' : '' ?></p>
                    <?php if (($quote['customer_tax_id'] ?? '') !== ''): ?><p>統一編號：<?= e($quote['customer_tax_id']) ?></p><?php endif; ?>
                    <?php if (($quote['customer_phone'] ?? '') !== ''): ?><p><?= e($quote['customer_phone']) ?></p><?php endif; ?>
                    <?php if (($quote['customer_email'] ?? '') !== ''): ?><p><?= e($quote['customer_email']) ?></p><?php endif; ?>
                    <?php if (($quote['customer_address'] ?? '') !== ''): ?><p><?= e($quote['customer_address']) ?></p><?php endif; ?>
                <?php else: ?>
                    <p>—</p>
                <?php endif; ?>
            </section>

            <section class="quo-side-section">
                <h2>報價資訊</h2>
                <dl class="quo-side-meta">
                    <div><dt>編號</dt><dd><?= e($quote['quote_number']) ?></dd></div>
                    <div><dt>日期</dt><dd><?= $createdAt !== '' ? e($createdAt) : '—' ?></dd></div>
                    <div><dt>有效期限</dt><dd><?= $validUntil !== '' ? e($validUntil) : '長期有效' ?></dd></div>
                </dl>
            </section>

            <?php if (($company['address'] ?? '') !== '' || ($company['phone'] ?? '') !== '' || ($company['email'] ?? '') !== '' || ($company['tax_id'] ?? '') !== '' || ($company['contact'] ?? '') !== ''): ?>
            <section class="quo-side-section quo-company-info">
                <h2>公司資料</h2>
                <?php if (($company['contact'] ?? '') !== ''): ?><p>聯絡人：<?= e($company['contact']) ?></p><?php endif; ?>
                <?php if (($company['address'] ?? '') !== ''): ?><p><?= e($company['address']) ?></p><?php endif; ?>
                <?php if (($company['phone'] ?? '') !== ''): ?><p>電話：<?= e($company['phone']) ?></p><?php endif; ?>
                <?php if (($company['email'] ?? '') !== ''): ?><p><?= e($company['email']) ?></p><?php endif; ?>
                <?php if (($company['tax_id'] ?? '') !== ''): ?><p>統一編號：<?= e($company['tax_id']) ?></p><?php endif; ?>
            </section>
            <?php endif; ?>
        </aside>

        <!-- 右主欄：標題 + 品項表 + 合計 + 備註 + 付款狀態 + 簽章區 -->
        <main class="quo-main">
            <header class="quo-main-head">
                <div>
                    <h1>報價單</h1>
                    <span class="quo-title-rule"></span>
                </div>
                <div class="quo-main-meta">
                    <span>幣別：<?= e($cur) ?></span>
                    <span>稅別：<?= ((float) ($quote['tax_rate'] ?? 0) > 0) ? '含稅' : '未稅' ?></span>
                </div>
            </header>

            <?php if (($quote['title'] ?? '') !== ''): ?>
            <p class="quo-subject"><?= e($quote['title']) ?></p>
            <?php endif; ?>

            <!-- 品項表 -->
            <table class="quo-items">
                <thead>
                    <tr>
                        <th class="col-item">品項與描述</th>
                        <th class="col-unit">單價</th>
                        <th class="col-qty">數量</th>
                        <th class="col-price">金額</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($items === []): ?>
                    <tr><td class="col-empty" colspan="4">無明細</td></tr>
                    <?php else: ?>
                        <?php foreach ($items as $it): ?>
                        <tr>
                            <td class="col-item">
                                <strong><?= e($it['name']) ?></strong>
                                <?php if (($it['description'] ?? '') !== ''): ?><span><?= e($it['description']) ?></span><?php endif; ?>
                            </td>
                            <td class="col-unit"><?= e($cur) ?> <?= e($money($it['unit_price'])) ?></td>
                            <td class="col-qty"><?= e($qtyFmt($it['qty'])) ?><?= ($it['unit'] ?? '') !== '' ? ' ' . e($it['unit']) : '' ?></td>
                            <td class="col-price"><?= e($cur) ?> <?= e($money($it['amount'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- 合計 -->
            <section class="quo-after-table">
                <div class="quo-after-spacer"></div>
                <div class="quo-totals">
                    <div><span>小計</span><strong><?= e($cur) ?> <?= e($money($quote['subtotal'])) ?></strong></div>
                    <?php if ((float) ($quote['tax_rate'] ?? 0) > 0): ?>
                    <div><span>稅額（<?= e($qtyFmt($quote['tax_rate'])) ?>%）</span><strong><?= e($cur) ?> <?= e($money($quote['tax'])) ?></strong></div>
                    <?php endif; ?>
                    <div class="grand"><span>總計</span><strong><?= e($cur) ?> <?= e($money($quote['total'])) ?></strong></div>
                </div>
            </section>

            <!-- 備註與條款（僅顯示客戶可見的「報價條款」；內部備註 notes 不對外，避免洩漏） -->
            <?php if (($quote['terms'] ?? '') !== ''): ?>
            <section class="quo-notes">
                <h2>備註與條款</h2>
                <p class="notes-content"><?= e($quote['terms']) ?></p>
            </section>
            <?php endif; ?>

            <!-- 付款狀態（§7.9）：已付款顯示狀態徽章；未付款的「前往付款」按鈕在文件下方動作區（簽約後才出現） -->
            <?php if ($isPaid): ?>
            <!-- 已完成付款（藍色，非綠） -->
            <section class="quo-payment-status">
                <div class="quo-pay-paid">
                    <svg class="w-9 h-9 mx-auto mb-2 text-blue-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p class="text-base font-semibold text-blue-700">已完成付款</p>
                    <p class="text-xs mt-1 text-slate-500">感謝您！本報價單款項已收訖。</p>
                </div>
            </section>
            <?php endif; ?>

            <!-- 簽章區：我方用印 + 客戶簽章雙欄 -->
            <section class="quo-signature-area">
                <div class="sig-grid">
                    <!-- 我方用印 -->
                    <div class="sig-col">
                        <div class="sig-col-label">我方用印</div>
                        <div class="sig-col-box">
                            <?php if ($sealPath !== ''): ?>
                                <img src="<?= e($sealPath) ?>" alt="<?= e($companyName) ?> 公司印章" class="company-signature-image<?= $isSigned ? '' : ' is-pending' ?>">
                            <?php else: ?>
                                <span class="sig-placeholder">（公司印章）</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 客戶簽章 -->
                    <div class="sig-col">
                        <div class="sig-col-label">客戶簽章</div>
                        <div class="sig-col-box">
                            <?php if ($isSigned && $sigData !== ''): ?>
                                <!-- 已簽署且有簽名圖：顯示簽名 + 簽署人 / 時間 / hash -->
                                <img src="<?= e($sigData) ?>" alt="客戶簽名" class="sig-image">
                                <div class="sig-meta-small">
                                    <div><?= e($sigSigner) ?><?= $sigAt !== '' ? ' ・ ' . e($sigAt) : '' ?></div>
                                    <?php if ($sigHash !== ''): ?><div>SHA-256: <?= e(substr($sigHash, 0, 16)) ?>...</div><?php endif; ?>
                                </div>
                            <?php elseif ($isSigned): ?>
                                <!-- 已簽署（公開查詢未帶簽名圖）：顯示確認狀態 -->
                                <span class="sig-confirmed">
                                    <svg class="w-7 h-7 mx-auto mb-1 text-blue-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    已完成線上簽署
                                </span>
                            <?php else: ?>
                                <span class="sig-placeholder">
                                    <?php if ($isVoid): ?>此報價單已作廢<?php elseif ($isExpired): ?>此報價單已逾期<?php else: ?>請於下方簽名板簽署<?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </article>

    <!-- 線上簽署表單（未簽署、未作廢、未逾期才顯示；列印時隱藏） -->
    <?php if (!$isSigned && !$isVoid && !$isExpired): ?>
    <div class="no-print ys-sign-card mt-6 bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-base font-semibold mb-1 text-slate-800">線上簽署</h3>
        <p class="text-sm mb-4 text-slate-500">請填寫簽署人姓名，並於下方簽名板簽名後送出。簽署即表示同意本報價內容。</p>

        <form id="ys-sign-form" method="POST" action="/q/<?= e($token) ?>/sign">
            <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
            <input type="hidden" name="signature_data" id="ys-signature-data">

            <div class="mb-4">
                <label for="signer_name" class="block text-sm font-medium text-slate-700 mb-1">簽署人姓名 <span class="text-red-600 dark:text-red-400">*</span></label>
                <input type="text" id="signer_name" name="signer_name" required maxlength="150"
                       class="w-full sm:w-80 px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                       placeholder="請輸入您的姓名">
            </div>

            <div class="mb-3">
                <label class="block text-sm font-medium text-slate-700 mb-1">簽名</label>
                <div class="relative inline-block">
                    <canvas id="ys-sig-canvas" width="600" height="200"
                            class="border-2 border-slate-300 rounded-lg bg-white touch-none cursor-crosshair w-full max-w-[600px]"
                            style="aspect-ratio: 3 / 1;"></canvas>
                </div>
                <div class="mt-2">
                    <button type="button" id="ys-sig-clear"
                            class="text-sm text-slate-500 hover:text-slate-700 underline">清除重簽</button>
                </div>
            </div>

            <p id="ys-sig-error" class="text-sm text-red-600 dark:text-red-400 mb-3 hidden"></p>

            <button type="submit" id="ys-sign-submit"
                    class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                確認簽署
            </button>
        </form>
    </div>
    <?php elseif ($canPay): ?>
    <!-- 簽約完成 → 下一步：前往付款（連到付款方式選擇頁 GET /q/{token}/pay；列印時隱藏） -->
    <div class="no-print ys-next-card mt-6">
        <p class="ys-next-label">已完成簽署，最後一步</p>
        <a href="/q/<?= e($token) ?>/pay" class="ys-pay-next-btn">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h2m-4 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            前往付款 <?= e($cur) ?> <?= e($money($quote['total'])) ?>
        </a>
        <p class="ys-next-hint">下一步將顯示付款方式（信用卡 / 虛擬 ATM）供您選擇。</p>
    </div>
    <?php endif; ?>
</div>

<script>
/*
 * 簽名板（純 JS，不依賴 Alpine）。
 * 設計重點（對齊 Node 版 public/signature-pad.js 久經實戰做法）：
 *   - 以 id 取得 canvas/form/hidden，閉包持有，避免框架 this/$refs 在 submit 時失效。
 *   - Pointer Events 一次涵蓋滑鼠 / 觸控 / 觸控筆（含 setPointerCapture）。
 *   - 每一筆畫完即把 toDataURL 寫入 hidden 欄位 → 送出時必定有值（修正原 Alpine
 *     @submit 在真實點擊時未寫入 hidden、造成空送出「簽名圖無效」之 bug）。
 *   - 白底填充，避免透明 PNG 視覺上像空白。
 *   - submit 以 addEventListener 守門：驗姓名 + 驗 hidden 為合法 PNG data URL。
 */
(function () {
    var canvas = document.getElementById('ys-sig-canvas');
    if (!canvas) return;
    var hidden   = document.getElementById('ys-signature-data');
    var form     = document.getElementById('ys-sign-form');
    var errorEl  = document.getElementById('ys-sig-error');
    var nameEl   = document.getElementById('signer_name');
    var clearBtn = document.getElementById('ys-sig-clear');

    // 依顯示寬度調整內部解析度（維持清晰），保留 3:1 比例。
    var dpr  = window.devicePixelRatio || 1;
    var cssW = canvas.clientWidth || 600;
    var cssH = Math.round(cssW / 3);
    canvas.width  = cssW * dpr;
    canvas.height = cssH * dpr;
    canvas.style.height = cssH + 'px';

    var ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);
    ctx.lineWidth = 2.2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#0F1B33';
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, cssW, cssH);

    var drawing = false, dirty = false;

    function point(e) {
        var r = canvas.getBoundingClientRect();
        return { x: e.clientX - r.left, y: e.clientY - r.top };
    }
    function start(e) {
        e.preventDefault();
        try { canvas.setPointerCapture(e.pointerId); } catch (_) {}
        drawing = true;
        var p = point(e);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
    }
    function move(e) {
        if (!drawing) return;
        e.preventDefault();
        var p = point(e);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        dirty = true;
    }
    function end(e) {
        if (!drawing) return;
        drawing = false;
        try { canvas.releasePointerCapture(e.pointerId); } catch (_) {}
        ctx.closePath();
        writeHidden();
    }
    function writeHidden() {
        if (!dirty) return;
        try { hidden.value = canvas.toDataURL('image/png'); } catch (_) {}
    }

    canvas.addEventListener('pointerdown', start);
    canvas.addEventListener('pointermove', move);
    canvas.addEventListener('pointerup', end);
    canvas.addEventListener('pointercancel', end);

    if (clearBtn) clearBtn.addEventListener('click', function (e) {
        e.preventDefault();
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, cssW, cssH);
        dirty = false;
        hidden.value = '';
        hideError();
    });

    function showError(m) { if (errorEl) { errorEl.textContent = m; errorEl.classList.remove('hidden'); } }
    function hideError()  { if (errorEl) errorEl.classList.add('hidden'); }

    form.addEventListener('submit', function (e) {
        if (!nameEl.value.trim()) { e.preventDefault(); showError('請填寫簽署人姓名。'); return; }
        if (dirty) writeHidden();
        var v = hidden.value || '';
        if (!/^data:image\/png;base64,[A-Za-z0-9+/=]+$/.test(v)) {
            e.preventDefault();
            showError('請於簽名板簽名後再送出。');
            return;
        }
        hideError();
        // 通過 → 原生送出（hidden 已含最新簽名）。
    });
})();
</script>

<style>
/*
 * 雙欄正式估價單樣式（米色棕色文件風，對齊 Node 版 public/style.css .quotation-doc）。
 * 以 scoped class 命名 + CSS 變數，避免動到 layout 既有 Tailwind 設定；無紫無綠。
 */
.quote-doc-outer { max-width: 980px; }

.quotation-doc {
    /* 配色由「系統設定 → 報價單外觀」注入（見下方 <style>）。
       這裡保留一組預設值：設定未存過、或注入失敗時仍是一份完整可讀的文件，
       而不是一張沒有顏色的白紙。 */
    --doc-text: #2a2a2a;
    --doc-muted: #6b6258;
    --doc-line: #d8c9b6;
    --doc-strong-line: #2a2a2a;
    --doc-brand: #6b4f30;
    --doc-brand-2: #7a5a36;
    --doc-rail: #f7f0e6;
    --doc-rail-line: #d4c2aa;
    --doc-soft: #faf7f2;
    display: grid;
    grid-template-columns: 280px minmax(0, 1fr);
    margin: 0 auto;
    padding: 0;
    overflow: hidden;
    border: 1px solid var(--doc-line);
    border-radius: 10px;
    background: #fff;
    color: var(--doc-text);
    box-shadow: 0 16px 40px rgba(42, 34, 25, 0.08);
    font-family: -apple-system, BlinkMacSystemFont, "Inter", "PingFang TC", "Noto Sans TC", "Microsoft JhengHei", sans-serif;
    font-feature-settings: "tnum" 1;
}

/* 左側欄 */
.quo-side {
    display: flex;
    flex-direction: column;
    gap: 28px;
    padding: 38px 28px;
    background: var(--doc-rail);
    border-right: 1px solid var(--doc-rail-line);
}
.quo-side-brand { text-align: center; }
.quo-side-brand strong { display: block; font-size: 18px; line-height: 1.25; color: var(--doc-text); }
.quo-side-logo,
.quo-side-logo-img {
    width: 66px;
    height: 66px;
    margin: 0 auto 12px;
    border: 2px solid var(--doc-brand-2);
    border-radius: 50%;
    display: grid;
    place-items: center;
    color: var(--doc-brand);
    background: #fff;
    font-size: 22px;
    font-weight: 700;
    letter-spacing: 0.04em;
}
.quo-side-logo-img { object-fit: contain; padding: 6px; }
.quo-side-section::before {
    content: "";
    display: block;
    width: 54px;
    height: 1px;
    margin-bottom: 12px;
    background: #bda98f;
}
.quo-side-section h2 {
    margin: 0 0 8px;
    color: var(--doc-brand);
    font-size: 13px;
    font-weight: 700;
}
.quo-side-section p {
    margin: 0 0 4px;
    color: #51483f;
    font-size: 13px;
    line-height: 1.7;
    word-break: break-word;
}
.quo-side-strong { color: var(--doc-text); font-weight: 700; }
.quo-side-meta { margin: 0; display: grid; gap: 7px; font-size: 12px; }
.quo-side-meta div { display: flex; justify-content: space-between; gap: 12px; }
.quo-side-meta dt { color: var(--doc-muted); }
.quo-side-meta dd { margin: 0; font-weight: 700; color: var(--doc-text); text-align: right; }
.quo-company-info { margin-top: auto; }

/* 右主欄 */
.quo-main { min-width: 0; padding: 52px 48px 42px; }
.quo-main-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 24px;
    margin-bottom: 22px;
    padding-bottom: 12px;
}
.quo-main-head h1 {
    margin: 0;
    color: var(--doc-text);
    font-size: 36px;
    font-weight: 600;
    letter-spacing: 8px;
}
.quo-title-rule {
    display: block;
    width: 44px;
    height: 2px;
    margin-top: 14px;
    background: var(--doc-brand-2);
}
.quo-main-meta { display: grid; gap: 4px; color: var(--doc-muted); font-size: 12px; text-align: right; white-space: nowrap; }
.quo-subject { margin: 0 0 18px; color: var(--doc-text); font-size: 16px; font-weight: 700; }

/* 品項表 */
.quo-items { width: 100%; margin: 0; border: 0; border-collapse: collapse; }
.quo-items thead th {
    padding: 12px 10px;
    border-top: 1px solid var(--doc-line);
    border-bottom: 1px solid var(--doc-line);
    background: var(--doc-rail);
    color: var(--doc-brand);
    font-size: 13px;
    font-weight: 700;
}
.quo-items tbody td {
    padding: 16px 10px;
    border-bottom: 1px solid #eee;
    color: var(--doc-text);
    font-size: 13px;
    vertical-align: top;
}
.quo-items .col-item { text-align: left; }
.quo-items .col-item strong { display: block; font-weight: 700; }
.quo-items .col-item span { display: block; margin-top: 3px; color: var(--doc-muted); font-size: 12px; line-height: 1.55; }
.quo-items .col-unit,
.quo-items .col-price { text-align: right; white-space: nowrap; }
.quo-items .col-qty { width: 90px; text-align: center; white-space: nowrap; }
.quo-items th.col-unit, .quo-items th.col-price { text-align: right; }
.quo-items th.col-qty { text-align: center; }
.quo-items .col-empty { padding: 28px 10px; text-align: center; color: var(--doc-muted); }

/* 合計 */
.quo-after-table {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 280px;
    gap: 28px;
    align-items: start;
    margin-top: 24px;
}
.quo-totals { font-size: 13px; }
.quo-totals div {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    padding: 7px 0;
    border-bottom: 1px solid #e8ded0;
    color: var(--doc-text);
}
.quo-totals .grand {
    padding: 12px 0;
    border-top: 2px solid var(--doc-strong-line);
    border-bottom: 0;
    font-size: 18px;
    font-weight: 700;
}

/* 備註 / 條款 */
.quo-notes { margin: 32px 0 0; padding-top: 18px; border-top: 1px solid var(--doc-line); }
.quo-notes h2 { margin: 0 0 10px; color: var(--doc-brand); font-size: 14px; font-weight: 700; }
.notes-content {
    margin: 0;
    padding: 0;
    border: 0;
    background: transparent;
    color: #3d3935;
    font-family: inherit;
    font-size: 13px;
    line-height: 1.9;
    white-space: pre-wrap;
}

/* 付款狀態 / 付款方式選擇器 */
.quo-payment-status { margin: 28px 0 0; padding-top: 18px; border-top: 1px solid var(--doc-line); }
.quo-pay-heading { margin: 0 0 4px; color: var(--doc-brand); font-size: 14px; font-weight: 700; }
.quo-pay-amount-label { margin: 14px 0 0; text-align: center; color: var(--doc-muted); font-size: 13px; }
.quo-pay-amount {
    margin: 2px 0 4px;
    text-align: center;
    color: var(--doc-text);
    font-size: 38px;
    font-weight: 800;
    letter-spacing: 0.5px;
}
.quo-pay-paid {
    padding: 20px;
    text-align: center;
    border: 1px solid #bfd3f4;
    border-radius: 8px;
    background: #eef4ff;
}

.pay-methods {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin: 16px 0 0;
}
.pay-method-form { margin: 0; }
.pay-method-btn {
    width: 100%;
    padding: 22px 18px;
    border: 2px solid var(--doc-line);
    border-radius: 10px;
    background: #fff;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    color: var(--doc-text);
    font: inherit;
    transition: border-color 0.2s ease, background-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
}
.pay-method-btn:hover {
    border-color: var(--doc-brand-2);
    background: var(--doc-soft);
    transform: translateY(-2px);
    box-shadow: 0 10px 24px rgba(42, 34, 25, 0.1);
}
.pay-method-btn:focus-visible { outline: 2px solid var(--doc-brand-2); outline-offset: 2px; }
.pm-icon { color: var(--doc-brand); }
.pm-title { font-size: 16px; font-weight: 700; }
.pm-desc { font-size: 12.5px; color: var(--doc-muted); }
.pay-bind-row {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-bottom: 10px;
    color: #51483f;
    font-size: 12.5px;
    line-height: 1.5;
    cursor: pointer;
}
.pay-bind-row input { margin-top: 2px; accent-color: var(--doc-brand); }
.quo-pay-secure { margin: 14px 0 0; text-align: center; color: var(--doc-muted); font-size: 12px; }

/* 簽章區 */
.quo-signature-area { margin: 32px 0 0; padding-top: 20px; border-top: 1px solid var(--doc-line); break-inside: avoid; }
.sig-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.sig-col { min-height: 120px; border: 1px solid var(--doc-line); border-radius: 6px; position: relative; }
.sig-col-label { position: absolute; top: -10px; left: 14px; padding: 0 8px; background: #fff; color: var(--doc-muted); font-size: 12px; }
.sig-col-box { min-height: 120px; padding: 26px 16px 22px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; }
.sig-col-box .sig-image { max-width: 220px; max-height: 80px; display: block; margin: 0 auto; }
.sig-col-box .company-signature-image { max-width: 220px; max-height: 80px; display: block; margin: 0 auto; object-fit: contain; }
.sig-col-box .company-signature-image.is-pending { opacity: 0.4; }
.sig-meta-small { margin-top: 10px; color: var(--doc-muted); font-size: 11px; text-align: center; line-height: 1.6; }
.sig-confirmed { color: #1d4ed8; font-size: 13px; font-weight: 600; }
.sig-placeholder { color: #b8a98f; font-size: 13px; }

/* 列印按鈕 */
.ys-print-btn { border: 1px solid var(--doc-line, #d8c9b6); color: #51483f; }
.ys-print-btn:hover { background: #faf7f2; }

/* 線上簽署卡片：暖灰邊框與文件一致 */
.ys-sign-card { border: 1px solid #e3d8c7; }

/* 簽約完成 → 前往付款（下一步）卡片 */
.ys-next-card {
    border: 1px solid #e3d8c7;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 1px 2px rgba(0,0,0,.04);
    padding: 28px 24px;
    text-align: center;
}
.ys-next-label { margin: 0 0 14px; color: var(--doc-muted); font-size: 14px; }
.ys-pay-next-btn {
    display: inline-flex; align-items: center; gap: 10px;
    background: #1d4ed8; color: #fff;
    padding: 14px 32px; border-radius: 10px;
    font-size: 17px; font-weight: 700; letter-spacing: .5px;
    text-decoration: none;
    box-shadow: 0 2px 8px rgba(29,78,216,.25);
    transition: background .15s, transform .15s;
}
.ys-pay-next-btn:hover { background: #1e40af; transform: translateY(-1px); }
.ys-next-hint { margin: 12px 0 0; color: var(--doc-muted); font-size: 12.5px; }

/* 響應式：手機單欄堆疊 */
/* 🔴 必須限定 screen。
   A4 直式扣掉 12mm 邊界後可印寬度是 186mm ≈ 703px —— **低於這個斷點**。
   沒有限定媒體的話，列印時整份文件會套用手機版版面：
   側欄堆到內容上方、標題改直排、金額區與簽章區都變單欄。
   實測簽章區因此從 122px 變成 311px，多出的高度把文件推到第二頁。

   使用者回報「列印沒有轉為 A4 格式」講的就是這件事 —— 紙張確實是 A4，
   但版面是手機版。紙不是手機：它窄，但它不需要單欄堆疊。

   （下方的列印區塊原本把 .quotation-doc 硬拉回雙欄，那是只修了其中一個症狀。） */
@media screen and (max-width: 760px) {
    .quotation-doc { grid-template-columns: 1fr; border-radius: 8px; }
    .quo-side { border-right: 0; border-bottom: 1px solid var(--doc-rail-line); }
    .quo-main { padding: 28px 18px; }
    .quo-main-head { flex-direction: column; }
    .quo-main-head h1 { font-size: 28px; letter-spacing: 5px; }
    .quo-main-meta { text-align: left; }
    .quo-after-table { grid-template-columns: 1fr; }
    .sig-grid { grid-template-columns: 1fr; }
}

/* 列印：只留乾淨雙欄估價單（A4），隱藏 .no-print（簽署表單 / 付款 / 導覽） */
@media print {
    @page { size: A4 portrait; margin: 12mm; }
    body { background: #fff !important; }
    .no-print { display: none !important; }
    /* 🔴 螢幕用的外距在紙上是重複的：@page 已經給了 12mm 邊界，
       這裡再加一圈 main 的 py-10/px-4（上下 40px、左右 16px）等於把版面又切掉一次。
       實測後果不只是「窄一點」—— 上下多出的 80px 讓文件總高 1085px 超過
       A4 可印高 1032px，於是帶 break-inside:avoid 的簽章區被整塊推到第二頁，
       一張只有一個品項的報價單印出來變成兩頁。 */
    body { margin: 0 !important; padding: 0 !important; }
    main { padding: 0 !important; margin: 0 !important; }
    .quote-doc-outer { max-width: none !important; margin: 0 !important; padding: 0 !important; }
    .quotation-doc {
        display: grid !important;
        /* 側欄在紙上要按比例縮：螢幕是 280/980≈29%，若沿用 260px
           在 A4 可印寬 703px 上會變成 37%，看起來頭重腳輕。 */
        grid-template-columns: 205px minmax(0, 1fr) !important;
        max-width: none !important;
        margin: 0 !important;
        border: 1px solid var(--doc-line) !important;
        border-radius: 0 !important;
        box-shadow: none !important;
    }
    .quo-side {
        border-right: 1px solid var(--doc-rail-line) !important;
        border-bottom: 0 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .quo-items thead th {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .quo-side { padding: 30px 22px !important; }
    .quo-main { padding: 28px 30px 20px !important; }
    /* 區塊間距：紙本比螢幕緊，這是排版慣例而不是為了硬擠。
       實測這份單頁報價單的區塊間距合計 124px（22/18/24/28/32），
       縮到 80px 省下 44px —— 剛好讓帶 break-inside:avoid 的簽章區
       不必整塊被推到第二頁。 */
    .quo-main-head     { margin-bottom: 14px !important; }
    .quo-subject       { margin-bottom: 12px !important; }
    .quo-after-table   { margin-top: 16px !important; }
    .quo-payment-status{ margin-top: 18px !important; }
    .quo-signature-area{ margin-top: 20px !important; }

    .quo-items tr,
    .quo-notes,
    .quo-signature-area { break-inside: avoid; }
    thead { display: table-header-group; }
}

<?php /* 設定注入的配色。放在最後才能覆寫上方的預設值。
         值已於 QuoteTheme::sanitizeColor() 以白名單驗證（只接受 #RGB / #RRGGBB）——
         這是會被寫進 <style> 的內容，不合格的輸入一律當作沒填，不嘗試修補。 */ ?>
.quotation-doc { <?= $quoteTheme['css'] ?? '' ?> }
</style>
