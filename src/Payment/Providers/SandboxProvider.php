<?php

declare(strict_types=1);

namespace YangSheep\CRM\Payment\Providers;

use YangSheep\CRM\Payment\PaymentProviderInterface;

/**
 * 沙盒金流商（測試用，對應架構設計 §7.9）。
 *
 * 目的：在沒有任何真實金流金鑰的情況下，仍能跑通「報價 → 簽署 → 付款 → 入帳」完整 e2e。
 *
 * 流程：
 *   1) createCheckout 回傳內部沙盒確認頁 URL（/pay/sandbox/{payment_no}），mode=redirect。
 *   2) 使用者在確認頁按「模擬付款成功 / 失敗」→ 該頁以 server-to-server 方式打 callback。
 *   3) verifyCallback 以 hash_hmac('sha256', payment_no, APP_KEY) 作為簽章驗證，
 *      模擬真實金流商的驗章機制（簽章來源為 server 自身的 APP_KEY，外部無法偽造）。
 *
 * 注意：沙盒不接觸真實金錢，但仍完整走過 payments 表的狀態機、冪等、金額比對與 audit log，
 * 確保切換到真實 provider 時行為一致。
 */
final class SandboxProvider implements PaymentProviderInterface
{
    public function key(): string
    {
        return 'sandbox';
    }

    /**
     * 計算沙盒簽章：hash_hmac('sha256', payment_no, APP_KEY)。
     * 對外公開（沙盒確認頁產生回呼時需用相同演算法）。
     */
    public static function sign(string $paymentNo): string
    {
        $appKey = (string) ($_ENV['APP_KEY'] ?? '');
        // APP_KEY 缺漏時退回空字串金鑰；驗章仍以「自身計算 vs 傳入」一致為準，
        // 但沙盒運行環境必有 APP_KEY（安裝精靈會寫入），此為防禦性處理。
        return hash_hmac('sha256', $paymentNo, $appKey);
    }

    /**
     * 沙盒不需要金鑰，永不 throw；回傳內部確認頁 URL（redirect）。
     */
    public function createCheckout(array $payment, string $returnUrl, string $callbackUrl): array
    {
        $paymentNo = (string) ($payment['payment_no'] ?? '');

        // 沙盒確認頁路徑：使用者在此選擇模擬成功/失敗。
        // return/callback 網址一併帶入（確認頁送出後據此回呼與導回）。
        $url = '/pay/sandbox/' . rawurlencode($paymentNo);

        return [
            'mode'         => 'redirect',
            'url'          => $url,
            'return_url'   => $returnUrl,
            'callback_url' => $callbackUrl,
        ];
    }

    /**
     * 驗證沙盒回呼。
     *
     * 預期 POST 欄位：
     *   - payment_no：付款編號
     *   - sign：hash_hmac('sha256', payment_no, APP_KEY)
     *   - result：'success' | 'fail'
     *   - amount：金額（整數字串）
     *
     * 驗章：以伺服器端重算的 sign 與傳入 sign 做 constant-time 比對（hash_equals）。
     */
    public function verifyCallback(array $post, array $server): array
    {
        $paymentNo = (string) ($post['payment_no'] ?? '');
        $sign      = (string) ($post['sign'] ?? '');
        $result    = (string) ($post['result'] ?? '');

        if ($paymentNo === '' || $sign === '') {
            return ['ok' => false];
        }

        $expected = self::sign($paymentNo);
        if (!hash_equals($expected, $sign)) {
            return ['ok' => false];
        }

        $status = $result === 'success' ? 'paid' : 'failed';
        $amount = (int) round((float) ($post['amount'] ?? 0));

        return [
            'ok'        => true,
            'txn_id'    => 'SBX-' . $paymentNo,
            'status'    => $status,
            'amount'    => $amount,
            'method'    => 'sandbox',
            'reference' => $paymentNo,
        ];
    }
}
