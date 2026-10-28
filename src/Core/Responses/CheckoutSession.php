<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Responses;

use IbnNajjaar\MIBGlobalPay\Core\Enums\Result;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;

/**
 * Initiate Checkout response. Persist sessionId, successIndicator and sessionVersion
 * before redirecting the payer.
 */
final readonly class CheckoutSession
{
    private function __construct(
        public string $sessionId,
        public string $successIndicator,
        public ?string $sessionVersion,
        public SessionUpdateStatus $updateStatus,
        public Result $result,
        public Payload $payload,
    ) {
    }

    /**
     * @throws MalformedPayload when the session ID or success indicator is missing
     */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            sessionId: $p->requireString('session.id'),
            successIndicator: $p->requireString('successIndicator'),
            sessionVersion: $p->string('session.version'),
            updateStatus: $p->enum('session.updateStatus', SessionUpdateStatus::class),
            result: $p->enum('result', Result::class),
            payload: $p,
        );
    }
}
