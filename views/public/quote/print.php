<?php
/**
 * 報價單列印專用頁（獨立 HTML，不套 layout）。
 *
 * 版面結構取自 QuotePdfDocument（表格式、A4 友善），
 * 但 CSS 改用瀏覽器原生支援的屬性（flex/grid/系統字型），
 * 不依賴 mPDF；以 mm 定義 A4 紙張與可列印範圍。
 *
 * 頁面載入後自動觸發 window.print()；使用者也可手動按列印。
 *
 * @var array  $quote        含 items
 * @var array  $company      我方公司資訊
 * @var array  $quoteTheme   QuoteTheme::resolve() 的結果
 * @var array  $statusLabels
 */

use function YangSheep\CRM\Core\e;

$cur       = (string) ($quote['currency'] ?? 'TWD');
$items     = is_array($quote['items'] ?? null) ? $quote['items'] : [];
$status    = (string) ($quote['status'] ?? 'draft');
$taxRate   = (float) ($quote['tax_rate'] ?? 0);
$isPaid    = (string) ($quote['payment_status'] ?? '') === 'paid' || $status === 'paid';

$companyName = (string) ($company['name'] ?? '') !== ''
    ? (string) $company['name']
    : 'YANGSHEEP DESIGN';

$companyLogo = (string) ($company['logo'] ?? '');
$sealPath    = (string) ($company['seal'] ?? '');

$brandMode = (string) ($quoteTheme['brand_display'] ?? 'logo_and_name');
if ($brandMode === 'logo_only' && $companyLogo === '') {
    $brandMode = 'name_only';
}

$money   = static fn($v): string => $cur . ' ' . number_format((float) $v, 0);
$qtyFmt  = static fn($v): string => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');

$sigData = (string) ($quote['signature_data'] ?? '');
$signer  = trim((string) ($quote['signer_name'] ?? ''));
$signedAt = (string) ($quote['signed_at'] ?? '');
$docHash = (string) ($quote['document_hash'] ?? '');

// 🔴 簽名可能是 data URL，**也可能是已存檔的上傳路徑**（storeSignature() 會把簽名板
// PNG 寫進 uploads/ 並回傳 /uploads/quotes/xxx.png）。維持白名單（不把任意字串吐進
// src），只是兩種形式都要認 —— 只認 data URL 會讓實際已簽署的報價在列印稿上沒有簽名。
$hasESign = $sigData !== '' && (
    str_starts_with($sigData, 'data:image/')
    || \YangSheep\CRM\Core\UploadPath::isUploadWebPath($sigData)
);

// SHA-256 是「電子簽章的存證雜湊」。沒有電子簽名時印出雜湊只會讓人誤以為這份
// 紙本已有電子存證，故一律跟著 $hasESign 走，而不是看 document_hash 有沒有值。
$showHash = $hasESign && $docHash !== '';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- 公開報價頁不應被搜尋引擎索引（此頁不套 layout，須自行宣告；HTTP 標頭另由全域 SecurityHeaders 送出） -->
<meta name="robots" content="noindex, nofollow, noarchive">
<title><?= e($quote['quote_number'] ?? '') ?> — 報價單列印</title>
<style>
/* 配色（由 QuoteTheme 注入，與公開頁 / PDF 同一組變數） */
:root {
    --doc-text: #2a2a2a;
    --doc-muted: #6b6258;
    --doc-line: #d8c9b6;
    --doc-strong-line: #2a2a2a;
    --doc-brand: #6b4f30;
    --doc-brand-2: #7a5a36;
    --doc-rail: #f7f0e6;
    --doc-rail-line: #d4c2aa;
    --doc-soft: #faf7f2;
    <?= $quoteTheme['css'] ?? '' ?>
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: -apple-system, BlinkMacSystemFont, "Inter", "PingFang TC", "Noto Sans TC",
                 "Microsoft JhengHei", sans-serif;
    font-size: 10pt;
    color: var(--doc-text);
    line-height: 1.55;
    background: #fff;
}

@page { size: A4 portrait; margin: 12mm; }

/* 頂部工具列（列印時隱藏） */
.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    max-width: 210mm;
    margin: 16px auto;
    padding: 0 12mm;
    gap: 12px;
    flex-wrap: wrap;
}
.toolbar-title { color: var(--doc-muted); font-size: 12px; }
.toolbar-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border: 1px solid var(--doc-line);
    border-radius: 6px;
    background: #fff;
    color: var(--doc-text);
    font-size: 13px;
    font-family: inherit;
    cursor: pointer;
    transition: background .15s;
}
.toolbar-btn:hover { background: var(--doc-soft); }
.toolbar-btn:focus-visible { outline: 2px solid var(--doc-brand); outline-offset: 3px; }
#print-status { flex-basis: 100%; font-size: 12px; color: #4b5563; }

