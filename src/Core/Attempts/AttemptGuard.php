<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Attempts;

use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\TransportException;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationResult;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationStatus;

/**
 * Decides whether a new checkout attempt may start for a payable (DESIGN-v2.md §6.7 C).
 *
 * Call it INSIDE Payable::lock(), and create the new attempt in the same critical section.
 * It relies on verifyCapture() only, never on order-status summaries:
 *
 *  1. The payable must be Open or Frozen.
 *  2. It must have no settlement and no attempt recorded as Captured.
 *  3. Every earlier attempt that may still be paid (Created, Live, Expired) is verified with the
 *     gateway, expired or not. In order of precedence:
 *     - any Captured / AmountMismatch / CurrencyMismatch refuses with MoneyCaptured (and the
 *       optional $onMoneyCaptured hook runs, so the Laravel layer can run the capture pipeline);
 *     - any Pending refuses with VerificationPending;
 *     - any NotCaptured / NotFound attempt that has not expired refuses with AttemptStillLive;
 *       a Live one is returned as reusableAttempt;
 *     - otherwise the attempts (expired and NotCaptured/NotFound, or Reversed) are returned in
 *       attemptsToSupersede, and a new attempt is allowed.
 *
 * Failed and Superseded attempts need no check. Cost: one Retrieve Order per attempt that may
 * still be paid (usually zero or one). If the gateway cannot be reached the guard throws, so no
 * new attempt starts while an older one might be paid.
 */
final readonly class AttemptGuard
{
    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null                          $clock           injectable for tests
     * @param (\Closure(Payable, Attempt, VerificationResult): void)|null $onMoneyCaptured runs for each attempt found captured
     */
    public function __construct(
        private Gateway $gateway,
        ?\Closure $clock = null,
        private ?\Closure $onMoneyCaptured = null,
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
        $verifications = [];
        $captured = [];
        $pending = null;
        $live = null;
        $toSupersede = [];

        foreach ($attempts as $attempt) {
            if (! $attempt->state()->mayStillBePaid()) {
                continue;
            }

            $verification = $this->gateway->verifyCapture($attempt->gatewayOrderId(), $attempt->amount());
            $verifications[$attempt->gatewayOrderId()] = $verification;

            if ($verification->isMoneyCaptured()) {
                $captured[] = [$attempt, $verification];
            } elseif ($verification->status === VerificationStatus::Pending) {
                $pending ??= $attempt;
            } elseif ($verification->status === VerificationStatus::Reversed || $attempt->expiresAt() <= $now) {
                // Finished without money held: expired and NotCaptured/NotFound, or reversed.
                $toSupersede[] = $attempt;
            } else {
                $live ??= $attempt;
            }
        }

        if ($captured !== []) {
            foreach ($captured as [$attempt, $verification]) {
                if ($this->onMoneyCaptured !== null) {
                    ($this->onMoneyCaptured)($payable, $attempt, $verification);
                }
            }

            return new GuardResult(false, GuardRefusal::MoneyCaptured, $next, blockingAttempt: $captured[0][0], verifications: $verifications);
        }

        if ($pending !== null) {
            return new GuardResult(false, GuardRefusal::VerificationPending, $next, blockingAttempt: $pending, verifications: $verifications);
        }

        if ($live !== null) {
            return new GuardResult(
                false,
                GuardRefusal::AttemptStillLive,
                $next,
                reusableAttempt: $live->state() === AttemptState::Live ? $live : null,
                blockingAttempt: $live,
                verifications: $verifications,
            );
        }

        return new GuardResult(true, null, $next, $toSupersede, verifications: $verifications);
    }
}
