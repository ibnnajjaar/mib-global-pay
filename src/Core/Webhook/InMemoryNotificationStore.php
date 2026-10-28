<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Webhook;

/**
 * Process-local NotificationStore for tests and single-process use. Not shared between workers.
 */
final class InMemoryNotificationStore implements NotificationStore
{
    /** @var array<string, array{orderId: ?string, outcome: ?NotificationOutcome}> */
    private array $notifications = [];

    /** @var array<string, true> */
    private array $transactions = [];

    public function claim(string $notificationId, ?string $orderId = null): bool
    {
        if (isset($this->notifications[$notificationId])) {
            return false;
        }

        $this->notifications[$notificationId] = ['orderId' => $orderId, 'outcome' => null];

        return true;
    }

    public function release(string $notificationId): void
    {
        unset($this->notifications[$notificationId]);
    }

    public function markProcessed(string $notificationId, NotificationOutcome $outcome): void
    {
        $this->notifications[$notificationId] = [
            'orderId' => $this->notifications[$notificationId]['orderId'] ?? null,
            'outcome' => $outcome,
        ];
    }

    public function outcomeOf(string $notificationId): ?NotificationOutcome
    {
        return $this->notifications[$notificationId]['outcome'] ?? null;
    }

    public function claimTransaction(string $orderId, string $transactionId, string $outcome): bool
    {
        $key = json_encode([$orderId, $transactionId, $outcome], JSON_THROW_ON_ERROR);

        if (isset($this->transactions[$key])) {
            return false;
        }

        $this->transactions[$key] = true;

        return true;
    }
}
