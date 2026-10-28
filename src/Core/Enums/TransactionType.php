<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Enums;

/**
 * Values of transaction.type (Retrieve Order, API v100).
 *
 * Unknown values from the gateway map to {@see self::Unrecognized} instead of throwing.
 */
enum TransactionType: string implements GatewayEnum
{
    use ResolvesFromGateway;

    case Authentication = 'AUTHENTICATION';
    case Authorization = 'AUTHORIZATION';
    case AuthorizationUpdate = 'AUTHORIZATION_UPDATE';
    case Capture = 'CAPTURE';
    case Chargeback = 'CHARGEBACK';
    case Disbursement = 'DISBURSEMENT';
    case Funding = 'FUNDING';
    case Payment = 'PAYMENT';
    case Refund = 'REFUND';
    case RefundRequest = 'REFUND_REQUEST';
    case Verification = 'VERIFICATION';
    case VoidAuthorization = 'VOID_AUTHORIZATION';
    case VoidCapture = 'VOID_CAPTURE';
    case VoidPayment = 'VOID_PAYMENT';
    case VoidRefund = 'VOID_REFUND';

    /** The gateway sent a value this version of the package does not know. */
    case Unrecognized = '';
}
