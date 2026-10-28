<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Enums;

/**
 * Values of response.gatewayCode (Retrieve Order, API v100).
 *
 * Unknown values from the gateway map to {@see self::Unrecognized} instead of throwing.
 */
enum GatewayCode: string implements GatewayEnum
{
    use ResolvesFromGateway;

    case Aborted = 'ABORTED';
    case AcquirerSystemError = 'ACQUIRER_SYSTEM_ERROR';
    case Approved = 'APPROVED';
    case ApprovedAuto = 'APPROVED_AUTO';
    case ApprovedPendingSettlement = 'APPROVED_PENDING_SETTLEMENT';
    case AuthenticationFailed = 'AUTHENTICATION_FAILED';
    case AuthenticationInProgress = 'AUTHENTICATION_IN_PROGRESS';
    case BalanceAvailable = 'BALANCE_AVAILABLE';
    case BalanceUnknown = 'BALANCE_UNKNOWN';
    case Blocked = 'BLOCKED';
    case Cancelled = 'CANCELLED';
    case Declined = 'DECLINED';
    case DeclinedAvs = 'DECLINED_AVS';
    case DeclinedAvsCsc = 'DECLINED_AVS_CSC';
    case DeclinedCsc = 'DECLINED_CSC';
    case DeclinedDoNotContact = 'DECLINED_DO_NOT_CONTACT';
    case DeclinedInvalidPin = 'DECLINED_INVALID_PIN';
    case DeclinedPaymentPlan = 'DECLINED_PAYMENT_PLAN';
    case DeclinedPinRequired = 'DECLINED_PIN_REQUIRED';
    case DeferredTransactionReceived = 'DEFERRED_TRANSACTION_RECEIVED';
    case DuplicateBatch = 'DUPLICATE_BATCH';
    case ExceededRetryLimit = 'EXCEEDED_RETRY_LIMIT';
    case ExpiredCard = 'EXPIRED_CARD';
    case InsufficientFunds = 'INSUFFICIENT_FUNDS';
    case InvalidCsc = 'INVALID_CSC';
    case LockFailure = 'LOCK_FAILURE';
    case NotEnrolled3dSecure = 'NOT_ENROLLED_3D_SECURE';
    case NotSupported = 'NOT_SUPPORTED';
    case NoBalance = 'NO_BALANCE';
    case PartiallyApproved = 'PARTIALLY_APPROVED';
    case Pending = 'PENDING';
    case Referred = 'REFERRED';
    case Submitted = 'SUBMITTED';
    case SystemError = 'SYSTEM_ERROR';
    case TimedOut = 'TIMED_OUT';
    case Unknown = 'UNKNOWN';
    case UnspecifiedFailure = 'UNSPECIFIED_FAILURE';

    /** The gateway sent a value this version of the package does not know. */
    case Unrecognized = '';
}
