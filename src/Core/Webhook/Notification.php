<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Webhook;

use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;

/**
 * A webhook notification whose secret has been verified by NotificationVerifier.
 *
 * A notification is only a trigger: confirm payments with verifyCapture(), which re-reads
 * the order from the gateway. The gateway itself says webhooks are not for critical flows.
 */
interface Notification
{
    /** X-Notification-ID: identical for redeliveries of the same notification. */
    public function id(): string;

    /** X-Notification-Attempt, if sent. */
    public function attempt(): ?int;

    public function kind(): NotificationKind;

    public function payload(): Payload;
}
