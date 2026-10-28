<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Responses;

use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;

/**
 * Check Gateway response, e.g. {"status":"OPERATING","gatewayVersion":"..."}.
 */
final readonly class GatewayStatus
{
    private function __construct(
        public ?string $status,
        public ?string $gatewayVersion,
        public Payload $payload,
    ) {
    }

    /**
     * @throws MalformedPayload
     */
    public static function fromPayload(Payload $p): self
    {
        return new self($p->string('status'), $p->string('gatewayVersion'), $p);
    }

    public function isOperating(): bool
    {
        return $this->status !== null && strtoupper($this->status) === 'OPERATING';
    }
}
