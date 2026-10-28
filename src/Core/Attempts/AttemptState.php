<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Attempts;

/**
 * Lifecycle of one checkout attempt (DESIGN-v2.md §6.7 C):
 *
 *   Created -> Live | Failed            (Initiate Checkout succeeded / failed)
 *   Live    -> Captured | Expired | Superseded
 *   Expired -> Captured | Superseded
 *   Superseded -> Captured              (a late capture is still recorded; it is a double capture if the payable is paid)
 *   Captured, Failed: final
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
            self::Created => [self::Live, self::Failed],
            self::Live => [self::Captured, self::Expired, self::Superseded],
            self::Expired => [self::Captured, self::Superseded],
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
