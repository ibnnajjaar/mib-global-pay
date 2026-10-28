<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Attempts;

final readonly class ResolvedAttempt
{
    public function __construct(
        public Payable $payable,
        public Attempt $attempt,
    ) {
    }
}
