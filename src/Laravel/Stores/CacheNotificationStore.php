<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Stores;

use Illuminate\Contracts\Cache\Repository;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationStore;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationOutcome;

/**
 * NotificationStore on the cache, using atomic Cache::add(). The dedupe window is the TTL:
 * 5 days, longer than the gateway's 3-day redelivery window. Use a shared cache store
 * (redis, database, ...) when several servers receive webhooks.
 */
final readonly class CacheNotificationStore implements NotificationStore
{
    public const TTL_SECONDS = 5 * 24 * 3600;

    private const PREFIX = 'mib-global-pay:';

    public function __construct(
        private Repository $cache,
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
        return $this->cache->add($this->key('t', json_encode([$orderId, $transactionId, $outcome], JSON_THROW_ON_ERROR)), true, self::TTL_SECONDS);
    }

    private function key(string $type, string $value): string
    {
        return self::PREFIX . $type . ':' . hash('sha256', $value);
    }
}
