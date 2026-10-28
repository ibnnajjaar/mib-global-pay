<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Attempts;

/**
 * The host payable's state, as the package sees it. Hosts with a legacy status keep it in sync.
 */
enum PayableState: string
{
    case Open = 'open';
    case Frozen = 'frozen';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case Paid = 'paid';

    /** Open or Frozen: a capture settles it, and a new attempt may be started (subject to AttemptGuard). */
    public function isPayable(): bool
    {
        return $this === self::Open || $this === self::Frozen;
    }
}
