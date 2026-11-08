<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Outcome;

use Illuminate\Contracts\Events\Dispatcher;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Attempt;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Payable;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\Source;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\Notification;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptState;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableState;
use IbnNajjaar\MIBGlobalPay\Core\Enums\TransactionType;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentFailed;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\VoidProcessed;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableResolver;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\ResolvedAttempt;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\MismatchReason;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentPending;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationStore;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\AccountVerified;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\CaptureMismatch;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentCaptured;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentReversed;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\RefundProcessed;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationOutcome;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentAuthorized;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentNeedsReview;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\NotificationReceived;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationResult;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationStatus;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\TransactionNotification;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentPartiallyCaptured;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\UnknownOrderNotification;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentCapturedForUnpayableOrder;

/**
 * The outcome pipeline (DESIGN-v2.md §6.2 and §6.7 E), shared by the webhook job, the return
 * URL (PaymentOutcome) and mib:reconcile.
 *
 * For each attempt: verifyCapture() against the payable's frozen amount (outside the lock,
 * since it calls the gateway), then inside Payable::lock() re-read the payable and dispatch at
 * most one outcome event. The package never marks anything paid: listeners do.
 *
 * Money events (PaymentCaptured, PaymentPartiallyCaptured, PaymentAuthorized, AccountVerified,
 * PaymentCapturedForUnpayableOrder, CaptureMismatch, PaymentReversed, RefundProcessed, VoidProcessed) go out at least once until one dispatch completes, then not again:
 * check for a delivery record, dispatch, and record only once Payable::lock() has returned. After a
 * throwable nothing is recorded, so a retry or mib:reconcile dispatches again. A crash before the
 * record, or a second worker entering the lock before it, repeats the event, so listeners must be
 * idempotent on deliveryKey. PaymentCaptured is also stopped by the payable's settlement, unless a
 * listener threw during an earlier dispatch.
 *
 * Advisory events (NotificationReceived, UnknownOrderNotification, PaymentPending, PaymentFailed) go
 * out at most once per notification, claimed before dispatching. A redelivery never reaches the job
 * (the controller answers duplicate), so only job retries are affected. Triggers without a
 * notification keep sending PaymentPending and PaymentFailed each time.
 */
