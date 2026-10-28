<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Testing;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;

/**
 * Webhook notifications in the gateway's REST-JSON shape (the operation's transaction response).
 */
final class NotificationFactory
{
    public static function paymentCaptured(string $orderId, Money $amount, string $transactionId = '1', ?string $notificationId = null): FakeNotification
    {
        return self::make($orderId, 'CAPTURED', $amount, OrderFactory::transaction($transactionId, 'PAYMENT', $amount), $notificationId);
    }

    public static function paymentDeclined(string $orderId, Money $amount, string $transactionId = '1', ?string $notificationId = null): FakeNotification
    {
        return self::make($orderId, 'FAILED', Money::ofMinor(0, $amount->currency), OrderFactory::transaction($transactionId, 'PAYMENT', $amount, 'FAILURE', 'DECLINED'), $notificationId);
    }

    public static function refund(string $orderId, Money $amount, string $transactionId = 'refund-1', string $targetTransactionId = '1', ?string $notificationId = null): FakeNotification
    {
        return self::make($orderId, 'REFUNDED', $amount, OrderFactory::transaction($transactionId, 'REFUND', $amount, target: $targetTransactionId), $notificationId);
    }

    public static function void(string $orderId, string $targetTransactionId = '1', string $transactionId = 'void-1', ?string $notificationId = null): FakeNotification
    {
        return self::make($orderId, 'CANCELLED', null, OrderFactory::transaction($transactionId, 'VOID_PAYMENT', null, target: $targetTransactionId), $notificationId);
    }

    public static function authentication(string $orderId, ?string $notificationId = null): FakeNotification
    {
        return self::make($orderId, 'AUTHENTICATED', null, ['result' => 'SUCCESS', 'transaction' => ['id' => 'auth-1', 'type' => 'AUTHENTICATION']], $notificationId);
    }

    /**
     * Any other verified notification body (e.g. a Payment Alert).
     *
     * @param array<string, mixed> $body
     */
    public static function other(array $body, ?string $notificationId = null): FakeNotification
    {
        return new FakeNotification((string) json_encode($body), self::headers($notificationId));
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private static function make(string $orderId, string $status, ?Money $totalCaptured, array $transaction, ?string $notificationId): FakeNotification
    {
        $order = array_filter([
            'id' => $orderId,
            'status' => $status,
            'currency' => $totalCaptured?->currency->value ?? 'MVR',
            'totalCapturedAmount' => $totalCaptured?->toDecimal(),
        ], fn (mixed $v): bool => $v !== null);

        return new FakeNotification(
            (string) json_encode(['merchant' => 'TESTMERCHANT', 'order' => $order, 'version' => '100'] + $transaction),
            self::headers($notificationId),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function headers(?string $notificationId): array
    {
        return [
            'X-Notification-ID' => $notificationId ?? 'NOTIF-' . strtoupper(bin2hex(random_bytes(8))),
            'X-Notification-Attempt' => '1',
        ];
    }
}
