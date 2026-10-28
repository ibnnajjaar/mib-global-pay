<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Events;

enum MismatchReason: string
{
    /** Money captured, but not exactly the payable's frozen amount (or the total was missing). */
    case AmountMismatch = 'amount_mismatch';

    /** Money captured in a different currency. */
    case CurrencyMismatch = 'currency_mismatch';

    /** A second capture for a payable that is already settled by another attempt or transaction. */
    case DoubleCapture = 'double_capture';
}
