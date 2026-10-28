<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Webhook;

enum NotificationKind: string
{
    /** An order transaction: Pay/Authorize, Capture, Refund, Void, Update Authorization, Verify, Referral, Disbursement, Browser Payment. */
    case Transaction = 'transaction';

    /** A payer authentication (3-D Secure) record: no financial outcome. */
    case Authentication = 'authentication';

    /** Any other verified notification, e.g. Payment Alerts (modelled in 2.x). */
    case Other = 'other';
}
