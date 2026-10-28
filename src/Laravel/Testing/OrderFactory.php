<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Testing;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;

/**
 * Retrieve Order payloads in the gateway's REST-JSON v100 shape, for MibGlobalPayFake::order().
 * The fake sets the order ID it is stubbed under, so $orderId is optional.
 */
final class OrderFactory
{
    /**
     * An approved PAYMENT for $amount; CAPTURED.
     *
     * @return array<string, mixed>
     */
    public static function captured(Money $amount, ?string $orderId = null): array
    {
        return self::order($orderId, $amount, 'CAPTURED', captured: $amount, transactions: [
            self::transaction('1', 'PAYMENT', $amount),
        ]);
    }

    /**
     * An approved AUTHORIZATION, nothing captured yet.
     *
     * @return array<string, mixed>
     */
    public static function authorizedOnly(Money $amount, ?string $orderId = null): array
    {
        return self::order($orderId, $amount, 'AUTHORIZED', authorized: $amount, captured: Money::ofMinor(0, $amount->currency), transactions: [
            self::transaction('1', 'AUTHORIZATION', $amount),
        ]);
    }

    /**
     * A declined PAYMENT.
     *
     * @return array<string, mixed>
     */
    public static function declined(Money $amount, ?string $orderId = null): array
    {
        return self::order($orderId, $amount, 'FAILED', captured: Money::ofMinor(0, $amount->currency), transactions: [
            self::transaction('1', 'PAYMENT', $amount, 'FAILURE', 'DECLINED'),
        ]);
    }

    /**
     * A PAYMENT still pending at the gateway.
     *
     * @return array<string, mixed>
     */
    public static function pending(Money $amount, ?string $orderId = null): array
    {
        return self::order($orderId, $amount, 'INITIATED', captured: Money::ofMinor(0, $amount->currency), transactions: [
            self::transaction('1', 'PAYMENT', $amount, 'PENDING', 'PENDING'),
        ]);
    }

    /**
     * Captured $amount, then $refunded refunded.
     *
     * @return array<string, mixed>
     */
    public static function partiallyRefunded(Money $amount, Money $refunded, ?string $orderId = null): array
    {
        return self::order($orderId, $amount, 'PARTIALLY_REFUNDED', captured: $amount, refunded: $refunded, transactions: [
            self::transaction('1', 'PAYMENT', $amount),
            self::transaction('refund-1', 'REFUND', $refunded, target: '1'),
        ]);
    }

    /**
     * Captured and fully refunded.
     *
     * @return array<string, mixed>
     */
    public static function refunded(Money $amount, ?string $orderId = null): array
    {
        return self::order($orderId, $amount, 'REFUNDED', captured: $amount, refunded: $amount, transactions: [
            self::transaction('1', 'PAYMENT', $amount),
            self::transaction('refund-1', 'REFUND', $amount, target: '1'),
        ]);
    }

    /**
     * Captured, then the payment was voided.
     *
     * @return array<string, mixed>
     */
    public static function voided(Money $amount, ?string $orderId = null): array
    {
        return self::order($orderId, $amount, 'CANCELLED', captured: $amount, transactions: [
            self::transaction('1', 'PAYMENT', $amount),
            self::transaction('void-1', 'VOID_PAYMENT', null, target: '1'),
        ]);
    }

    /**
     * An approved PAYMENT but no totalCapturedAmount in the response (verification treats it as a mismatch).
     *
     * @return array<string, mixed>
     */
    public static function withoutCapturedTotal(Money $amount, ?string $orderId = null): array
    {
        $order = self::captured($amount, $orderId);
        unset($order['totalCapturedAmount']);

        return $order;
    }

    /**
     * @param list<array<string, mixed>> $transactions
     *
     * @return array<string, mixed>
     */
    private static function order(
        ?string $orderId,
        Money $amount,
        string $status,
        ?Money $authorized = null,
        ?Money $captured = null,
        ?Money $refunded = null,
        array $transactions = [],
    ): array {
        $zero = Money::ofMinor(0, $amount->currency);

        return [
            'amount' => $amount->toDecimal(),
            'creationTime' => '2026-10-20T09:00:00.000Z',
            'currency' => $amount->currency->value,
            'id' => $orderId ?? 'ORDER-1',
            'merchant' => 'TESTMERCHANT',
            'result' => 'SUCCESS',
            'status' => $status,
            'totalAuthorizedAmount' => ($authorized ?? $captured ?? $zero)->toDecimal(),
            'totalCapturedAmount' => ($captured ?? $zero)->toDecimal(),
            'totalRefundedAmount' => ($refunded ?? $zero)->toDecimal(),
            'transaction' => $transactions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function transaction(
        string $id,
        string $type,
        ?Money $amount,
        string $result = 'SUCCESS',
        string $gatewayCode = 'APPROVED',
        ?string $target = null,
    ): array {
        return [
            'result' => $result,
            'response' => ['acquirerCode' => $result === 'SUCCESS' ? '00' : '05', 'gatewayCode' => $gatewayCode],
            'timeOfRecord' => '2026-10-20T09:01:10.000Z',
            'transaction' => array_filter([
                'amount' => $amount?->toDecimal(),
                'currency' => $amount?->currency->value,
                'id' => $id,
                'targetTransactionId' => $target,
                'type' => $type,
            ], fn (mixed $v): bool => $v !== null),
        ];
    }
}
