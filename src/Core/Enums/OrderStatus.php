<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Enums;

/**
 * Values of order.status (Retrieve Order, API v100).
 *
 * Unknown values from the gateway map to {@see self::Unrecognized} instead of throwing.
 */
enum OrderStatus: string implements GatewayEnum
{
    use ResolvesFromGateway;

    case Authenticated = 'AUTHENTICATED';
    case AuthenticationInitiated = 'AUTHENTICATION_INITIATED';
    case AuthenticationNotNeeded = 'AUTHENTICATION_NOT_NEEDED';
    case AuthenticationUnsuccessful = 'AUTHENTICATION_UNSUCCESSFUL';
    case Authorized = 'AUTHORIZED';
    case Cancelled = 'CANCELLED';
    case Captured = 'CAPTURED';
    case ChargebackProcessed = 'CHARGEBACK_PROCESSED';
    case Disbursed = 'DISBURSED';
    case Disputed = 'DISPUTED';
    case ExcessivelyRefunded = 'EXCESSIVELY_REFUNDED';
    case Failed = 'FAILED';
    case Funding = 'FUNDING';
    case Initiated = 'INITIATED';
    case PartiallyCaptured = 'PARTIALLY_CAPTURED';
    case PartiallyRefunded = 'PARTIALLY_REFUNDED';
    case Refunded = 'REFUNDED';
    case RefundRequested = 'REFUND_REQUESTED';
    case Verified = 'VERIFIED';

    /** The gateway sent a value this version of the package does not know. */
    case Unrecognized = '';
}
