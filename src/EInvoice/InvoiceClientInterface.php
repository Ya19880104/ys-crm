<?php

declare(strict_types=1);

namespace YangSheep\CRM\EInvoice;

/**
 * 電子發票金流商（開立商）介面。
 *
 * 目前唯一實作為 PayNow REST v1；抽介面是為了讓 InvoiceService 可在測試時
 * 注入假 client，完整覆蓋「查詢失敗 / 已存在 / timeout 補查」等分支而不需真實 API。
 *
 * 所有方法一律回傳結構化陣列、**不丟例外**：
 *   - success       bool   本次呼叫是否明確成功
 *   - indeterminate bool   結果不確定（timeout/5xx）——呼叫端必須補查而非直接判失敗
 *   - message       string 對外訊息
 *   - request_id    string 開立商的請求編號（對帳／客服追查用）
 *   - raw           array  原始回應（寫入 API log）
 */
interface InvoiceClientInterface
{
    /**
     * 開立發票。
     *
     * @param array<string,mixed> $payload InvoicePayloadBuilder 產出的 payload
     * @param array<string,mixed> $context correlation_id / invoice_id / payment_id 等記錄用脈絡
     * @return array<string,mixed> 成功時額外含 invoice_number / invoice_date / random_number / invoice
     */
    public function issueInvoice(array $payload, array $context = []): array;

    /**
     * 依訂單號查詢發票（開立前 reconcile 與 timeout 補查共用）。
     *
     * @param array<string,mixed> $context 可含 exclude_invoice_numbers（排除本地已作廢號）
     *                                     與 expected_total_amount（金額須相符才算命中）
     * @return array<string,mixed> 額外含 found:bool / invoice:?array
     */
    public function queryByOrder(string $orderNo, array $context = []): array;

    /** 依發票號碼查詢。 */
    public function queryByNumber(string $invoiceNumber, array $context = []): array;

    /** 作廢發票。 */
    public function cancelInvoice(string $invoiceNumber, array $context = []): array;

    /** 連線測試（驗證憑證與端點是否正確）。 */
    public function testConnection(): array;
}