/* 文件容器：模擬 A4 */
.doc {
    max-width: 210mm;
    margin: 0 auto;
    padding: 10mm 12mm;
    background: #fff;
}

/* 表頭：品牌 + 標題（雙欄 flexbox） */
.head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6mm; }
.head-brand { flex: 0 0 42%; }
.head-title { flex: 0 0 58%; text-align: right; }
.logo { width: 26mm; height: auto; display: block; margin-bottom: 2mm; }
.brand-name { font-size: 13pt; font-weight: bold; color: var(--doc-brand); letter-spacing: 0.5pt; }
.doc-title { font-size: 22pt; font-weight: bold; color: var(--doc-brand); letter-spacing: 5pt; }
.meta { font-size: 8.5pt; color: var(--doc-muted); }

/* 資訊帶 */
.info { display: flex; gap: 0; margin-bottom: 6mm; }
.info-l {
    flex: 0 0 58%;
    background: var(--doc-rail);
    border: 0.5pt solid var(--doc-rail-line);
    padding: 4mm 5mm;
}
.info-r { flex: 0 0 42%; padding: 4mm 0 4mm 6mm; }
.info-h { font-size: 8.5pt; font-weight: bold; color: var(--doc-brand); margin-bottom: 1.5mm; }
.info-strong { font-weight: bold; font-size: 11pt; margin-bottom: 1mm; }
.info-line { font-size: 8.5pt; color: var(--doc-muted); }
.kv { width: 100%; font-size: 8.5pt; border-collapse: collapse; }
.kv .k { color: var(--doc-muted); padding: 0.8mm 0; }
.kv .v { text-align: right; font-weight: bold; padding: 0.8mm 0; }

.subject { font-weight: bold; font-size: 11pt; margin-bottom: 3mm; }

