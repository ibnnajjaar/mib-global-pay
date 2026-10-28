<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Attempts;

use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationResult;

/**
 * AttemptGuard's answer. When allowed, the host (still inside Payable::lock()) marks
 * $attemptsToSupersede as Superseded and creates attempt $nextAttemptNumber in state Created.
 */
final readonly class GuardResult
{
    /**
     * @param list<Attempt>                     $attemptsToSupersede
     * @param array<string, VerificationResult> $verifications by gateway order ID, for audit
     */
    public function __construct(
        public bool $allowed,
        public ?GuardRefusal $reason,
        public int $nextAttemptNumber,
        public array $attemptsToSupersede = [],
        public ?Attempt $reusableAttempt = null,
        public ?Attempt $blockingAttempt = null,
        public array $verifications = [],
    ) {
    }
}
