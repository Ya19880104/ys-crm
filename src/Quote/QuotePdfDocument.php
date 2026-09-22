<?php

declare(strict_types=1);

namespace YangSheep\CRM\Quote;

use function YangSheep\CRM\Core\e;

/**
 * 報價單的 PDF 版面（產生 HTML，不負責轉檔）。
 *
 * 【為什麼是獨立的一份版面，而不是沿用畫面上那份】
 * PDF 由 mPDF 算繪，而 mPDF **不支援 CSS grid 與 flexbox** —— 畫面版面的雙欄
 * 結構（`grid-template-columns: 205px 1fr`）在它眼裡等於不存在，整份會塌成單欄。
 * 所以這不是「要不要多一份模板」的選擇題，是換算繪引擎必然的結果。
 *
 * 【避免兩份漂移的做法】版面不同是必然，但**內容不可以不同**：
 * 兩邊都只從同一組 quote 欄位取值，且欄位清單由本類別的 FIELDS 常數釘住，
 * 有測試比對 —— 畫面上加了欄位卻忘了加到 PDF，測試會紅。
 *
 * 【為什麼拆成「產生 HTML」與「轉 PDF」兩個類別】
 * 本機沒有 composer，mPDF 只裝在伺服器上。若把兩件事寫在一起，
 * 這段版面邏輯就永遠只能在伺服器上測。拆開之後，版面（也就是會出錯的那部分）
 * 可以在本機以純 PHP 測試，mPDF 那層只剩薄薄一層設定。
 */
final class QuotePdfDocument
{
    /**
     * PDF 會用到的 quote 欄位。
     *
     * 🔴 這不只是文件 —— 有測試拿它跟畫面版比對，確保兩份版面吃的是同一組資料。
     * 新增欄位時兩邊都要加，否則客戶收到的 PDF 會少東西，而畫面上看起來一切正常。
     */
    public const FIELDS = [
        'quote_number', 'title', 'status', 'currency',
        'customer_name', 'customer_type', 'customer_tax_id',
        'customer_phone', 'customer_email', 'customer_address',
        'items', 'subtotal', 'tax', 'tax_rate', 'total',
        'terms', 'valid_until', 'created_at',
        'signature_data', 'document_hash', 'signer_name', 'signed_at',
        'payment_status',
    ];

    /**
     * 刻意不放進 PDF 的欄位。
     *
     * 有這份白名單，測試才能反向驗證：「畫面版讀了、PDF 沒讀」的欄位一律要在這裡
     * 明列理由，否則就是漏掉。沒有它的話，只能驗 PDF→畫面那個方向 ——
     * 而那個方向永遠抓不到「畫面新增欄位、PDF 忘了加」。
     *
     * @var array<string,string> 欄位 => 不放的理由
     */
    public const INTENTIONALLY_OMITTED = [
        'access_token'     => '公開連結的憑證。PDF 會被轉寄，不可寫進文件。',
        'customer_type'    => '僅影響畫面上的敬語，PDF 版面不用。',
        'payment_enabled'  => '線上付款按鈕是螢幕互動，紙本沒有對應。',
    ];

    /** 品項的欄位（同上，兩份版面必須一致）。 */
    public const ITEM_FIELDS = ['name', 'description', 'qty', 'unit_price', 'unit', 'amount'];

