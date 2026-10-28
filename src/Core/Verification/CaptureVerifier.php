<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Verification;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Enums\Result;
use IbnNajjaar\MIBGlobalPay\Core\Enums\GatewayCode;
use IbnNajjaar\MIBGlobalPay\Core\Enums\TransactionType;
use IbnNajjaar\MIBGlobalPay\Core\Responses\OrderResponse;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Core\Responses\TransactionResponse;

/**
 * Answers "is order X captured for exactly amount Y in currency Z?" from a Retrieve Order
 * response. Pure and side-effect free; MibGateway::verifyCapture() re-reads the order first.
 *
 * Rules, in order:
 *  1. No approved PAYMENT/CAPTURE: Pending if the latest non-authentication transaction is
 *     pending or unknown, otherwise NotCaptured.
 *  2. Every approved PAYMENT/CAPTURE was voided (approved VOID_PAYMENT/VOID_CAPTURE targeting
 *     it), or refunds cover the captured total: Reversed.
 *  3. Order currency differs from the expected currency: CurrencyMismatch.
 *  4. totalCapturedAmount missing from the response: AmountMismatch (never a match).
 *  5. totalCapturedAmount equals the expected amount: Captured; otherwise AmountMismatch.
 */
final readonly class CaptureVerifier
{
    private const PENDING_CODES = [
        GatewayCode::Pending,
        GatewayCode::Submitted,
        GatewayCode::DeferredTransactionReceived,
        GatewayCode::Unknown,
        GatewayCode::TimedOut,
    ];

    public function __construct(
        private ApprovalPolicy $policy = new ApprovalPolicy(),
    ) {
    }

    public function verify(OrderResponse $order, Money $expected): VerificationResult
    {
        $captures = array_values(array_filter(
            $order->transactions,
            fn (TransactionResponse $t): bool => ($t->type === TransactionType::Payment || $t->type === TransactionType::Capture)
                && $t->isApproved($this->policy),
        ));

        if ($captures === []) {
            return $this->result($this->isPending($order) ? VerificationStatus::Pending : VerificationStatus::NotCaptured, $order, $expected, null);
        }

        $voided = $this->voidedTransactionIds($order);
        $live = array_values(array_filter($captures, fn (TransactionResponse $t): bool => ! in_array($t->transactionId, $voided, true)));

        $fullyRefunded = $order->totalCaptured->isPositive()
            && $order->totalRefunded->greaterThanOrEqual($order->totalCaptured);

        if ($live === [] || $fullyRefunded) {
            return $this->result(VerificationStatus::Reversed, $order, $expected, $captures[0]);
        }

        if ($order->currency !== $expected->currency) {
            return $this->result(VerificationStatus::CurrencyMismatch, $order, $expected, $live[0]);
        }

        if (! $order->isTotalCapturedReported()) {
            return $this->result(VerificationStatus::AmountMismatch, $order, $expected, $live[0]);
        }

        return $this->result(
            $order->totalCaptured->equals($expected) ? VerificationStatus::Captured : VerificationStatus::AmountMismatch,
            $order,
            $expected,
            $live[0],
        );
    }

    /**
     * Result for an order the gateway does not know.
     */
    public function notFound(string $orderId, Money $expected): VerificationResult
    {
        return new VerificationResult(VerificationStatus::NotFound, $orderId, $expected, null, null, null, null);
    }

    /**
     * Whether a Retrieve Order failure means "no such order".
     *
     * Unverified against the MIB sandbox: treats HTTP 404, and HTTP 400 whose explanation
     * mentions that the order was not found, as NotFound.
     */
    public static function isOrderNotFound(GatewayException $e): bool
    {
        if ($e->httpStatus() === 404) {
            return true;
        }

        $text = strtolower(($e->explanation() ?? '') . ' ' . ($e->cause() ?? ''));

        return $e->httpStatus() === 400
            && (str_contains($text, 'not found') || str_contains($text, 'unable to find') || str_contains($text, 'does not exist'));
    }

    private function isPending(OrderResponse $order): bool
    {
        $latest = null;
        foreach ($order->transactions as $transaction) {
            if ($transaction->type !== TransactionType::Authentication) {
                $latest = $transaction;
            }
        }

        if ($latest === null) {
            return false;
        }

        return in_array($latest->result, [Result::Pending, Result::Unknown], true)
            || in_array($latest->gatewayCode, self::PENDING_CODES, true);
    }

    /**
     * @return list<string>
     */
    private function voidedTransactionIds(OrderResponse $order): array
    {
        $ids = [];
        foreach ($order->transactionsOfType(TransactionType::VoidPayment, TransactionType::VoidCapture) as $void) {
            if ($void->isApproved($this->policy) && $void->targetTransactionId !== null) {
                $ids[] = $void->targetTransactionId;
            }
        }

        return $ids;
    }

    private function result(VerificationStatus $status, OrderResponse $order, Money $expected, ?TransactionResponse $capturing): VerificationResult
    {
        return new VerificationResult(
            $status,
            $order->id,
            $expected,
            $order->totalCaptured,
            $order->totalRefunded,
            $capturing,
            $order,
        );
    }
}
