<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Events;

use IbnNajjaar\MIBGlobalPay\Core\Webhook\Notification;

/** A transaction notification for an order ID the PayableResolver does not know. */
final readonly class UnknownOrderNotification
{
    public function __construct(
        public string $gatewayOrderId,
        public Notification $notification,
    ) {
    }
}