    /**
     * 產生可交給 mPDF 的完整 HTML。
     *
     * @param array<string,mixed> $quote
     * @param array<string,mixed> $company
     * @param array<string,mixed> $theme  QuoteTheme::resolve() 的結果
     */
    public static function build(array $quote, array $company, array $theme): string
    {
        $cur    = (string) ($quote['currency'] ?? 'TWD');
        $items  = is_array($quote['items'] ?? null) ? $quote['items'] : [];
        $status = (string) ($quote['status'] ?? 'draft');

        $companyName = (string) ($company['name'] ?? '') !== ''
            ? (string) $company['name']
            : 'YANGSHEEP DESIGN';

        // 品牌顯示模式與畫面版同一套規則（選 logo_only 但沒有 Logo 時退回名稱）。
        $brandMode = (string) ($theme['brand_display'] ?? 'logo_and_name');
        $logoPath  = self::localPath((string) ($company['logo'] ?? ''));
        $sealPath  = self::localPath((string) ($company['seal'] ?? ''));
        if ($brandMode === 'logo_only' && $logoPath === null) {
            $brandMode = 'name_only';
        }

        $vars = self::cssVars($theme);
        $money = static fn($v): string => $cur . ' ' . number_format((float) $v, 0);
        // 與畫面版相同：3 印成「3」、2.5 印成「2.5」，不要「3.00」。
        $qtyFmt = static fn($v): string => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');

        $html = '<html><head><meta charset="utf-8"><style>' . self::styles($vars) . '</style></head><body>';

        // ── 表頭：品牌 + 標題 ──
        $html .= '<table class="head"><tr>';
        $html .= '<td class="head-brand">';
        if ($logoPath !== null && $brandMode !== 'name_only') {
            $html .= '<img src="' . e($logoPath) . '" class="logo">';
        }
        if ($brandMode !== 'logo_only') {
            $html .= '<div class="brand-name">' . e($companyName) . '</div>';
        }
        $html .= '</td>';
        $html .= '<td class="head-title"><div class="doc-title">報價單</div>'
               . '<div class="meta">幣別：' . e($cur) . '</div>'
               . '<div class="meta">稅別：含稅</div></td>';
        $html .= '</tr></table>';

        // ── 資訊帶：報價對象｜報價資訊 ──
        //
        // 🔴 【為什麼不是畫面上那種左側全高欄】實測 28 個品項時，
        // 把整份文件包在雙欄 <table> 裡會讓第一頁幾乎空白、內容整塊被推到第二頁 ——
        // 表格儲存格無法跨頁流動，內容一超過一頁就整塊搬家。
        // 全高側欄是螢幕設計，它撐不過分頁。紙本改用標準商用文件結構：
        // 資訊帶在上（短、固定在第一頁），品項表全寬往下流。
        $html .= '<table class="info"><tr>';

        $html .= '<td class="info-l"><div class="info-h">報價對象</div>';
        $html .= '<div class="info-strong">' . e((string) ($quote['customer_name'] ?? '')) . '</div>';
        foreach (['customer_tax_id' => '統一編號', 'customer_phone' => '電話', 'customer_email' => 'Email', 'customer_address' => '地址'] as $key => $label) {
            $val = trim((string) ($quote[$key] ?? ''));
            if ($val === '') {
                continue;
            }
            $html .= '<div class="info-line">' . e($label) . '：' . e($val) . '</div>';
        }
        $html .= '</td>';

        $html .= '<td class="info-r"><div class="info-h">報價資訊</div><table class="kv">';
        foreach ([
            '編號'     => (string) ($quote['quote_number'] ?? ''),
            '日期'     => substr((string) ($quote['created_at'] ?? ''), 0, 10),
            '有效期限' => substr((string) ($quote['valid_until'] ?? ''), 0, 10),
        ] as $label => $val) {
            if ($val === '') {
                continue;
            }
            $html .= '<tr><td class="k">' . e($label) . '</td><td class="v">' . e($val) . '</td></tr>';
        }
        $html .= '</table></td></tr></table>';

        if (trim((string) ($quote['title'] ?? '')) !== '') {
            $html .= '<div class="subject">' . e((string) $quote['title']) . '</div>';
        }

        // 品項表：全寬、可跨頁。thead 設 table-header-group，跨頁時表頭會重複出現 ——
        // 沒有這個，第二頁的數字就沒有欄位名稱，讀的人得翻回第一頁對照。
        $html .= '<table class="items"><thead><tr>'
               . '<th class="c-name">品項與描述</th>'
               . '<th class="c-num">單價</th>'
               . '<th class="c-qty">數量</th>'
               . '<th class="c-num">金額</th>'
               . '</tr></thead><tbody>';
        foreach ($items as $it) {
            $html .= '<tr>';
            $html .= '<td class="c-name"><span class="it-name">' . e((string) ($it['name'] ?? '')) . '</span>';
            $desc = trim((string) ($it['description'] ?? ''));
            if ($desc !== '') {
                $html .= '<div class="it-desc">' . nl2br(e($desc)) . '</div>';
            }
            $html .= '</td>';
            $html .= '<td class="c-num">' . e($money($it['unit_price'] ?? 0)) . '</td>';
            // 🔴 這一格原本只印 unit（「式」），數量整個不見 —— 客戶收到的文件上
            // 單價與金額都在，中間那格卻是「式」，無法核對金額怎麼算出來的。
            // 與畫面版 views/public/quote/show.php 同一套格式：數量 + 單位。
            $qty  = $qtyFmt($it['qty'] ?? 0);
            $unit = trim((string) ($it['unit'] ?? ''));
            $html .= '<td class="c-qty">' . e($unit !== '' ? $qty . ' ' . $unit : $qty) . '</td>';
            $html .= '<td class="c-num">' . e($money($it['amount'] ?? 0)) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        // 合計
        $taxRate = (float) ($quote['tax_rate'] ?? 0);
        $html .= '<table class="totals">';
        $html .= '<tr><td class="t-k">小計</td><td class="t-v">' . e($money($quote['subtotal'] ?? 0)) . '</td></tr>';
        $html .= '<tr><td class="t-k">稅額'
               . ($taxRate > 0 ? '（' . e(rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.')) . '%）' : '')
               . '</td><td class="t-v">' . e($money($quote['tax'] ?? 0)) . '</td></tr>';
        $html .= '<tr class="grand"><td class="t-k">總計</td><td class="t-v">' . e($money($quote['total'] ?? 0)) . '</td></tr>';
        $html .= '</table>';

        if ((string) ($quote['payment_status'] ?? '') === 'paid' || $status === 'paid') {
            $html .= '<div class="paid">已完成付款　感謝您！本報價單款項已收訖。</div>';
        }

        $terms = trim((string) ($quote['terms'] ?? ''));
        if ($terms !== '') {
            $html .= '<div class="terms-h">備註與條款</div><div class="terms">' . nl2br(e($terms)) . '</div>';
        }

        // 用印 / 簽章。整塊不可被切開 —— 簽名被切成兩半的文件不能用。
        $html .= '<table class="sig"><tr>';
        $html .= '<td class="sig-cell"><div class="sig-h">我方用印</div><div class="sig-box">';
        if ($sealPath !== null) {
            $html .= '<img src="' . e($sealPath) . '" class="seal">';
        } else {
            $html .= '<span class="sig-placeholder">（公司印章）</span>';
        }
        $html .= '</div></td>';

        $html .= '<td class="sig-cell"><div class="sig-h">客戶簽章</div><div class="sig-box">';
        $sigData = (string) ($quote['signature_data'] ?? '');
        if ($sigData !== '' && str_starts_with($sigData, 'data:image/')) {
            $html .= '<img src="' . e($sigData) . '" class="sig-img">';
        } else {
            $html .= '<span class="sig-placeholder">（客戶簽名）</span>';
        }
        $html .= '</div>';
        $signer = trim((string) ($quote['signer_name'] ?? ''));
        if ($signer !== '') {
            $html .= '<div class="sig-meta">' . e($signer) . '</div>';
        }
        if ((string) ($quote['signed_at'] ?? '') !== '') {
            $html .= '<div class="sig-meta">' . e(substr((string) $quote['signed_at'], 0, 19)) . '</div>';
        }
        // 🔴 實際欄位是 document_hash（migration 033）。原本只讀 signature_hash ——
        // 那個 key 從來不存在，所以已簽署的 PDF 上這一行永遠不會出現。
        // 畫面版有 ?? fallback 所以顯示正常，兩邊因此長期不一致而沒人發現。
        $docHash = (string) ($quote['document_hash'] ?? '');
        if ($docHash !== '') {
            $html .= '<div class="sig-meta">SHA-256: ' . e(substr($docHash, 0, 24)) . '…</div>';
        }
        $html .= '</td></tr></table>';

        $html .= '</body></html>';

        return $html;
    }

    /**
     * 頁尾（每頁都出現）。
     *
     * 🔴 這是 PDF 相對於瀏覽器列印的實際優勢：Chrome **不支援** CSS 的
     * `@page { @bottom-right { content: counter(page) } }`，頁碼只能由瀏覽器自己
     * 加在頁首頁尾（而那也正是會印出網址與日期的東西）。改用 PDF 之後，
     * 頁碼由我們自己畫，而且不會夾帶網址。
     */
    public static function footer(array $quote, array $company): string
    {
        $name = (string) ($company['name'] ?? '') !== '' ? (string) $company['name'] : 'YANGSHEEP DESIGN';

        return '<table style="width:100%;font-size:7.5pt;color:#8a8a8a;border-top:0.4pt solid #ddd;padding-top:3mm">'
             . '<tr><td style="text-align:left">' . e((string) ($quote['quote_number'] ?? '')) . '　' . e($name) . '</td>'
             . '<td style="text-align:right">第 {PAGENO} 頁／共 {nbpg} 頁</td></tr></table>';
    }

    /**
     * 把設定的配色轉成這份版面用得到的顏色。
     *
     * @param array<string,mixed> $theme
     * @return array<string,string>
     */
    public static function cssVars(array $theme): array
    {
        // QuoteTheme::resolve() 回傳的是 "--doc-x: #hex;" 串接字串；解析回陣列。
        $out = [];
        preg_match_all('/--([a-z0-9-]+)\s*:\s*(#[0-9a-fA-F]{3,6})\s*;/', (string) ($theme['css'] ?? ''), $m, PREG_SET_ORDER);
        foreach ($m as $one) {
            $out[$one[1]] = strtolower($one[2]);
        }

        // 少了任何一個就用暖米色的預設值補齊 —— PDF 不能因為缺一個變數就變成沒有顏色。
        return $out + [
            'doc-text'        => '#2a2a2a',
            'doc-muted'       => '#6b6258',
            'doc-line'        => '#d8c9b6',
            'doc-strong-line' => '#2a2a2a',
            'doc-brand'       => '#6b4f30',
            'doc-brand-2'     => '#7a5a36',
            'doc-rail'        => '#f7f0e6',
            'doc-rail-line'   => '#d4c2aa',
            'doc-soft'        => '#faf7f2',
        ];
    }

    /**
     * 把公開 URL 轉成本機檔案路徑。
     *
     * 🔴 mPDF 取圖時若給的是 http(s) 網址，它會**從伺服器對自己發一次 HTTP 請求**。
     * 那在只綁內部位址、或憑證不被自己信任的環境會直接失敗，而且失敗方式是
     * 「圖沒了但 PDF 還是產得出來」—— 沒有人會發現。直接餵本機路徑最穩。
     */
    private static function localPath(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        // 只接受本站的 /uploads/... 之類相對路徑或同站絕對網址
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        // 🔴 【必須用 realpath 收斂，不能只看 is_file】parse_url 不會解 `%2e%2e`，
        // 也不會處理 `..`；`$root . $path` 只是字串相接。設定裡的 Logo 網址雖然
        // 由管理員填寫，但它會變成「讀哪個檔案再嵌進 PDF」的依據 ——
        // 那是任意檔案讀取的形狀，不該靠「來源可信」當作唯一防線。
        //
        // realpath() 會解掉 `..`、symlink 與編碼後的路徑，再確認結果仍在允許的
        // 根目錄之下；跳出去的一律拒絕。
        foreach ([BASE_PATH . '/public_html', BASE_PATH] as $root) {
            $realRoot = realpath($root);
            if ($realRoot === false) {
                continue;
            }

            $candidate = realpath($root . '/' . ltrim(rawurldecode($path), '/'));
            if ($candidate === false || !is_file($candidate)) {
                continue;
            }

            // 前綴比對要帶目錄分隔字元，否則 /var/www/appEVIL 會被當成在 /var/www/app 之下。
            if (str_starts_with($candidate, $realRoot . DIRECTORY_SEPARATOR)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param array<string,string> $v */
    private static function styles(array $v): string
    {
        $text   = $v['doc-text'];
        $muted  = $v['doc-muted'];
        $line   = $v['doc-line'];
        $strong = $v['doc-strong-line'];
        $brand  = $v['doc-brand'];
        $rail   = $v['doc-rail'];
        $railLn = $v['doc-rail-line'];
        $soft   = $v['doc-soft'];

        return <<<CSS
        /* 🔴 字型必須是 sun-exta（mPDF 內建的 CJK 字型），不要改成 sans-serif 或系統字型。
           正式機實測（2026-09-02）四種組合：
             sans-serif             → 全形標點緊鄰拉丁字時變方框（幣別：TWD 的冒號）
             系統 DroidSansFallback → 中文正確但**所有英數字**變方框
             backupSubsFont 遞補    → 中文全毀
             sun-exta               → 中英數與全形標點全部正確  ✅
           mPDF 依書寫系統切段換字型，緊鄰拉丁的全形標點會被歸給拉丁字型而缺字。
           使用者輸入的條款與品項描述必然出現這種組合，所以不能靠「避開某些字元」解決。
           代價是拉丁字形偏襯線 —— 但任何一個字元靜默變方框的代價更高。 */
        body { font-family: sun-exta; font-size: 9.5pt; color: {$text}; line-height: 1.55; }

        .head { width: 100%; margin-bottom: 6mm; }
        .head-brand { width: 42%; vertical-align: top; }
        .head-title { width: 58%; vertical-align: top; text-align: right; }
        .logo { width: 26mm; }
        .brand-name { font-size: 12pt; font-weight: bold; color: {$brand}; letter-spacing: 0.5pt; margin-top: 2mm; }
        .doc-title { font-size: 21pt; font-weight: bold; color: {$brand}; letter-spacing: 5pt; }
        .meta { font-size: 8pt; color: {$muted}; }

        /* 資訊帶：短、固定在第一頁；不包住主體，主體才能跨頁流動。 */
        .info { width: 100%; margin-bottom: 6mm; border-collapse: collapse; }
        .info-l { width: 58%; background-color: {$rail}; border: 0.5pt solid {$railLn};
                  padding: 4mm 5mm; vertical-align: top; }
        .info-r { width: 42%; padding: 4mm 0 4mm 6mm; vertical-align: top; }
        .info-h { font-size: 8pt; font-weight: bold; color: {$brand}; margin-bottom: 1.5mm; }
        .info-strong { font-weight: bold; font-size: 10.5pt; margin-bottom: 1mm; }
        .info-line { font-size: 8.5pt; color: {$muted}; }
        .kv { width: 100%; font-size: 8.5pt; border-collapse: collapse; }
        .kv .k { color: {$muted}; padding: 0.8mm 0; }
        .kv .v { text-align: right; font-weight: bold; padding: 0.8mm 0; }

        .subject { font-weight: bold; font-size: 10.5pt; margin-bottom: 3mm; }

        .items { width: 100%; border-collapse: collapse; }
        .items th { background-color: {$soft}; color: {$brand}; font-size: 8pt; font-weight: bold;
                    text-align: left; padding: 2.5mm; border-bottom: 0.8pt solid {$line}; }
        .items td { padding: 2.5mm; border-bottom: 0.4pt solid {$line}; vertical-align: top; }
        .c-num { text-align: right; white-space: nowrap; }
        .c-qty { text-align: center; white-space: nowrap; }
        .it-name { font-weight: bold; }
        .it-desc { font-size: 8pt; color: {$muted}; margin-top: 0.8mm; }

        .totals { width: 45%; margin-left: 55%; margin-top: 4mm; border-collapse: collapse; }
        .totals .t-k { padding: 1.6mm 0; color: {$muted}; }
        .totals .t-v { padding: 1.6mm 0; text-align: right; font-weight: bold; }
        .totals .grand .t-k, .totals .grand .t-v { font-size: 12pt; color: {$text};
                    border-top: 1pt solid {$strong}; padding-top: 2.5mm; }

        .paid { margin-top: 5mm; padding: 3mm; text-align: center; font-size: 9pt;
                background-color: {$soft}; border: 0.5pt solid {$line}; }

        .terms-h { margin-top: 6mm; font-size: 8.5pt; font-weight: bold; color: {$brand}; }
        .terms { font-size: 8.5pt; color: {$muted}; }

        .sig { width: 100%; margin-top: 7mm; border-collapse: collapse; }
        .sig-cell { width: 50%; padding-right: 4mm; vertical-align: top; }
        .sig-h { font-size: 8pt; color: {$muted}; margin-bottom: 1.5mm; }
        .sig-box { height: 26mm; border: 0.5pt solid {$line}; text-align: center; padding-top: 8mm; }
        .sig-placeholder { color: {$muted}; font-size: 8.5pt; }
        .seal { width: 24mm; }
        .sig-img { height: 18mm; }
        .sig-meta { font-size: 7pt; color: {$muted}; margin-top: 1mm; }
        CSS;
    }
}
