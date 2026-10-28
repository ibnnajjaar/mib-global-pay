<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Outcome;

use Illuminate\Contracts\Events\Dispatcher;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Attempt;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Payable;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\Source;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\Notification;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableState;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentFailed;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\VoidProcessed;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableResolver;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\ResolvedAttempt;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\MismatchReason;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentPending;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationStore;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\CaptureMismatch;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentCaptured;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentReversed;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\RefundProcessed;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationOutcome;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\NotificationReceived;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationResult;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationStatus;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\TransactionNotification;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\UnknownOrderNotification;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentCapturedForUnpayableOrder;

/**
 * The outcome pipeline (DESIGN-v2.md §6.2 and §6.7 E), shared by the webhook job, the return
 * URL (PaymentOutcome) and mib:reconcile.
 *
 * For each attempt: verifyCapture() against the payable's frozen amount (outside the lock,
 * since it calls the gateway), then inside Payable::lock() re-read the payable and dispatch at
 * most one outcome event. Business-level dedupe (order ID + transaction ID + outcome) means a
 * capture produces its event once, however many triggers arrive. The package never marks
 * anything paid: listeners do.
 */
final readonly class OutcomeProcessor
{
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
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    public function handleNotification(Notification $notification): NotificationOutcome
    {
        $this->events->dispatch(new NotificationReceived($notification));

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

        if (! $notification->isMoneyIn()) {
            return NotificationOutcome::Ignored;
        }

        if ($resolved === null) {
            $this->events->dispatch(new UnknownOrderNotification($notification->orderId, $notification));

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

        return $payable->lock(fn (): OutcomeResult => $this->decide($payable, $attempt, $verification, $source, $notification));
    }

    private function decide(Payable $payable, Attempt $attempt, VerificationResult $v, Source $source, ?TransactionNotification $n): OutcomeResult
    {
        $orderId = $attempt->gatewayOrderId();
        $transactionId = $v->transactionId();

        switch ($v->status) {
            case VerificationStatus::Captured:
                /** @var string $transactionId Captured always has a capturing transaction */
                $settlement = $payable->settledBy();

                if ($settlement !== null) {
                    if ($settlement->isSameCapture($orderId, $transactionId)) {
                        return new OutcomeResult($v, null, duplicate: true);
                    }

                    return $this->once($v, 'double_capture', new CaptureMismatch($orderId, $payable, $attempt, $v, MismatchReason::DoubleCapture, $settlement, $transactionId, $source, $n));
                }

                $state = $payable->payableState();

                if ($state->isPayable()) {
                    return $this->once($v, 'payment_captured', new PaymentCaptured($orderId, $payable, $attempt, $v, $transactionId, $source, $n));
                }

                if ($state === PayableState::Paid) {
                    // Paid without a recorded settlement: treat as a double capture, never as a new payment.
                    return $this->once($v, 'double_capture', new CaptureMismatch($orderId, $payable, $attempt, $v, MismatchReason::DoubleCapture, null, $transactionId, $source, $n));
                }

                return $this->once($v, 'captured_for_unpayable', new PaymentCapturedForUnpayableOrder($orderId, $payable, $attempt, $v, $transactionId, $state, $source, $n));

            case VerificationStatus::AmountMismatch:
            case VerificationStatus::CurrencyMismatch:
                $reason = $v->status === VerificationStatus::AmountMismatch ? MismatchReason::AmountMismatch : MismatchReason::CurrencyMismatch;

                return $this->once($v, $reason->value, new CaptureMismatch($orderId, $payable, $attempt, $v, $reason, $payable->settledBy(), $transactionId, $source, $n));

            case VerificationStatus::Pending:
                return $this->dispatch($v, new PaymentPending($orderId, $payable, $attempt, $v, $source, $n));

            case VerificationStatus::Reversed:
                $reversed = $v->refunded !== null && $v->refunded->isPositive() ? $v->refunded : $v->captured;

                return $reversed === null
                    ? new OutcomeResult($v, null)
                    : $this->once($v, 'payment_reversed', new PaymentReversed($orderId, $payable, $attempt, $v, $reversed, $source, $n));

            case VerificationStatus::NotCaptured:
            case VerificationStatus::NotFound:
                // Decided (DESIGN-v2.md §6.0): only a finished attempt is reported as failed,
                // i.e. the triggering transaction failed, or the attempt is past expiresAt.
                $failedTrigger = $n !== null && $n->transaction->isFailure();
                $expired = $attempt->expiresAt() <= ($this->clock)();

                if (! $failedTrigger && ! $expired) {
                    return new OutcomeResult($v, null);
                }

                return $this->dispatch($v, new PaymentFailed($orderId, $payable, $attempt, $v, $n?->transaction->gatewayCode, $source, $n));
        }
    }

    private function once(VerificationResult $v, string $outcome, object $event): OutcomeResult
    {
        $transactionId = $v->transactionId();

        if ($transactionId !== null && ! $this->store->claimTransaction($v->orderId, $transactionId, $outcome)) {
            return new OutcomeResult($v, null, duplicate: true);
        }

        return $this->dispatch($v, $event);
    }

    private function dispatch(VerificationResult $v, object $event): OutcomeResult
    {
        $this->events->dispatch($event);

        return new OutcomeResult($v, $event);
    }

    private function dispatchLifecycle(TransactionNotification $n, ?ResolvedAttempt $resolved): void
    {
        $transactionId = $n->transactionId();
        $key = $n->isRefund() ? 'refund_processed' : 'void_processed';

        if ($transactionId !== null && ! $this->store->claimTransaction($n->orderId, $transactionId, $key)) {
            return;
        }

        $this->events->dispatch($n->isRefund()
            ? new RefundProcessed($n->orderId, $resolved, $n->transaction, Source::Webhook, $n)
            : new VoidProcessed($n->orderId, $resolved, $n->transaction, Source::Webhook, $n));
    }
}
