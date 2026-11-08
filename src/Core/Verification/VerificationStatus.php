<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Verification;

/**
 * The outcome of verifyCapture(). Only Captured means "paid for exactly the expected amount".
 */
enum VerificationStatus: string
{
    /** An approved payment or capture exists, and the captured total equals the expected amount in the same currency. */
    case Captured = 'captured';

    /** Part of the authorized amount has been captured, and the rest can still be. */
    case PartiallyCaptured = 'partially_captured';

    /** An approved authorization holds the funds, and nothing has been captured yet. */
    case Authorized = 'authorized';

    /** The payer's account was verified. No money moved. */
    case Verified = 'verified';

    /** Money was captured, but not exactly the expected amount (over, under, or totalCapturedAmount missing). */
    case AmountMismatch = 'amount_mismatch';

    /** Money was captured in a different currency than expected. */
    case CurrencyMismatch = 'currency_mismatch';

    /** No approved payment, capture, authorization or verification (initiated, declined, voided, ...). */
    case NotCaptured = 'not_captured';

    /** The latest transaction is still pending or unknown; ask again later. */
    case Pending = 'pending';

    /** Money was captured and then fully refunded or voided. */
    case Reversed = 'reversed';

    /** The gateway has no such order. */
    case NotFound = 'not_found';
}
