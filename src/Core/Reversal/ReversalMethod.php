<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Reversal;

enum ReversalMethod: string
{
    /** The capture was voided (before settlement). */
    case Void = 'void';

    /**
     * The void was declined or rejected and a refund was sent. Check $reversed: when false, the
     * refund definitely failed and calling again with the same IDs sends nothing.
     */
    case Refund = 'refund';

    /**
     * The result of a void or refund is not known yet, or a rejected void could not be shown to be
     * absent or failed, so no refund was sent. Call again later: each call re-reads the order first.
     * Do not refund by other means meanwhile. If it stays like this, ask the acquirer: for
     * UNSPECIFIED_FAILURE the MIB docs say the gateway itself does not know the result.
     */
    case Unconfirmed = 'unconfirmed';

    /** Nothing to reverse: no approved payment/capture, or nothing left to refund. */
    case Nothing = 'nothing';
}
