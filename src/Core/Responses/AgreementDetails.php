<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Responses;

use IbnNajjaar\MIBGlobalPay\Core\Enums\Result;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;

/**
 * Retrieve Agreement response.
 */
final readonly class AgreementDetails
{
    private function __construct(
        public ?string $id,
        public ?string $type,
        public ?string $status,
        public ?string $expiryDate,
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
            id: $p->string('agreement.id') ?? $p->string('id'),
            type: $p->string('agreement.type') ?? $p->string('type'),
            status: $p->string('agreement.status') ?? $p->string('status'),
            expiryDate: $p->string('agreement.expiryDate') ?? $p->string('expiryDate'),
            result: $p->has('result') ? $p->enum('result', Result::class) : Result::Success,
            payload: $p,
        );
    }
}
