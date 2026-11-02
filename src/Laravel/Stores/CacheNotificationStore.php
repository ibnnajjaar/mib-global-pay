<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Stores;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\DatabaseTransactionsManager;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationStore;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationOutcome;

/**
 * NotificationStore on the cache, using atomic Cache::add(). The dedupe window for notifications
 * and advisory events is the TTL: 5 days, longer than the gateway's 3-day redelivery window. Use a
 * shared cache store (redis, database, ...) when several servers receive webhooks.
 *
 * Delivery records have no TTL. Reconcile, AttemptGuard and the return URL can reach an attempt at
 * any time, so any TTL would send its money event again each time it ran out. A cache flush or an
 * eviction still does that, which is why listeners must be idempotent on deliveryKey.
 *
 * The cache cannot roll back. A delivery recorded inside a host transaction therefore waits for the
 * commit and is dropped on rollback, so the event goes out again with the work that was undone.
 */
final readonly class CacheNotificationStore implements NotificationStore
{
    public const TTL_SECONDS = 5 * 24 * 3600;

    private const string PREFIX = 'mib-global-pay:';

    public function __construct(
        private Repository $cache,
        private ?DatabaseTransactionsManager $transactions = null,
    ) {
    }

    public function claim(string $notificationId, ?string $orderId = null): bool
    {
        return $this->cache->add($this->key('n', $notificationId), 'claimed', self::TTL_SECONDS);
    }

    public function release(string $notificationId): void
    {
        $this->cache->forget($this->key('n', $notificationId));
    }

    public function markProcessed(string $notificationId, NotificationOutcome $outcome): void
    {
        $this->cache->put($this->key('n', $notificationId), $outcome->value, self::TTL_SECONDS);
    }

    public function outcomeOf(string $notificationId): ?NotificationOutcome
    {
        $value = $this->cache->get($this->key('n', $notificationId));

        return is_string($value) ? NotificationOutcome::tryFrom($value) : null;
    }

    public function claimTransaction(string $orderId, string $transactionId, string $outcome): bool
    {
        return $this->cache->add($this->transactionKey($orderId, $transactionId, $outcome), true, self::TTL_SECONDS);
    }

    public function wasDelivered(string $orderId, string $transactionId, string $outcome): bool
    {
        return $this->cache->has($this->transactionKey($orderId, $transactionId, $outcome));
    }

    public function markDelivered(string $orderId, string $transactionId, string $outcome): void
    {
        $record = fn (): bool => $this->cache->forever($this->transactionKey($orderId, $transactionId, $outcome), true);

        // Runs at once when no transaction is open, on any connection.
        $this->transactions instanceof DatabaseTransactionsManager ? $this->transactions->addCallback($record) : $record();
    }

    private function transactionKey(string $orderId, string $transactionId, string $outcome): string
    {
        return $this->key('t', json_encode([$orderId, $transactionId, $outcome], JSON_THROW_ON_ERROR));
    }

    private function key(string $type, string $value): string
    {
        return self::PREFIX . $type . ':' . hash('sha256', $value);
    }
}
