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
    private const array PENDING_CODES = [
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
            fn (TransactionResponse $t): bool => $t->type->movesMoneyIn() && $t->isApproved($this->policy),
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
     * Whether a Retrieve Order failure means "no such order". NotFound counts as unpaid and can
     * allow a new attempt, so when unsure this says no and the exception reaches the caller.
     *
     * Unverified against the MIB sandbox, and the docs do not describe this case (DESIGN-v2.md §4.2).
     * It needs the documented error format with cause INVALID_REQUEST and no field-level validation
     * details, and then either HTTP 404 with any wording, or HTTP 400 whose explanation says the
     * order is what is missing. The docs say the explanation's format cannot be guaranteed, so a
     * 404 does not depend on it. They describe a 400 as a malformed request, so a 400 does.
     */
    public static function isOrderNotFound(GatewayException $e): bool
    {
        return self::isNotFound($e, 'order');
    }

    /**
     * The same rule for a Retrieve Transaction failure, with the 400 text naming the transaction.
     * A 404 cannot tell an unknown transaction from an unknown order, and either way the
     * transaction is absent. Equally unverified.
     */
    public static function isTransactionNotFound(GatewayException $e): bool
    {
        return self::isNotFound($e, 'transaction');
    }

    private static function isNotFound(GatewayException $e, string $subject): bool
    {
        $error = $e->error();

        if (! $e->isGatewayError() || $e->cause() !== 'INVALID_REQUEST' || isset($error['field']) || isset($error['validationType'])) {
            return false;
        }

        return $e->httpStatus() === 404
            || ($e->httpStatus() === 400 && self::saysMissing(trim($e->explanation() ?? ''), $subject));
    }

    /**
     * "Unable to find order X", "Order not found", "The order with id X-1 was not found", "Order X-1
     * does not exist". The subject must be what is missing, so "Merchant not found for order X" and
     * "Order reference not found" do not count, nor does a value echoed back in a validation message.
     */
    private static function saysMissing(string $explanation, string $subject): bool
    {
        $end = '(?![\w-]|\.\w)';

        return preg_match(
            "/^(?:unable to find (?:the |an? )?{$subject}{$end}|(?:the )?{$subject}(?: (?:with )?id)?(?: \S*\d\S*)? (?:was )?(?:not found|does not exist)\b)/i",
            $explanation,
        ) === 1;
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
        foreach ($order->transactions as $void) {
            if ($void->type->reversesMoneyIn() && $void->isApproved($this->policy) && $void->targetTransactionId !== null) {
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
