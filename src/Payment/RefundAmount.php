<?php

declare(strict_types=1);

namespace YangSheep\CRM\Payment;

/**
 * 將管理介面的退款欄位轉為 PaymentService 的整數契約。
 *
 * 留空代表退完剩餘額度；其餘輸入必須在任何 `(int)` 轉型之前證明不會 overflow。
 */
final class RefundAmount
{
    public static function parse(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }

        $max = (string) PHP_INT_MAX;
        $valid = preg_match('/^[1-9][0-9]*$/D', $raw) === 1
            && (strlen($raw) < strlen($max)
                || (strlen($raw) === strlen($max) && strcmp($raw, $max) <= 0));

        if (!$valid) {
            throw new \RuntimeException('退款金額必須是正整數（留空代表全額退款）。');
        }

        return (int) $raw;
    }
}
