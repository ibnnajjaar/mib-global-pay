<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Requests;

/**
 * Values of agreement.amountVariability.
 */
enum AmountVariability: string
{
    case Fixed = 'FIXED';
    case Variable = 'VARIABLE';
}
