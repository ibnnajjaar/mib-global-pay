<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Reversal;

use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\TransportException;

/**
 * Reverses a captured order: VOID the latest approved payment/capture, and if the gateway
 * declines or rejects the void (typically after settlement), REFUND the refundable amount.
 *
 * Never called automatically by the package except by the opt-in AutoReverseDoubleCapture
 * listener (stage 6). The caller supplies both transaction IDs so it can persist them before
 * sending; resending with the same IDs after a timeout is safe.
 */
final readonly class ReversalHelper
{
    public function __construct(
        private Gateway $gateway,
    ) {
    }

    /**
     * @throws InvalidRequest
     * @throws TransportException the void or refund got no response; resend with the same IDs
     * @throws GatewayException   the refund was rejected with HTTP 4xx/5xx
     * @throws MalformedPayload
     */
    public function reverse(string $orderId, string $voidTransactionId, string $refundTransactionId, ?string $reference = null): ReversalOutcome
    {
        $order = $this->gateway->orders()->retrieve($orderId);
        $policy = $this->gateway->config()->approvalPolicy;

        $target = null;
        foreach ($order->transactions as $transaction) {
            if ($transaction->type->movesMoneyIn() && $transaction->isApproved($policy) && $transaction->transactionId !== null) {
                $target = $transaction;
            }
        }

        if ($target === null || ! $order->refundable()->isPositive()) {
            return new ReversalOutcome(ReversalMethod::Nothing, false, null);
        }

        /** @var string $targetId */
        $targetId = $target->transactionId;
        $voidAttempt = null;
        $voidError = null;

        try {
            $voidAttempt = $this->gateway->orders()->void($orderId, $voidTransactionId, $targetId, $reference);
            if ($voidAttempt->isApproved($policy)) {
                return new ReversalOutcome(ReversalMethod::Void, true, $voidAttempt, $voidAttempt);
            }
        } catch (GatewayException $e) {
            if ($e->isServerError()) {
                throw $e;
            }
            $voidError = $e;
        }

        $refund = $this->gateway->orders()->refund($orderId, $refundTransactionId, $order->refundable(), null, $reference);

        return new ReversalOutcome(ReversalMethod::Refund, $refund->isApproved($policy), $refund, $voidAttempt, $voidError);
    }
}
