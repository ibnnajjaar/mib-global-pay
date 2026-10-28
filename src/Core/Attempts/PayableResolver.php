<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Attempts;

/**
 * Maps gateway order IDs to the host's payables. Implemented and bound by the host.
 */
interface PayableResolver
{
    /** Any attempt's gateway order ID -> its payable and attempt; null if unknown to the host. */
    public function resolveAttempt(string $gatewayOrderId): ?ResolvedAttempt;

    /**
     * Attempts in state Created or Live, or Expired without a final verified status, for reconciliation.
     *
     * @return iterable<ResolvedAttempt>
     */
    public function awaitingGateway(): iterable;
}