/* 品項表 */
.items { width: 100%; border-collapse: collapse; table-layout: fixed; }
.items thead { display: table-header-group; }
.items tr { break-inside: avoid; }
.items col.item-name { width: 46%; }
.items col.item-price, .items col.item-total { width: 21%; }
.items col.item-qty { width: 12%; }
.items th {
    background: var(--doc-soft);
    color: var(--doc-brand);
    font-size: 8.5pt;
    font-weight: bold;
    text-align: left;
    padding: 2.5mm;
    border-bottom: 0.8pt solid var(--doc-line);
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.items td { padding: 2.5mm; border-bottom: 0.4pt solid var(--doc-line); vertical-align: top; font-size: 9.5pt; }
.items th.c-num, .items td.c-num { text-align: right; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.items th.c-qty, .items td.c-qty { text-align: center; overflow-wrap: anywhere; }
.c-name { text-align: left; }
.c-name, .head-brand, .head-title, .info-l, .info-r { overflow-wrap: anywhere; min-width: 0; }
.it-name { font-weight: bold; }
.it-desc { font-size: 8.5pt; color: var(--doc-muted); margin-top: 0.8mm; }

/* 合計 */
.totals { width: 45%; margin-left: 55%; margin-top: 4mm; border-collapse: collapse; break-inside: avoid; }
.totals .t-row { display: flex; justify-content: space-between; gap: 3mm; padding: 1.6mm 0; }
.totals .t-k { color: var(--doc-muted); }
.totals .t-v { font-weight: bold; text-align: right; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.totals .grand { font-size: 13pt; color: var(--doc-text); border-top: 1pt solid var(--doc-strong-line); padding-top: 2.5mm; }

/* 已付款 */
.paid-notice {
    margin-top: 5mm;
    padding: 3mm;
    text-align: center;
    font-size: 9.5pt;
    background: var(--doc-soft);
    border: 0.5pt solid var(--doc-line);
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

/* 備註 */
.terms-h { margin-top: 6mm; font-size: 9pt; font-weight: bold; color: var(--doc-brand); break-after: avoid; }
.terms { font-size: 8.5pt; color: var(--doc-muted); white-space: pre-wrap; overflow-wrap: anywhere; }

/* 簽章區 */
.sig { display: flex; gap: 4mm; margin-top: 7mm; break-inside: avoid; }
.sig-cell { flex: 1; min-width: 0; }
.sig-h { font-size: 8.5pt; color: var(--doc-muted); margin-bottom: 1.5mm; }
.sig-box {
    height: 26mm;
    border: 0.5pt solid var(--doc-line);
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
}
.sig-placeholder { color: var(--doc-muted); font-size: 8.5pt; }
/* 🔴 用印／簽章的空間必須固定：這份列印稿常是印出來手簽或蓋章的，
   簽署前後、有無電子簽名，留給實體用印的位置都不該改變，兩欄底部也要齊平。
   meta 最多三行（簽署人 / 時間 / SHA-256），故保留三行高度。 */
.sig-meta-area { min-height: 12mm; }
.seal { width: 24mm; height: auto; max-width: 100%; max-height: 24mm; object-fit: contain; }
.sig-img { height: 18mm; max-width: 100%; object-fit: contain; }
.sig-meta { font-size: 7.5pt; color: var(--doc-muted); margin-top: 1mm; }

/* 頁尾 */
.footer {
    margin-top: 8mm;
    padding-top: 3mm;
    border-top: 0.4pt solid #ddd;
    display: flex;
    justify-content: space-between;
    font-size: 7.5pt;
    color: #8a8a8a;
}

/* 列印時隱藏工具列 */
@media print {
    .toolbar { display: none !important; }
    .doc { max-width: none; margin: 0; padding: 0; }
    body { background: #fff; }
    .info-l, .items th, .paid-notice {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>
</head>
<body>

<div class="toolbar">
    <span class="toolbar-title"><?= e($quote['quote_number'] ?? '') ?> — <?= e($companyName) ?></span>
    <button type="button" class="toolbar-btn" onclick="window.print()">
        <svg aria-hidden="true" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
        </svg>
        列印／儲存 PDF
    </button>
    <p id="print-status" role="status">正在準備字型與圖片。列印時請選 A4，可在目的地選擇「另存為 PDF」。</p>
</div>

<div class="doc">

    <!-- 表頭 -->
    <div class="head">
        <div class="head-brand">
            <?php if ($companyLogo !== '' && $brandMode !== 'name_only'): ?>
                <img src="<?= e($companyLogo) ?>" class="logo" alt="">
            <?php endif; ?>
            <?php if ($brandMode !== 'logo_only'): ?>
                <div class="brand-name"><?= e($companyName) ?></div>
            <?php endif; ?>
        </div>
        <div class="head-title">
            <div class="doc-title">報價單</div>
            <div class="meta">幣別：<?= e($cur) ?></div>
            <div class="meta">稅別：<?= $taxRate > 0 ? '含稅' : '未稅' ?></div>
        </div>
    </div>

    <!-- 資訊帶 -->
    <div class="info">
        <div class="info-l">
            <div class="info-h">報價對象</div>
            <div class="info-strong"><?= e((string) ($quote['customer_name'] ?? '')) ?></div>
            <?php foreach (['customer_tax_id' => '統一編號', 'customer_phone' => '電話', 'customer_email' => 'Email', 'customer_address' => '地址'] as $key => $label): ?>
                <?php $val = trim((string) ($quote[$key] ?? '')); if ($val === '') continue; ?>
                <div class="info-line"><?= e($label) ?>：<?= e($val) ?></div>
            <?php endforeach; ?>
        </div>
        <div class="info-r">
            <div class="info-h">報價資訊</div>
            <table class="kv">
                <?php foreach ([
                    '編號'     => (string) ($quote['quote_number'] ?? ''),
                    '日期'     => substr((string) ($quote['created_at'] ?? ''), 0, 10),
                    '有效期限' => substr((string) ($quote['valid_until'] ?? ''), 0, 10),
                ] as $label => $val): ?>
                    <?php if ($val === '') continue; ?>
                    <tr><td class="k"><?= e($label) ?></td><td class="v"><?= e($val) ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <?php if (trim((string) ($quote['title'] ?? '')) !== ''): ?>
        <div class="subject"><?= e((string) $quote['title']) ?></div>
    <?php endif; ?>

    <!-- 品項表 -->
    <table class="items">
        <colgroup><col class="item-name"><col class="item-price"><col class="item-qty"><col class="item-total"></colgroup>
        <thead><tr>
            <th class="c-name">品項與描述</th>
            <th class="c-num">單價</th>
            <th class="c-qty">數量</th>
            <th class="c-num">金額</th>
        </tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <tr>
                <td class="c-name">
                    <span class="it-name"><?= e((string) ($it['name'] ?? '')) ?></span>
                    <?php $desc = trim((string) ($it['description'] ?? '')); if ($desc !== ''): ?>
                        <div class="it-desc"><?= nl2br(e($desc)) ?></div>
                    <?php endif; ?>
                </td>
                <td class="c-num"><?= e($money($it['unit_price'] ?? 0)) ?></td>
                <?php
                $qty  = $qtyFmt($it['qty'] ?? 0);
                $unit = trim((string) ($it['unit'] ?? ''));
                ?>
                <td class="c-qty"><?= e($unit !== '' ? $qty . ' ' . $unit : $qty) ?></td>
                <td class="c-num"><?= e($money($it['amount'] ?? 0)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- 合計 -->
    <div class="totals">
        <div class="t-row"><span class="t-k">小計</span><span class="t-v"><?= e($money($quote['subtotal'] ?? 0)) ?></span></div>
        <div class="t-row"><span class="t-k">稅額<?= $taxRate > 0 ? '（' . e(rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.')) . '%）' : '' ?></span><span class="t-v"><?= e($money($quote['tax'] ?? 0)) ?></span></div>
        <div class="t-row grand"><span class="t-k">總計</span><span class="t-v"><?= e($money($quote['total'] ?? 0)) ?></span></div>
    </div>

    <?php if ($isPaid): ?>
        <div class="paid-notice">已完成付款　感謝您！本報價單款項已收訖。</div>
    <?php endif; ?>

    <?php $termsText = trim((string) ($quote['terms'] ?? '')); if ($termsText !== ''): ?>
        <div class="terms-h">備註與條款</div>
        <div class="terms"><?= e($termsText) ?></div>
    <?php endif; ?>

    <!-- 簽章區 -->
    <div class="sig">
        <div class="sig-cell">
            <div class="sig-h">我方用印</div>
            <div class="sig-box">
                <?php if ($sealPath !== ''): ?>
                    <img src="<?= e($sealPath) ?>" class="seal" alt="">
                <?php else: ?>
                    <span class="sig-placeholder">（公司印章）</span>
                <?php endif; ?>
            </div>
            <?php // 與客戶簽章欄對稱的保留區，讓兩欄底部齊平（本欄無 meta 內容）。 ?>
            <div class="sig-meta-area"></div>
        </div>
        <div class="sig-cell">
            <div class="sig-h">客戶簽章</div>
            <div class="sig-box">
                <?php if ($hasESign): ?>
                    <img src="<?= e($sigData) ?>" class="sig-img" alt="">
                <?php else: ?>
                    <span class="sig-placeholder">（客戶簽名）</span>
                <?php endif; ?>
            </div>
            <?php // meta 區高度固定：簽署前後版面不位移，兩欄底部也才會齊平。 ?>
            <div class="sig-meta-area">
                <?php if ($hasESign && $signer !== ''): ?>
                    <div class="sig-meta"><?= e($signer) ?></div>
                <?php endif; ?>
                <?php if ($hasESign && $signedAt !== ''): ?>
                    <div class="sig-meta"><?= e(substr($signedAt, 0, 19)) ?></div>
                <?php endif; ?>
                <?php if ($showHash): ?>
                    <div class="sig-meta">SHA-256: <?= e(substr($docHash, 0, 24)) ?>…</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 頁尾 -->
    <div class="footer">
        <span><?= e((string) ($quote['quote_number'] ?? '')) ?>　<?= e($companyName) ?></span>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', async function () {
    const status = document.getElementById('print-status');
    const images = Array.from(document.images, image => new Promise((resolve, reject) => {
        const ready = () => image.naturalWidth > 0 ? resolve() : reject(new Error('image'));
        if (image.complete) { ready(); return; }
        image.addEventListener('load', ready, {once: true});
        image.addEventListener('error', () => reject(new Error('image')), {once: true});
    }));
    let timeout;
    try {
        await Promise.race([
            Promise.all([document.fonts ? document.fonts.ready : Promise.resolve(), ...images]),
            new Promise((_, reject) => { timeout = setTimeout(() => reject(new Error('timeout')), 8000); })
        ]);
        status.textContent = '列印格式已就緒；若未顯示列印視窗，請按「列印／儲存 PDF」。';
        requestAnimationFrame(() => requestAnimationFrame(() => window.print()));
    } catch (_) {
        status.textContent = '部分字型或圖片尚未就緒，已暫停自動列印。請檢查內容，重新整理或手動列印。';
    } finally {
        clearTimeout(timeout);
    }
});
</script>
</body>
</html>
