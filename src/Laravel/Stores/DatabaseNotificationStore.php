<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Stores;

use Illuminate\Database\ConnectionInterface;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationStore;
use Illuminate\Database\UniqueConstraintViolationException;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationOutcome;

/**
 * NotificationStore on the mib_global_pay_notifications table. Atomicity comes from its unique
 * indexes: notification_id, and (order_id, transaction_id, outcome).
 */
final readonly class DatabaseNotificationStore implements NotificationStore
{
    public const TABLE = 'mib_global_pay_notifications';

    public function __construct(
        private ConnectionInterface $db,
    ) {
    }

    public function claim(string $notificationId, ?string $orderId = null): bool
    {
        return $this->insert(['notification_id' => $notificationId, 'order_id' => $orderId, 'status' => 'claimed']);
    }

    public function release(string $notificationId): void
    {
        $this->db->table(self::TABLE)->where('notification_id', $notificationId)->delete();
    }

    public function markProcessed(string $notificationId, NotificationOutcome $outcome): void
    {
        $this->db->table(self::TABLE)
            ->where('notification_id', $notificationId)
            ->update(['status' => $outcome->value, 'updated_at' => now()]);
    }

    public function outcomeOf(string $notificationId): ?NotificationOutcome
    {
        $status = $this->db->table(self::TABLE)->where('notification_id', $notificationId)->value('status');

        return is_string($status) ? NotificationOutcome::tryFrom($status) : null;
    }

    public function claimTransaction(string $orderId, string $transactionId, string $outcome): bool
    {
        return $this->insert(['order_id' => $orderId, 'transaction_id' => $transactionId, 'outcome' => $outcome]);
    }

    /**
     * @param array<string, string|null> $values
     */
    private function insert(array $values): bool
    {
        try {
            $this->db->table(self::TABLE)->insert($values + ['created_at' => now(), 'updated_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
