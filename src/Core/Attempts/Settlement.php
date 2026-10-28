<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Attempts;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;

/**
 * The capture that settled a payable.
 */
final readonly class Settlement
{
    public function __construct(
        public string $gatewayOrderId,
        public string $transactionId,
        public Money $amount,
        public \DateTimeImmutable $settledAt,
    ) {
    }

    public function isSameCapture(string $gatewayOrderId, ?string $transactionId): bool
    {
        return $this->gatewayOrderId === $gatewayOrderId && $this->transactionId === $transactionId;
    }
}
