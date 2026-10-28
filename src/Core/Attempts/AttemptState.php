<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Attempts;

/**
 * Lifecycle of one checkout attempt (DESIGN-v2.md §6.7 C):
 *
 *   Created    -> Live | Failed | Superseded   (Initiate Checkout succeeded / failed / never answered
 *                                              and the attempt was retired by AttemptGuard)
 *   Live       -> Captured | Expired | Failed | Superseded
 *   Expired    -> Captured | Failed | Superseded
 *   Superseded -> Captured                     (a late capture is still recorded; it is a double
 *                                              capture if the payable is already paid)
 *   Captured, Failed: final
 *
 * Failed means "finished without payment": Initiate Checkout failed, or the bank confirmed the
 * attempt as finally unpaid (e.g. on PaymentFailed). Superseded means "retired in favour of a
 * newer attempt".
 */
enum AttemptState: string
{
    case Created = 'created';
    case Live = 'live';
    case Captured = 'captured';
    case Failed = 'failed';
    case Expired = 'expired';
    case Superseded = 'superseded';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Created => [self::Live, self::Failed, self::Superseded],
            self::Live => [self::Captured, self::Expired, self::Failed, self::Superseded],
            self::Expired => [self::Captured, self::Failed, self::Superseded],
            self::Superseded => [self::Captured],
            self::Captured, self::Failed => [],
        }, true);
    }

    /**
     * Whether a payer may still pay on this attempt's session, so the gateway must be asked
     * before another attempt starts. Created counts: Initiate Checkout may have succeeded
     * without the response arriving.
     */
    public function mayStillBePaid(): bool
    {
        return in_array($this, [self::Created, self::Live, self::Expired], true);
    }
}
