<?php
declare(strict_types=1);
namespace YangSheep\CRM\Payment;

/** Internal timestamp intent, never constructible from an HTTP/provider string. */
enum PaymentTimestamp
{
    case DatabaseNow;
}
