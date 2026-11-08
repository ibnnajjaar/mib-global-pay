<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Checkout;

use RuntimeException;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\GuardResult;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MibGlobalPayException;

/**
 * No new attempt may start: the payable is settled, money is already held or captured, or an
 * earlier attempt is still pending. $result says why.
 */
final class CheckoutNotAllowed extends RuntimeException implements MibGlobalPayException
{
    public function __construct(public readonly GuardResult $result)
    {
        parent::__construct('A new checkout attempt is not allowed: ' . ($result->reason === null ? 'unknown reason' : $result->reason->value));
    }
}
