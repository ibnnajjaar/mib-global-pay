<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Responses;

use IbnNajjaar\MIBGlobalPay\Core\Enums\Result;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;

/**
 * Initiate Checkout response for a payment link. Share $url with the payer, and keep $id and $successIndicator.
 */
final readonly class PaymentLink
{
    private function __construct(
        public string $id,
        public string $url,
        public ?\DateTimeImmutable $expiresAt,
        public ?int $allowedAttempts,
        public ?string $successIndicator,
        public Result $result,
        public Payload $payload,
    ) {
    }

    /**
     * @throws MalformedPayload
     */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            id: $p->requireString('paymentLink.id'),
            url: $p->requireString('paymentLink.url'),
            expiresAt: $p->dateTime('paymentLink.expiryDateTime'),
            allowedAttempts: $p->int('paymentLink.numberOfAllowedAttempts'),
            successIndicator: $p->string('successIndicator'),
            result: $p->enum('result', Result::class),
            payload: $p,
        );
    }
}
