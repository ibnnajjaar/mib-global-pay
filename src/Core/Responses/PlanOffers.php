<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Responses;

use IbnNajjaar\MIBGlobalPay\Core\Enums\Result;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;

/**
 * Plan Offers Inquiry response.
 */
final readonly class PlanOffers
{
    /**
     * @param list<Payload> $offers each with finalAmount, numberOfPayments and paymentAmount
     */
    private function __construct(
        public ?string $planId,
        public ?string $interestRate,
        public array $offers,
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
            planId: $p->string('paymentPlan.planId'),
            interestRate: $p->string('paymentPlan.interestRate'),
            offers: $p->list('paymentPlan.planOffer'),
            result: $p->enum('result', Result::class),
            payload: $p,
        );
    }
}
