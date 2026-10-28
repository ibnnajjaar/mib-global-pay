<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Webhook;

use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;

/**
 * A verified notification that is not an order transaction, e.g. Payment Alerts.
 * Its body shape is not modelled yet (unverified; see MIB question 18). Use payload().
 */
final readonly class GenericNotification implements Notification
{
    private function __construct(
        private string $id,
        private ?int $attempt,
        private Payload $payload,
    ) {
    }

    /**
     * @internal built by NotificationVerifier
     */
    public static function fromPayload(Payload $payload, string $id, ?int $attempt): self
    {
        return new self($id, $attempt, $payload);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function attempt(): ?int
    {
        return $this->attempt;
    }

    public function kind(): NotificationKind
    {
        return NotificationKind::Other;
    }

    public function payload(): Payload
    {
        return $this->payload;
    }
}
