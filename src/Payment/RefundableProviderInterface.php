<?php

declare(strict_types=1);

namespace YangSheep\CRM\Payment;

/**
 * 支援退款與交易查詢的金流商（可選能力介面）。
 *
 * 【為何另開介面而不是擴充 PaymentProviderInterface】
 * Sandbox 與 SHOPLINE 目前都沒有退款實作。若把 refund() 加進主介面，
 * 這兩個 provider 會被迫寫假實作；而假的退款實作正是最危險的東西——
 * 一個「總是回成功」的 stub 會讓系統把未實際退款的交易標記為已退款。
 * 以可選介面表達「這個 provider 支援退款」，不支援者由呼叫端明確拒絕，
 * 使用者會看到「此金流商尚不支援線上退款」而非默默失敗。
 *
 * 【outcome 三態】所有寫入型操作必須回報三態之一：
 *   success            明確成功，可據以改寫本地狀態
 *   rejected_terminal  明確被拒（金額超限／狀態不符／驗章失敗），不需重試
 *   indeterminate      結果不確定（逾時／5xx），**不可改寫狀態**，須人工或查詢確認
 */
interface RefundableProviderInterface
{
    /**
     * 對已入帳的交易發動退款。
     *
     * @param array<string, mixed> $payment payments 表列（需含 provider_txn_id）
     * @param int $amount 退款金額（元）；0 代表全額
     * @return array{ok: bool, outcome: string, message: string, data?: array<string,mixed>}
     */
    public function refund(array $payment, int $amount = 0): array;

    /**
     * 查詢交易現況（退款前把關、對帳、不確定時確認結果）。
     *
     * @return array{ok: bool, outcome: string, message: string, data?: array<string,mixed>}
     */
    public function queryTrade(string $tradeNo, string $merTradeNo = ''): array;
}
