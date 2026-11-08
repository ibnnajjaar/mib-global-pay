<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Listeners;

use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptState;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentFailed;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\AccountVerified;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentCaptured;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentReversed;
use IbnNajjaar\MIBGlobalPay\Laravel\Models\CheckoutAttempt;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentAuthorized;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentCapturedForUnpayableOrder;

/**
 * Keeps the package's own attempts up to date, so a host using the HasMibPayments trait has no
 * bookkeeping to do. It ignores attempts stored anywhere else.
 */
final class RecordAttemptOutcome
{
    public function handle(PaymentCaptured|PaymentAuthorized|AccountVerified|PaymentFailed|PaymentReversed|PaymentCapturedForUnpayableOrder $event): void
    {
        $attempt = $event->attempt;

        if (! $attempt instanceof CheckoutAttempt) {
            return;
        }

        match (true) {
            $event instanceof PaymentCaptured => $this->settle($attempt, $event),
            $event instanceof PaymentCapturedForUnpayableOrder => $this->moveTo($attempt, AttemptState::Captured),
            $event instanceof PaymentAuthorized => $this->moveTo($attempt, AttemptState::Authorized),
            $event instanceof AccountVerified => $this->moveTo($attempt, AttemptState::Verified),
            $event instanceof PaymentFailed => $this->moveTo($attempt, AttemptState::Failed),
            $event instanceof PaymentReversed => $attempt->update(['reversed_at' => new \DateTimeImmutable()]),
        };
    }

    private function settle(CheckoutAttempt $attempt, PaymentCaptured $event): void
    {
        $this->moveTo($attempt, AttemptState::Captured);

        $attempt->update([
            'settled_transaction_id' => $event->transactionId,
            'settled_amount_minor' => $event->verification->captured?->minor,
            'settled_at' => new \DateTimeImmutable(),
        ]);
    }

    private function moveTo(CheckoutAttempt $attempt, AttemptState $state): void
    {
        if ($attempt->state->canTransitionTo($state)) {
            $attempt->update(['state' => $state]);
        }
    }
}
