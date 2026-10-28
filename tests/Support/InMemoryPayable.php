<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Tests\Support;

use Closure;
use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Attempt;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Payable;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Settlement;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableState;

/**
 * Test double for the host payable.
 */
final class InMemoryPayable implements Payable
{
    public int $locks = 0;

    /** @param list<Attempt> $attempts newest first */
    public function __construct(
        public Money $expected,
        public PayableState $state = PayableState::Frozen,
        public array $attempts = [],
        public ?Settlement $settlement = null,
        public string $reference = '(R)IUM/FN/2026/123',
    ) {
    }

    public function payableReference(): string
    {
        return $this->reference;
    }

    public function expectedAmount(): Money
    {
        return $this->expected;
    }

    public function payableState(): PayableState
    {
        return $this->state;
    }

    public function settledBy(): ?Settlement
    {
        return $this->settlement;
    }

    public function attempts(): iterable
    {
        return $this->attempts;
    }

    public function lock(Closure $callback): mixed
    {
        $this->locks++;

        return $callback();
    }
}
