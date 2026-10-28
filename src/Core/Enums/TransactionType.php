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

    /** PAYMENT or CAPTURE: moves money from the payer. AUTHORIZATION only reserves funds. */
    public function movesMoneyIn(): bool
    {
        return $this === self::Payment || $this === self::Capture;
    }

    /** Any of VOID_AUTHORIZATION, VOID_CAPTURE, VOID_PAYMENT, VOID_REFUND. */
    public function isVoid(): bool
    {
        return in_array($this, [self::VoidAuthorization, self::VoidCapture, self::VoidPayment, self::VoidRefund], true);
    }

    /** VOID_PAYMENT or VOID_CAPTURE: undoes money moved in. */
    public function reversesMoneyIn(): bool
    {
        return $this === self::VoidPayment || $this === self::VoidCapture;
    }

    /** The gateway sent a value this version of the package does not know. */
    case Unrecognized = '';
}
