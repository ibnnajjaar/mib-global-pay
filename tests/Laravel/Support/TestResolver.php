<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Tests\Laravel\Support;

use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableResolver;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\ResolvedAttempt;
use IbnNajjaar\MIBGlobalPay\Tests\Support\InMemoryAttempt;
use IbnNajjaar\MIBGlobalPay\Tests\Support\InMemoryPayable;

/**
 * Host-side resolver double: maps attempt order IDs to in-memory payables.
 */
final class TestResolver implements PayableResolver
{
    /** @var array<string, ResolvedAttempt> */
    public array $byOrderId = [];

    public function add(InMemoryPayable $payable): self
    {
        foreach ($payable->attempts as $attempt) {
            $this->byOrderId[$attempt->gatewayOrderId()] = new ResolvedAttempt($payable, $attempt);
        }

        return $this;
    }

    public function resolveAttempt(string $gatewayOrderId): ?ResolvedAttempt
    {
        return $this->byOrderId[$gatewayOrderId] ?? null;
    }

    public function awaitingGateway(): iterable
    {
        foreach ($this->byOrderId as $resolved) {
            /** @var InMemoryAttempt $attempt */
            $attempt = $resolved->attempt;
            if ($attempt->state()->mayStillBePaid()) {
                yield $resolved;
            }
        }
    }
}
