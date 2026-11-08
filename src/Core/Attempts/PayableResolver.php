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
     * Attempts in state Created, Live or Authorized, or Expired without a final verified status, for
     * reconciliation. Also return attempts you retired recently (Superseded, or Failed after a person
     * confirmed them unpaid), so a late charge is noticed even if its webhook is lost. The pipeline
     * only reports money on those.
     *
     * @return iterable<ResolvedAttempt>
     */
    public function awaitingGateway(): iterable;
}
