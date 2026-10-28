<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Contracts;

/**
 * Generates the gateway order.id for one checkout attempt.
 */
interface OrderIdGenerator
{
    /**
     * @param int $attemptNumber 1, 2, 3 ...
     */
    public function forAttempt(string $prefix, string $payableReference, int $attemptNumber): string;

    /** A random ID, used when the readable one collides or does not fit. */
    public function random(string $prefix): string;
}
