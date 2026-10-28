<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Tests\Support;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Attempt;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptState;

/**
 * Test double for a checkout attempt.
 */
final class InMemoryAttempt implements Attempt
{
    public function __construct(
        public int $number,
        public string $orderId,
        public Money $amount,
        public AttemptState $state,
        public \DateTimeImmutable $expiresAt,
        public ?string $sessionId = 'SESSION0001',
        public ?string $successIndicator = 'abc',
    ) {
    }

    public function attemptNumber(): int
    {
        return $this->number;
    }

    public function gatewayOrderId(): string
    {
        return $this->orderId;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    public function successIndicator(): ?string
    {
        return $this->successIndicator;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function state(): AttemptState
    {
        return $this->state;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
