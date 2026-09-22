<?php

declare(strict_types=1);

namespace YangSheep\CRM\Quote;

/**
 * 把 QuotePdfDocument 產生的 HTML 轉成 PDF（mPDF）。
 *
 * 【為何刻意只留這麼薄一層】mPDF 由 composer 安裝，而本專案核心用自寫
 * Autoloader、開發機也沒有 composer。所有會出錯的東西（版面、資料取值）
 * 都放在 QuotePdfDocument —— 那份可以在本機用純 PHP 測。
 * 這裡只剩「設定 + 呼叫」，是最不需要測、也最測不到的部分。
 *
 * 🔴 【字型設定的由來】不要改成註冊系統的 DroidSansFallback。
 * 正式機實測（2026-09-02）：以 Droid 當 default_font 時，中文正確但
 * **所有英數字都變成方框** —— 一張金額全是方框的報價單完全不能用，
 * 而且它產得出來、不會報錯。改用 mPDF 內建的 autoScriptToLang/autoLangToFont
 * 之後中英數皆正常（檔案從 8KB 變 50KB，可接受）。
 *
 * ⚠️ 全形直線「｜」在這個字型組合下仍是方框，本專案的 PDF 版面已避開不用。
 */
final class QuotePdfRenderer
{
    /** composer 的 autoload 相對於 app 根目錄的位置。 */
    private const AUTOLOAD = '/vendor/autoload.php';

    /** mPDF 是否可用（未安裝時要能優雅地退回列印，而不是白畫面）。 */
    public static function isAvailable(): bool
    {
        return is_file(BASE_PATH . self::AUTOLOAD);
    }

    /**
     * 產生 PDF 位元組。
     *
     * @param array<string,mixed> $quote
     * @param array<string,mixed> $company
     * @param array<string,mixed> $theme
     * @throws \RuntimeException mPDF 未安裝，或轉檔失敗
     */
    public static function render(array $quote, array $company, array $theme): string
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException('PDF 產生元件尚未安裝（vendor/mpdf）。');
        }

        require_once BASE_PATH . self::AUTOLOAD;

        if (!class_exists(\Mpdf\Mpdf::class)) {
            throw new \RuntimeException('PDF 產生元件載入失敗。');
        }

        // mPDF 需要可寫的暫存目錄。放在專案 storage 之下而不是系統 /tmp ——
        // 共用主機的 /tmp 可能被清、也可能被別的站台看到中間產物。
        $tempDir = BASE_PATH . '/storage/mpdf';
        if (!is_dir($tempDir) && !@mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            throw new \RuntimeException('無法建立 PDF 暫存目錄。');
        }

        $mpdf = new \Mpdf\Mpdf([
            'mode'             => 'utf-8',
            'format'           => 'A4',
            'margin_left'      => 12,
            'margin_right'     => 12,
            'margin_top'       => 12,
            'margin_bottom'    => 18,   // 留給頁尾的頁碼
            'margin_footer'    => 8,
            'tempDir'          => $tempDir,
            // 見類別註解：這兩個是中英數都能正確輸出的關鍵。
            'autoScriptToLang' => true,
            'autoLangToFont'   => true,
        ]);

        $number = (string) ($quote['quote_number'] ?? '報價單');
        $mpdf->SetTitle($number);
        $mpdf->SetAuthor((string) ($company['name'] ?? 'YANGSHEEP DESIGN'));
        $mpdf->SetCreator('YS CRM');
        // 🔴 不要把公開連結寫進 PDF 中繼資料：PDF 會被轉寄，
        // 而那個 token 就是檢視這份報價單的唯一憑證。
        $mpdf->SetSubject('報價單');

        $mpdf->SetHTMLFooter(QuotePdfDocument::footer($quote, $company));
        $mpdf->WriteHTML(QuotePdfDocument::build($quote, $company, $theme));

        $bytes = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);

        if (!is_string($bytes) || !str_starts_with($bytes, '%PDF')) {
            throw new \RuntimeException('PDF 產生結果不是有效的檔案。');
        }

        return $bytes;
    }

    /**
     * 下載用的檔名。
     *
     * 只保留英數與 -_.：檔名會進 Content-Disposition，
     * 換行或引號可以拆出額外的標頭（response splitting）。
     */
    public static function filename(array $quote): string
    {
        $base = (string) ($quote['quote_number'] ?? 'quote');
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '', $base) ?: 'quote';

        return $safe . '.pdf';
    }
}
