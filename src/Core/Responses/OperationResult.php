<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Responses;

use IbnNajjaar\MIBGlobalPay\Core\Enums\Result;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;

/**
 * The answer to an operation that returns no details, such as a delete. An empty body counts as success.
 */
final readonly class OperationResult
{
    private function __construct(
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
            result: $p->has('result') ? $p->enum('result', Result::class) : Result::Success,
            payload: $p,
        );
    }
}
