<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Checkout;

use RuntimeException;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationResult;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MibGlobalPayException;

/**
 * confirmUnpaid() was refused, because the gateway now reports a result for the attempt.
 */
final class AttemptNotUnpaid extends RuntimeException implements MibGlobalPayException
{
    public function __construct(public readonly VerificationResult $verification)
    {
        parent::__construct('The gateway reports this attempt as ' . $verification->status->value . ', so it cannot be confirmed unpaid');
    }
}
