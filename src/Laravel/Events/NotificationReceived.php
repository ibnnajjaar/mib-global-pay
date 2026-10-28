<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Events;

use IbnNajjaar\MIBGlobalPay\Core\Webhook\Notification;

/** Every verified, non-duplicate notification, before processing (for audit). */
final readonly class NotificationReceived
{
    public function __construct(
        public Notification $notification,
    ) {
    }
}
