<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Webhook;

use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;
use IbnNajjaar\MIBGlobalPay\Core\Responses\TransactionResponse;

/**
 * Notification for Initiate Authentication / Authenticate Payer. It carries the authentication
 * only, "not the outcome of the financial transaction" (MIB webhook guide).
 */
final readonly class AuthenticationNotification implements Notification
{
    private function __construct(
        private string $id,
        private ?int $attempt,
        public ?string $orderId,
        public TransactionResponse $transaction,
        private Payload $payload,
    ) {
    }

    /**
     * @internal built by NotificationVerifier
     *
     * @throws MalformedPayload
     */
    public static function fromPayload(Payload $payload, string $id, ?int $attempt): self
    {
        return new self($id, $attempt, $payload->string('order.id'), TransactionResponse::fromPayload($payload), $payload);
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
        return NotificationKind::Authentication;
    }

    public function payload(): Payload
    {
        return $this->payload;
    }
}
