<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Reversal;

use IbnNajjaar\MIBGlobalPay\Core\Enums\Result;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Enums\GatewayCode;
use IbnNajjaar\MIBGlobalPay\Core\Enums\TransactionType;
use IbnNajjaar\MIBGlobalPay\Core\Responses\OrderResponse;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;
use IbnNajjaar\MIBGlobalPay\Core\Verification\CaptureVerifier;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\TransportException;
use IbnNajjaar\MIBGlobalPay\Core\Responses\TransactionResponse;

/**
 * Reverses a captured order: VOID the latest approved payment/capture, and if the void definitely
 * failed, REFUND the refundable amount against that capture.
 *
 * An HTTP 400 on the void in the gateway's error format is only a candidate for the refund: the void
 * is read back by its ID and the order is re-read, and the refund goes out only if the void is absent
 * or definitely failed. Any other 4xx is rethrown, and so is a 400 not in that format, which says
 * nothing about the void. Any other void result may still succeed, so no refund is sent and the
 * outcome is ReversalMethod::Unconfirmed.
 *
 * Calling again re-reads the order first. A reversal it already lists is reported and nothing is
 * sent. A void that definitely failed is not resent, because the MIB docs say a failed operation
 * needs a new ID to retry and an identical request returns the first response. The next step is the
 * refund, whose ID is stable too. The docs do not describe a 4xx for a duplicate request. None of
 * this gateway behaviour has been observed on the live gateway, including whether Retrieve Order
 * lists a pending void.
 *
 * Never called automatically by the package except by the opt-in AutoReverseDoubleCapture
 * listener (stage 6). Use reverseWithDerivedIds() unless you need your own IDs; then persist them
 * before sending and resend with the same IDs and reference.
 */
