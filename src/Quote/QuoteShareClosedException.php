<?php

declare(strict_types=1);

namespace YangSheep\CRM\Quote;

/**
 * 提交當下（交易內重讀）發現匿名分享已關閉或過期。
 *
 * 呼叫端必須回應與「查無報價」完全相同的通用頁，且不附任何 flash 訊息——
 * 否則回應差異本身就洩漏了「這個 token 曾經有效」。
 */
final class QuoteShareClosedException extends \RuntimeException
{
}
