<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Attempts;

use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\TransportException;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationStatus;

/**
 * Decides whether a new checkout attempt may start for a payable (DESIGN-v2.md §6.7 C).
 *
 * Call it INSIDE Payable::lock(), and create the new attempt in the same critical section.
 * It relies on verifyCapture() only, never on order-status summaries:
 *
 *  1. The payable must be Open or Frozen.
 *  2. It must have no settlement and no attempt recorded as Captured.
 *  3. Every earlier attempt that may still be paid (Created, Live, Expired) must be past
 *     expiresAt() AND verify as NotCaptured, Reversed or NotFound. An attempt not yet expired
 *     refuses with AttemptStillLive and is offered for reuse.
 *  4. Captured / AmountMismatch / CurrencyMismatch refuses with MoneyCaptured; Pending refuses
 *     with VerificationPending.
 *
 * Failed and Superseded attempts need no check.
 */
final readonly class AttemptGuard
{
    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock injectable for tests
     */
    public function __construct(
        private Gateway $gateway,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * @throws InvalidRequest
     * @throws GatewayException
     * @throws TransportException
     * @throws MalformedPayload
     */
    public function canStartNewAttempt(Payable $payable): GuardResult
    {
        $attempts = [...$payable->attempts()];
        $next = 1 + array_reduce($attempts, fn (int $max, Attempt $a): int => max($max, $a->attemptNumber()), 0);

        if (! $payable->payableState()->isPayable()) {
            return new GuardResult(false, GuardRefusal::PayableNotPayable, $next);
        }

        if ($payable->settledBy() !== null) {
            return new GuardResult(false, GuardRefusal::AlreadySettled, $next);
        }

        foreach ($attempts as $attempt) {
            if ($attempt->state() === AttemptState::Captured) {
                return new GuardResult(false, GuardRefusal::AttemptCaptured, $next, blockingAttempt: $attempt);
            }
        }

        $now = ($this->clock)();
        $toSupersede = [];
        $verifications = [];

        foreach ($attempts as $attempt) {
            if (! $attempt->state()->mayStillBePaid()) {
                continue;
            }

            if ($attempt->expiresAt() > $now) {
                return new GuardResult(
                    false,
                    GuardRefusal::AttemptStillLive,
                    $next,
                    reusableAttempt: $attempt->state() === AttemptState::Live ? $attempt : null,
                    blockingAttempt: $attempt,
                    verifications: $verifications,
                );
            }

            $verification = $this->gateway->verifyCapture($attempt->gatewayOrderId(), $attempt->amount());
            $verifications[$attempt->gatewayOrderId()] = $verification;

            if ($verification->isMoneyCaptured()) {
                return new GuardResult(false, GuardRefusal::MoneyCaptured, $next, blockingAttempt: $attempt, verifications: $verifications);
            }

            if ($verification->status === VerificationStatus::Pending) {
                return new GuardResult(false, GuardRefusal::VerificationPending, $next, blockingAttempt: $attempt, verifications: $verifications);
            }

            // NotCaptured, Reversed or NotFound, past expiry: the attempt is finished without money held.
            $toSupersede[] = $attempt;
        }

        return new GuardResult(true, null, $next, $toSupersede, verifications: $verifications);
    }
}
