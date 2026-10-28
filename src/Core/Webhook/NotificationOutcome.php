<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Webhook;

enum NotificationOutcome: string
{
    /** Handled; an outcome event was dispatched. */
    case Processed = 'processed';

    /** Verified but nothing to do (e.g. an authentication record). */
    case Ignored = 'ignored';

    /** Processing failed after the claim; see logs. */
    case Failed = 'failed';
}
