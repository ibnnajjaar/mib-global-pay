<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Attempts;

/**
 * Why AttemptGuard refused a new attempt.
 */
enum GuardRefusal: string
{
    /** The payable is Cancelled, Failed or Paid. */
    case PayableNotPayable = 'payable_not_payable';

    /** The payable already has a settlement. */
    case AlreadySettled = 'already_settled';

    /** An attempt is recorded as Captured. */
    case AttemptCaptured = 'attempt_captured';

    /** The gateway reports money captured on an earlier attempt (run the capture pipeline). */
    case MoneyCaptured = 'money_captured';

    /** The gateway reports an earlier attempt as pending; ask again later. */
    case VerificationPending = 'verification_pending';

    /** An earlier attempt has not expired yet; re-display its session instead (see GuardResult::$reusableAttempt). */
    case AttemptStillLive = 'attempt_still_live';
}
