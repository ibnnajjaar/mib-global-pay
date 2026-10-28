<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Responses;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;

/**
 * Retrieve Session / Update Session response: what a live session will charge.
 */
final readonly class SessionDetails
{
    private function __construct(
        public string $sessionId,
        public SessionUpdateStatus $updateStatus,
        public ?string $version,
        public ?string $orderId,
        public ?Money $orderAmount,
        public Currency $orderCurrency,
        public ?string $interactionOperation,
        public Payload $payload,
    ) {
    }

    /**
     * @throws MalformedPayload
     */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            sessionId: $p->requireString('session.id'),
            updateStatus: $p->enum('session.updateStatus', SessionUpdateStatus::class),
            version: $p->string('session.version'),
            orderId: $p->string('order.id'),
            orderAmount: $p->money('order.amount', 'order.currency'),
            orderCurrency: $p->currency('order.currency'),
            interactionOperation: $p->string('interaction.operation'),
            payload: $p,
        );
    }

    public function isUsable(): bool
    {
        return $this->updateStatus === SessionUpdateStatus::Success;
    }
}
