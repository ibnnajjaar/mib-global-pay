<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Reversal;

enum ReversalMethod: string
{
    /** The capture was voided (before settlement). */
    case Void = 'void';

    /** The void was declined or rejected, so the refundable amount was refunded. */
    case Refund = 'refund';

    /** Nothing to reverse: no approved payment/capture, or nothing left to refund. */
    case Nothing = 'nothing';
}
