<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Responses;

use IbnNajjaar\MIBGlobalPay\Core\Enums\GatewayEnum;
use IbnNajjaar\MIBGlobalPay\Core\Enums\ResolvesFromGateway;

/**
 * Values of session.updateStatus. A session can only be used when this is SUCCESS.
 */
enum SessionUpdateStatus: string implements GatewayEnum
{
    use ResolvesFromGateway;

    case Failure = 'FAILURE';
    case NoUpdate = 'NO_UPDATE';
    case Success = 'SUCCESS';

    /** The gateway sent a value this version of the package does not know. */
    case Unrecognized = '';
}
