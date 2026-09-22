<?php

declare(strict_types=1);

namespace YangSheep\CRM\Payment;

/**
 * 金流商抽象介面（對應架構設計 §7.9）。
 *
 * 沿用 ys-cart gateway 模式：核心只認介面，不認具體金流商；新增金流商只需實作此介面
 * 並註冊到 PaymentProviderRegistry。
 *
 * Zero Trust 約束：
 *   - createCheckout 不接受、也不回傳前端傳入的金額；金額來源一律是伺服器端 payment 陣列。
 *   - 金鑰缺漏時必須 throw \RuntimeException（fail-closed，不可降級為明文/無簽章請求）。
 *   - verifyCallback 必須驗章；驗章失敗一律回 ['ok' => false]，呼叫端據此回 4xx 不入帳。
 */
interface PaymentProviderInterface
{
    /**
     * 金流商代碼（與 payments.provider、設定 payment_provider 對應）。
     * 例：'sandbox' / 'payuni' / 'shopline'。
     */
    public function key(): string;

    /**
     * 建立結帳請求（導向金流商付款頁）。
     *
     * @param array<string, mixed> $payment    付款列（含 payment_no / amount / currency …），金額以此為準。
     * @param string               $returnUrl  付款完成後使用者瀏覽器導回的網址（顯示結果用，非入帳依據）。
     * @param string               $callbackUrl 金流商 server-to-server 通知（webhook）網址，實際入帳依據。
     *
     * @return array{mode: string, url?: string, fields?: array<string, string>, ...}
     *   - mode='redirect'：直接 302 導向 url（GET）。
     *   - mode='form'：產生自動送出的 POST 表單到 url，欄位為 fields。
     *
     * @throws \RuntimeException 金鑰未設定時（fail-closed）。
     */
    public function createCheckout(array $payment, string $returnUrl, string $callbackUrl): array;

    /**
     * 驗證金流商回呼（webhook）並解析結果。
     *
     * @param array<string, mixed> $post   回呼 POST 內容（已正規化為關聯陣列）。
     * @param array<string, mixed> $server 伺服器變數（必要時取 header 驗章用）。
     *
     * @return array{ok: bool, txn_id?: string, status?: string, amount?: int, method?: ?string, reference?: string}
     *   - ok=true：驗章成功。status 為 'paid' | 'failed'；amount 為整數金額（與 payment.amount 比對）；
     *     txn_id 為金流交易序號（寫入 provider_txn_id，防重放）；reference 為本系統付款編號
     *     payment_no（驗章/解密後取得，供呼叫端定位付款列，免再以外層明文猜測）。
     *   - ok=false：驗章失敗（呼叫端回 4xx，絕不入帳）。
     */
    public function verifyCallback(array $post, array $server): array;
}
