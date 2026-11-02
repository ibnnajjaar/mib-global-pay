<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Stores;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseTransactionsManager;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationStore;
use Illuminate\Database\UniqueConstraintViolationException;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationOutcome;

/**
 * NotificationStore on the mib_global_pay_notifications table. Atomicity comes from its unique
 * indexes: notification_id, and (order_id, transaction_id, outcome).
 */
final readonly class DatabaseNotificationStore implements NotificationStore
{
    public const string TABLE = 'mib_global_pay_notifications';

    public function __construct(
        private ConnectionInterface $db,
        private ?DatabaseTransactionsManager $transactions = null,
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

    public function wasDelivered(string $orderId, string $transactionId, string $outcome): bool
    {
        return $this->db->table(self::TABLE)
            ->where(['order_id' => $orderId, 'transaction_id' => $transactionId, 'outcome' => $outcome])
            ->exists();
    }

    /**
     * Inside a transaction on this connection the record commits or rolls back with it. With none
     * open here, the host's may be on another connection, so the record waits for that commit and
     * is dropped on rollback. With no transaction anywhere it runs at once.
     */
    public function markDelivered(string $orderId, string $transactionId, string $outcome): void
    {
        $record = fn (): bool => $this->claimTransaction($orderId, $transactionId, $outcome);

        $this->db->transactionLevel() === 0 && $this->transactions instanceof DatabaseTransactionsManager
            ? $this->transactions->addCallback($record)
            : $record();
    }

    /**
     * False only for a real duplicate, which the database reports as a unique violation; any other
     * error surfaces. Hence no insertOrIgnore: MySQL's INSERT IGNORE would truncate an over-long ID
     * into a false duplicate, and it and SQLite's INSERT OR IGNORE swallow NOT NULL failures.
     *
     * Inside a host transaction the insert gets its own savepoint: on PostgreSQL a failed statement
     * aborts the whole transaction otherwise, so the host's later queries and its commit would fail.
     *
     * @param array<string, string|null> $values
     */
    private function insert(array $values): bool
    {
        $insert = fn (): bool => $this->db->table(self::TABLE)->insert($values + ['created_at' => now(), 'updated_at' => now()]);

        try {
            $this->db->transactionLevel() > 0 ? $this->db->transaction($insert) : $insert();
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
