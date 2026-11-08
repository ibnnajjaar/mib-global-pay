<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Responses;

use IbnNajjaar\MIBGlobalPay\Core\Enums\Result;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;

/**
 * Retrieve Token or Save Token response. Card details are masked by the gateway.
 */
final readonly class TokenDetails
{
    private function __construct(
        public ?string $token,
        public Result $result,
        public ?string $status,
        public ?string $cardBrand,
        public ?string $maskedCardNumber,
        public ?string $expiryMonth,
        public ?string $expiryYear,
        public Payload $payload,
    ) {
    }

    /**
     * @throws MalformedPayload
     */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            token: $p->string('token'),
            result: $p->has('result') ? $p->enum('result', Result::class) : Result::Success,
            status: $p->string('status'),
            cardBrand: $p->string('sourceOfFunds.provided.card.brand'),
            maskedCardNumber: $p->string('sourceOfFunds.provided.card.number'),
            expiryMonth: $p->string('sourceOfFunds.provided.card.expiry.month'),
            expiryYear: $p->string('sourceOfFunds.provided.card.expiry.year'),
            payload: $p,
        );
    }
}
