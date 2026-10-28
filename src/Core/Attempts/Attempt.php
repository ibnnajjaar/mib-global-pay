<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Attempts;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;

/**
 * One checkout attempt: one Initiate Checkout with its own gateway order.id. Implemented by the host.
 */
interface Attempt
{
    public function attemptNumber(): int;

    public function gatewayOrderId(): string;

    public function sessionId(): ?string;

    public function successIndicator(): ?string;

    public function amount(): Money;

    public function state(): AttemptState;

    /** created_at + interaction.timeout + grace. */
    public function expiresAt(): \DateTimeImmutable;
}
