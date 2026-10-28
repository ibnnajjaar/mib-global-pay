<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Enums;

/**
 * Values of the top-level result field (API v100: FAILURE, PENDING, SUCCESS, UNKNOWN; ERROR is kept for older payloads).
 *
 * Unknown values from the gateway map to {@see self::Unrecognized} instead of throwing.
 */
enum Result: string implements GatewayEnum
{
    use ResolvesFromGateway;

    case Error = 'ERROR';
    case Failure = 'FAILURE';
    case Pending = 'PENDING';
    case Success = 'SUCCESS';
    case Unknown = 'UNKNOWN';

    /** The gateway sent a value this version of the package does not know. */
    case Unrecognized = '';
}
