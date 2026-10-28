<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Enums;

/**
 * Values of response.gatewayRecommendation (Retrieve Order, API v100).
 *
 * Unknown values from the gateway map to {@see self::Unrecognized} instead of throwing.
 */
enum GatewayRecommendation: string implements GatewayEnum
{
    use ResolvesFromGateway;

    case CheckTransactionStatusLater = 'CHECK_TRANSACTION_STATUS_LATER';
    case ContactPaymentProvider = 'CONTACT_PAYMENT_PROVIDER';
    case DoNotProceed = 'DO_NOT_PROCEED';
    case DoNotProceedAbandonOrder = 'DO_NOT_PROCEED_ABANDON_ORDER';
    case DoNotProceedContactPayer = 'DO_NOT_PROCEED_CONTACT_PAYER';
    case NoAction = 'NO_ACTION';
    case Proceed = 'PROCEED';
    case ResubmitLater = 'RESUBMIT_LATER';
    case ResubmitWith3ds = 'RESUBMIT_WITH_3DS';
    case ResubmitWithAlternativePaymentDetails = 'RESUBMIT_WITH_ALTERNATIVE_PAYMENT_DETAILS';
    case ResubmitWithPayerAuthentication = 'RESUBMIT_WITH_PAYER_AUTHENTICATION';
    case ResubmitWithPin = 'RESUBMIT_WITH_PIN';
    case ResubmitWithUpdatedPaymentDetails = 'RESUBMIT_WITH_UPDATED_PAYMENT_DETAILS';
    case ReviewAuthenticationResult = 'REVIEW_AUTHENTICATION_RESULT';
    case ReviewRiskStatus = 'REVIEW_RISK_STATUS';

    /** The gateway sent a value this version of the package does not know. */
    case Unrecognized = '';
}