final readonly class ReversalHelper
{
    /**
     * A FAILURE or ERROR result with one of these codes does not prove the operation will not happen.
     * The in-progress codes match CaptureVerifier; the rest are system-side failures, a lock held
     * by another transaction, a result the gateway says it does not know (UNSPECIFIED_FAILURE), and
     * codes this version does not know.
     */
    private const array INCONCLUSIVE_CODES = [
        GatewayCode::Pending,
        GatewayCode::Submitted,
        GatewayCode::DeferredTransactionReceived,
        GatewayCode::Unknown,
        GatewayCode::TimedOut,
        GatewayCode::SystemError,
        GatewayCode::AcquirerSystemError,
        GatewayCode::LockFailure,
        GatewayCode::UnspecifiedFailure,
        GatewayCode::Unrecognized,
    ];

    public function __construct(
        private Gateway $gateway,
    ) {
    }

    /**
     * Reverse with IDs derived from the order and the capture being reversed (ReversalIds::derive()),
     * so every call for that capture, from any process, sends identical requests. The capture is
     * only known after reading the order, so the IDs are in the outcome's $ids.
     *
     * The target stays the same across calls, because a capture that was voided or refunded is still
     * the latest approved one. Only a new approved capture on the order moves it, and that capture
     * gets its own IDs; a reversal still unsettled for the old one blocks anything being sent.
     *
     * @throws InvalidRequest
     * @throws TransportException a void, refund or read got no response; call again
     * @throws GatewayException   see reverse()
     * @throws MalformedPayload
     */
    public function reverseWithDerivedIds(string $orderId, ?string $reference = null): ReversalOutcome
    {
        $order = $this->gateway->orders()->retrieve($orderId);
        $targetId = $this->latestApprovedCapture($order)?->transactionId;

        if ($targetId === null) {
            return new ReversalOutcome(ReversalMethod::Nothing, false, null);
        }

        return $this->reverseTarget($orderId, $order, ReversalIds::derive($orderId, $targetId), $reference);
    }

    /**
     * @throws InvalidRequest
     * @throws TransportException a void, refund or read got no response; resend with the same IDs
     * @throws GatewayException   the void failed with anything but a gateway HTTP 400, a read after one failed, or the refund was rejected
     * @throws MalformedPayload
     */
    public function reverse(string $orderId, string $voidTransactionId, string $refundTransactionId, ?string $reference = null): ReversalOutcome
    {
        $order = $this->gateway->orders()->retrieve($orderId);
        $targetId = $this->latestApprovedCapture($order)?->transactionId;

        if ($targetId === null) {
            return new ReversalOutcome(ReversalMethod::Nothing, false, null);
        }

        return $this->reverseTarget($orderId, $order, new ReversalIds($targetId, $voidTransactionId, $refundTransactionId), $reference);
    }

    private function reverseTarget(string $orderId, OrderResponse $order, ReversalIds $ids, ?string $reference): ReversalOutcome
    {
        $earlier = $this->outcomeOfEarlierReversal($order, $ids);
        if ($earlier !== null) {
            return $earlier;
        }

        if (! $order->refundable()->isPositive()) {
            return new ReversalOutcome(ReversalMethod::Nothing, false, null, ids: $ids);
        }

        // Still listed here means it definitely failed. An identical VOID would replay that failure.
        $voidAttempt = $order->findTransaction($ids->void);
        $voidError = null;

        if ($voidAttempt === null) {
            try {
                $voidAttempt = $this->gateway->orders()->void($orderId, $ids->void, $ids->target, $reference);
                if ($voidAttempt->isApproved($this->gateway->config()->approvalPolicy)) {
                    return new ReversalOutcome(ReversalMethod::Void, true, $voidAttempt, $voidAttempt, ids: $ids);
                }
                if (! $this->definitelyFailed($voidAttempt)) {
                    return new ReversalOutcome(ReversalMethod::Unconfirmed, false, $voidAttempt, $voidAttempt, ids: $ids);
                }
            } catch (GatewayException $e) {
                if ($e->httpStatus() !== 400 || ! $e->isGatewayError()) {
                    throw $e;
                }

                $unconfirmed = $this->whyNotRefundAfterRejection($orderId, $order, $ids, $e);
                if ($unconfirmed !== null) {
                    return $unconfirmed;
                }

                $voidError = $e;
            }
        }

        $refund = $this->gateway->orders()->refund($orderId, $ids->refund, $order->refundable(), $ids->target, $reference);

        if (! $refund->isApproved($this->gateway->config()->approvalPolicy) && ! $this->definitelyFailed($refund)) {
            return new ReversalOutcome(ReversalMethod::Unconfirmed, false, $refund, $voidAttempt, $voidError, $ids);
        }

        return new ReversalOutcome(ReversalMethod::Refund, $refund->isApproved($this->gateway->config()->approvalPolicy), $refund, $voidAttempt, $voidError, $ids);
    }

    /**
     * After an HTTP 400 on the void, read the void back and re-read the order. Null means the void
     * is absent or definitely failed and nothing on the order has moved, so the refund may go out.
     * Anything else gives Unconfirmed; there the void may yet exist, or the rejection is unexplained.
     *
     * A failed read is rethrown rather than turned into Unconfirmed, like any other request here
     * that gets no usable answer: the same call can be made again, and no refund has been sent.
     *
     * @throws InvalidRequest
     * @throws TransportException
     * @throws GatewayException
     * @throws MalformedPayload
     */
    private function whyNotRefundAfterRejection(string $orderId, OrderResponse $order, ReversalIds $ids, GatewayException $rejection): ?ReversalOutcome
    {
        $unconfirmed = fn (?TransactionResponse $transaction): ReversalOutcome => new ReversalOutcome(ReversalMethod::Unconfirmed, false, $transaction, null, $rejection, $ids);

        $void = $this->readVoid($orderId, $ids->void);
        $current = $this->gateway->orders()->retrieve($orderId);

        if ($void !== null && ($void->transactionId !== $ids->void || ! $this->definitelyFailed($void))) {
            return $unconfirmed($void);
        }

        $earlier = $this->outcomeOfEarlierReversal($current, $ids);
        if ($earlier !== null) {
            return $unconfirmed($earlier->transaction);
        }

        $sameTarget = $this->latestApprovedCapture($current)?->transactionId === $ids->target;

        return $sameTarget && $current->refundable()->equals($order->refundable()) ? null : $unconfirmed($void);
    }

    /**
     * The void as Retrieve Transaction reports it, or null when it is absent. The docs do not say
     * what an unknown transaction ID returns, so a not-found gateway error
     * (CaptureVerifier::isTransactionNotFound()) and an empty 200 both count as absent, and the caller
     * still needs the re-read order to agree. Other failures are rethrown, including a 404 that is
     * not a gateway error, so a wrong host sends no refund.
     *
     * @throws InvalidRequest
     * @throws TransportException
     * @throws GatewayException
     * @throws MalformedPayload
     */
    private function readVoid(string $orderId, string $voidTransactionId): ?TransactionResponse
    {
        try {
            $void = $this->gateway->orders()->retrieveTransaction($orderId, $voidTransactionId);
        } catch (GatewayException $e) {
            if (CaptureVerifier::isTransactionNotFound($e)) {
                return null;
            }

            throw $e;
        }

        return $void->transactionId === null && $void->rawResult() === null ? null : $void;
    }

    /**
     * What the order already shows about this reversal, or null when nothing stops it going ahead.
     *
     * Approved: our void or any void of this target; our refund by its ID; or a refund under another
     * ID only if it targets this capture and covers all of it, so a host's partial refund is never
     * taken for this reversal.
     *
     * Unsettled: any void of money in, or any refund, on the whole order. The refund amount comes
     * from the order's totals, and these may yet change them, so nothing is sent until they settle.
     *
     * Definitely failed: only our own refund counts, and it is final. Another ID's failure says
     * nothing about ours, and a failed void is followed by the refund.
     */
    private function outcomeOfEarlierReversal(OrderResponse $order, ReversalIds $ids): ?ReversalOutcome
    {
        $target = $order->findTransaction($ids->target);
        $unsettled = null;
        $failedRefund = null;

        foreach ($order->transactions as $transaction) {
            $isOurVoid = $transaction->transactionId === $ids->void
                || ($transaction->type->reversesMoneyIn() && $transaction->targetTransactionId === $ids->target);
            $isOurRefund = $transaction->transactionId === $ids->refund;
            $isRefund = in_array($transaction->type, [TransactionType::Refund, TransactionType::RefundRequest], true);

            if (! $isOurVoid && ! $isOurRefund && ! $isRefund && ! $transaction->type->reversesMoneyIn()) {
                continue;
            }

            if ($transaction->isApproved($this->gateway->config()->approvalPolicy)) {
                if ($isOurVoid) {
                    return new ReversalOutcome(ReversalMethod::Void, true, $transaction, $transaction, ids: $ids);
                }
                if ($isOurRefund || ($isRefund && $this->refundsAllOf($transaction, $target))) {
                    return new ReversalOutcome(ReversalMethod::Refund, true, $transaction, ids: $ids);
                }

                continue;
            }

            if (! $this->definitelyFailed($transaction)) {
                $unsettled = $transaction;
            } elseif ($isOurRefund) {
                $failedRefund = $transaction;
            }
        }

        if ($unsettled !== null) {
            return new ReversalOutcome(ReversalMethod::Unconfirmed, false, $unsettled, $unsettled->type->reversesMoneyIn() ? $unsettled : null, ids: $ids);
        }

        return $failedRefund === null ? null : new ReversalOutcome(ReversalMethod::Refund, false, $failedRefund, ids: $ids);
    }

    private function refundsAllOf(TransactionResponse $refund, ?TransactionResponse $target): bool
    {
        return $target !== null
            && $refund->targetTransactionId === $target->transactionId
            && $refund->amount !== null
            && $target->amount !== null
            && $refund->amount->isSameCurrency($target->amount)
            && $refund->amount->greaterThanOrEqual($target->amount);
    }

    private function latestApprovedCapture(OrderResponse $order): ?TransactionResponse
    {
        $target = null;
        foreach ($order->transactions as $transaction) {
            if ($transaction->type->movesMoneyIn() && $transaction->isApproved($this->gateway->config()->approvalPolicy) && $transaction->transactionId !== null) {
                $target = $transaction;
            }
        }

        return $target;
    }

    private function definitelyFailed(TransactionResponse $transaction): bool
    {
        return in_array($transaction->result, [Result::Failure, Result::Error], true)
            && ! in_array($transaction->gatewayCode, self::INCONCLUSIVE_CODES, true);
    }
}
