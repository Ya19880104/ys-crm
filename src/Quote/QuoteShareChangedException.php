<?php

declare(strict_types=1);

namespace YangSheep\CRM\Quote;

/**
 * 提交當下發現分享授權版本已變動（可見性、期限、token 或密碼在開啟頁面後被修改）。
 * 不沿用頁面開啟時的判斷，要求使用者重新開啟連結，以最新授權重新確認。
 */
final class QuoteShareChangedException extends \RuntimeException
{
}
