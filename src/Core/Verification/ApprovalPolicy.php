<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Verification;

use IbnNajjaar\MIBGlobalPay\Core\Enums\Result;
use IbnNajjaar\MIBGlobalPay\Core\Enums\GatewayCode;

/**
 * The single approval rule, as data.
 *
 * A transaction is approved when result is SUCCESS and the gateway code is in an
 * explicit allow-list: APPROVED and APPROVED_PENDING_SETTLEMENT. APPROVED_AUTO
 * ("approved by the gateway, not submitted to the acquirer") counts only when the
 * host opts in. There is no prefix matching: a new APPROVED_* code is not approved.
 */
final readonly class ApprovalPolicy
{
    public function __construct(
        public bool $acceptApprovedAuto = false,
    ) {
    }

    /** @return list<GatewayCode> */
    public function approvedCodes(): array
    {
        $codes = [GatewayCode::Approved, GatewayCode::ApprovedPendingSettlement];

        if ($this->acceptApprovedAuto) {
            $codes[] = GatewayCode::ApprovedAuto;
        }

        return $codes;
    }

    public function isApprovedCode(GatewayCode $code): bool
    {
        return in_array($code, $this->approvedCodes(), true);
    }

    public function isApproved(Result $result, GatewayCode $code): bool
    {
        return $result === Result::Success && $this->isApprovedCode($code);
    }
}
