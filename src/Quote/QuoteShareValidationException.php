<?php

declare(strict_types=1);

namespace YangSheep\CRM\Quote;

/**
 * 分享期限設定不合法或缺少必要確認（無期限確認、重新公開確認、縮短即關閉確認、天數範圍）。
 *
 * field 對應表單欄位（days／ack／reopen／close_now），供編輯頁把錯誤標在該欄位旁，
 * 並保留使用者剛才的輸入重新顯示，而不是清空整張表單。
 */
final class QuoteShareValidationException extends \RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