final readonly class OutcomeProcessor
{
    /** Records that a listener threw during PaymentCaptured, for the settlement check in decide(). */
    private const string CAPTURE_INTERRUPTED = 'payment_captured_interrupted';

    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        private Gateway $gateway,
        private PayableResolver $resolver,
        private NotificationStore $store,
        private Dispatcher $events,
        ?\Closure $clock = null,
        private int $reviewAfterSeconds = 86400,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    public function handleNotification(Notification $notification): NotificationOutcome
    {
        if ($this->claimForNotification($notification, 'notification_received')) {
            $this->events->dispatch(new NotificationReceived($notification));
        }

        if (! $notification instanceof TransactionNotification) {
            return NotificationOutcome::Ignored;
        }

        $resolved = $this->resolver->resolveAttempt($notification->orderId);

        if ($notification->isRefund() || $notification->isVoid()) {
            $this->dispatchLifecycle($notification, $resolved);

            if ($resolved !== null) {
                $this->process($resolved, Source::Webhook, $notification);
            }

            return NotificationOutcome::Processed;
        }

        if (! $notification->isMoneyIn() && ! $notification->isAuthorizationOrVerification()) {
            return NotificationOutcome::Ignored;
        }

        if ($resolved === null) {
            if ($this->claimForNotification($notification, 'unknown_order')) {
                $this->events->dispatch(new UnknownOrderNotification($notification->orderId, $notification));
            }

            return NotificationOutcome::Processed;
        }

        $this->process($resolved, Source::Webhook, $notification);

        return NotificationOutcome::Processed;
    }

    public function process(ResolvedAttempt $resolved, Source $source, ?TransactionNotification $notification = null): OutcomeResult
    {
        $payable = $resolved->payable;
        $attempt = $resolved->attempt;
        $verification = $this->gateway->verifyCapture($attempt->gatewayOrderId(), $payable->expectedAmount());

        return $this->processVerified($resolved, $verification, $source, $notification);
    }

    /**
     * Run the decision step for a verification that was already made (e.g. by AttemptGuard).
     * Takes Payable::lock() itself; the host's lock must therefore be re-entrant when this is
     * called from inside it (DB::transaction + lockForUpdate is).
     */
    public function processVerified(ResolvedAttempt $resolved, VerificationResult $verification, Source $source, ?TransactionNotification $notification = null): OutcomeResult
    {
        $payable = $resolved->payable;
        $attempt = $resolved->attempt;

        $claimed = null;
        $dispatching = null;

        try {
            $result = $payable->lock(function () use ($payable, $attempt, $verification, $source, $notification, &$claimed, &$dispatching): OutcomeResult {
                return $this->decide($payable, $attempt, $verification, $source, $notification, $claimed, $dispatching);
            });
        } catch (\Throwable $e) {
            // A transactional lock has just rolled the claim back along with the failed listener.
            if ($notification !== null && $claimed !== null) {
                $this->claimForNotification($notification, $claimed);
            }

            if ($dispatching !== null && $dispatching[2] === 'payment_captured') {
                $this->store->claimTransaction($dispatching[0], $dispatching[1], self::CAPTURE_INTERRUPTED);
            }

            throw $e;
        }

        if ($dispatching !== null) {
            $this->store->markDelivered(...$dispatching);
        }

        return $result;
    }

    /**
     * @param array{string, string, string}|null $dispatching set by once(), for processVerified() to record after the lock
     */

    private function decide(Payable $payable, Attempt $attempt, VerificationResult $v, Source $source, ?TransactionNotification $n, ?string &$claimed, ?array &$dispatching): OutcomeResult
    {
        $orderId = $attempt->gatewayOrderId();
        $transactionId = $v->transactionId();

        // A retired attempt is only watched for a late charge, so every other answer is news to nobody.
        if (
            in_array($attempt->state(), [AttemptState::Superseded, AttemptState::Failed], true)
            && in_array($v->status, [VerificationStatus::NotCaptured, VerificationStatus::NotFound, VerificationStatus::Pending, VerificationStatus::Verified], true)
        ) {
            return new OutcomeResult($v, null);
        }

        switch ($v->status) {
            case VerificationStatus::Captured:
                /** @var string $transactionId Captured always has a capturing transaction */
                $settlement = $payable->settledBy();

                if ($settlement !== null) {
                    if ($settlement->isSameCapture($orderId, $transactionId)) {
                        // Normally the capture is done. But a lock with no rollback keeps a settlement
                        // saved by one listener when a later one threw, and that dispatch must go again.
                        return $this->store->wasDelivered($orderId, $transactionId, self::CAPTURE_INTERRUPTED)
                            ? $this->once($v, 'payment_captured', fn (string $key): PaymentCaptured => new PaymentCaptured($orderId, $payable, $attempt, $v, $transactionId, $source, $key, $n), $dispatching)
                            : new OutcomeResult($v, null, duplicate: true);
                    }

                    return $this->once($v, 'double_capture', fn (string $key): CaptureMismatch => new CaptureMismatch($orderId, $payable, $attempt, $v, MismatchReason::DoubleCapture, $settlement, $transactionId, $source, $key, $n), $dispatching);
                }

                $state = $payable->payableState();

                if ($state->isPayable()) {
                    return $this->once($v, 'payment_captured', fn (string $key): PaymentCaptured => new PaymentCaptured($orderId, $payable, $attempt, $v, $transactionId, $source, $key, $n), $dispatching);
                }

                if ($state === PayableState::Paid) {
                    // Paid without a recorded settlement: treat as a double capture, never as a new payment.
                    return $this->once($v, 'double_capture', fn (string $key): CaptureMismatch => new CaptureMismatch($orderId, $payable, $attempt, $v, MismatchReason::DoubleCapture, null, $transactionId, $source, $key, $n), $dispatching);
                }

                return $this->once($v, 'captured_for_unpayable', fn (string $key): PaymentCapturedForUnpayableOrder => new PaymentCapturedForUnpayableOrder($orderId, $payable, $attempt, $v, $transactionId, $state, $source, $key, $n), $dispatching);

            case VerificationStatus::PartiallyCaptured:
                /** @var string $transactionId PartiallyCaptured always has a capturing transaction */
                return $this->once($v, 'partially_captured', fn (string $key): PaymentPartiallyCaptured => new PaymentPartiallyCaptured($orderId, $payable, $attempt, $v, $transactionId, $source, $key, $n), $dispatching);

            case VerificationStatus::Authorized:
                /** @var string $transactionId Authorized always has an authorization transaction */
                return $this->once($v, 'payment_authorized', fn (string $key): PaymentAuthorized => new PaymentAuthorized($orderId, $payable, $attempt, $v, $transactionId, $source, $key, $n), $dispatching);

            case VerificationStatus::Verified:
                /** @var string $transactionId Verified always has a verification transaction */
                return $this->once($v, 'account_verified', fn (string $key): AccountVerified => new AccountVerified($orderId, $payable, $attempt, $v, $transactionId, $source, $key, $n), $dispatching);

            case VerificationStatus::AmountMismatch:
            case VerificationStatus::CurrencyMismatch:
                $reason = $v->status === VerificationStatus::AmountMismatch ? MismatchReason::AmountMismatch : MismatchReason::CurrencyMismatch;

                return $this->once($v, $reason->value, fn (string $key): CaptureMismatch => new CaptureMismatch($orderId, $payable, $attempt, $v, $reason, $payable->settledBy(), $transactionId, $source, $key, $n), $dispatching);

            case VerificationStatus::Pending:
                $since = $this->pendingSince($attempt, $v);

                if ($since->modify(sprintf('+%d seconds', $this->reviewAfterSeconds)) <= ($this->clock)()) {
                    return $this->once($v, 'needs_review', fn (string $key): PaymentNeedsReview => new PaymentNeedsReview($orderId, $payable, $attempt, $v, $since, $source, $key, $n), $dispatching);
                }

                return $this->oncePerNotification($v, 'payment_pending', new PaymentPending($orderId, $payable, $attempt, $v, $source, $n), $n, $claimed);

            case VerificationStatus::Reversed:
                $reversed = $v->refunded !== null && $v->refunded->isPositive() ? $v->refunded : $v->captured;

                return $reversed === null
                    ? new OutcomeResult($v, null)
                    : $this->once($v, 'payment_reversed', fn (string $key): PaymentReversed => new PaymentReversed($orderId, $payable, $attempt, $v, $reversed, $source, $key, $n), $dispatching);

            case VerificationStatus::NotCaptured:
            case VerificationStatus::NotFound:
                // Decided (DESIGN-v2.md §6.0): only a finished attempt is reported as failed,
                // i.e. the triggering transaction failed, or the attempt is past expiresAt.
                $failedTrigger = $n !== null && $n->transaction->isFailure();
                $expired = $attempt->expiresAt() <= ($this->clock)();

                if (! $failedTrigger && ! $expired) {
                    return new OutcomeResult($v, null);
                }

                return $this->oncePerNotification($v, 'payment_failed', new PaymentFailed($orderId, $payable, $attempt, $v, $n?->transaction->gatewayCode, $source, $n), $n, $claimed);
        }
    }

    /** When the latest transaction happened. Not expiresAt, which can be months away for a payment link. */
    private function pendingSince(Attempt $attempt, VerificationResult $v): \DateTimeImmutable
    {
        $since = $v->order?->creationTime;

        foreach ($v->order === null ? [] : $v->order->transactions as $transaction) {
            if ($transaction->type !== TransactionType::Authentication && $transaction->timeOfRecord !== null) {
                $since = $transaction->timeOfRecord;
            }
        }

        return $since ?? $attempt->expiresAt();
    }

    /**
     * @param \Closure(string): object           $event     builds the event from its delivery key
     * @param array{string, string, string}|null $dispatching what is going out, for processVerified()
     */
    private function once(VerificationResult $v, string $outcome, \Closure $event, ?array &$dispatching): OutcomeResult
    {
        $transactionId = $v->transactionId() ?? self::idWithoutTransaction($v);

        if ($this->store->wasDelivered($v->orderId, $transactionId, $outcome)) {
            return new OutcomeResult($v, null, duplicate: true);
        }

        $dispatching = [$v->orderId, $transactionId, $outcome];

        return $this->dispatch($v, $event(self::deliveryKey($v->orderId, $transactionId, $outcome)));
    }

    /**
     * Stands in for the transaction ID of a verification with no approved transaction (a mismatch or
     * reversal seen only in the order totals). What the gateway reports is the same from every trigger,
     * including ones with no notification, and a new total is a new event. Fits the store's 40 characters.
     */
    private static function idWithoutTransaction(VerificationResult $v): string
    {
        return sha1(json_encode([$v->status->value, (string) $v->captured, (string) $v->refunded], JSON_THROW_ON_ERROR));
    }

    /** 64 hex characters, so it fits a host's unique column and order IDs can hold any character. */
    private static function deliveryKey(string $orderId, string $transactionId, string $outcome): string
    {
        return hash('sha256', json_encode([$orderId, $transactionId, $outcome], JSON_THROW_ON_ERROR));
    }

    /**
     * @param string|null $claimed set to $outcome once claimed, so processVerified() can restore the claim
     */
    private function oncePerNotification(VerificationResult $v, string $outcome, object $event, ?TransactionNotification $n, ?string &$claimed): OutcomeResult
    {
        if ($n !== null) {
            if (! $this->claimForNotification($n, $outcome)) {
                return new OutcomeResult($v, null, duplicate: true);
            }

            $claimed = $outcome;
        }

        return $this->dispatch($v, $event);
    }

    /**
     * False if this notification already sent $outcome. The empty order ID keeps these claims apart
     * from real transactions, and the hash fits the store's 40-character transaction ID.
     */
    private function claimForNotification(Notification $n, string $outcome): bool
    {
        return $this->store->claimTransaction('', sha1($n->id()), $outcome);
    }

    private function dispatch(VerificationResult $v, object $event): OutcomeResult
    {
        $this->events->dispatch($event);

        return new OutcomeResult($v, $event);
    }

    /**
     * No lock here: $resolved can be null, so these listeners run outside Payable::lock(). A refund or
     * void with no transaction ID is told apart by its notification, since two partial refunds can be
     * identical in every other way.
     */
    private function dispatchLifecycle(TransactionNotification $n, ?ResolvedAttempt $resolved): void
    {
        $transactionId = $n->transactionId() ?? sha1($n->id());
        $outcome = $n->isRefund() ? 'refund_processed' : 'void_processed';

        if ($this->store->wasDelivered($n->orderId, $transactionId, $outcome)) {
            return;
        }

        $key = self::deliveryKey($n->orderId, $transactionId, $outcome);

        $this->events->dispatch($n->isRefund()
            ? new RefundProcessed($n->orderId, $resolved, $n->transaction, Source::Webhook, $key, $n)
            : new VoidProcessed($n->orderId, $resolved, $n->transaction, Source::Webhook, $key, $n));

        $this->store->markDelivered($n->orderId, $transactionId, $outcome);
    }
}
