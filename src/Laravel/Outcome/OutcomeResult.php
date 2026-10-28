<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Outcome;

use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationResult;

/**
 * What the pipeline did for one attempt: the event it dispatched (or null), and why.
 */
final readonly class OutcomeResult
{
    public function __construct(
        public ?VerificationResult $verification,
        public ?object $event,
        public bool $duplicate = false,
    ) {
    }

    public function dispatched(): bool
    {
        return $this->event !== null;
    }
}
