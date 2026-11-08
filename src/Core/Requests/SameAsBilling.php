<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Requests;

/**
 * Values of shipping.address.sameAsBilling.
 */
enum SameAsBilling: string
{
    case Different = 'DIFFERENT';
    case Same = 'SAME';
    case Unknown = 'UNKNOWN';
}
