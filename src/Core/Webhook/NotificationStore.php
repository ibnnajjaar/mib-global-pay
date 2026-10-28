<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Webhook;

/**
 * Dedupe and idempotency for notifications. Implementations must make claim() and
 * claimTransaction() atomic (e.g. a unique index or an atomic cache add).
 *
 * Two levels:
 *  1. notification level: the gateway redelivers the same X-Notification-ID (up to 20 times
 *     over 3 days), so claim() returns false for an ID already claimed;
 *  2. business level: claimTransaction() returns false when (order ID, transaction ID, outcome)
 *     was already recorded. This also covers the return URL racing the webhook, which has no
 *     notification ID.
 */
interface NotificationStore
{
    /** Atomically record the notification ID; false if it was already claimed. */
    public function claim(string $notificationId, ?string $orderId = null): bool;

    /** Undo a claim whose processing never started (e.g. queueing failed), so a redelivery is processed. */
    public function release(string $notificationId): void;

    public function markProcessed(string $notificationId, NotificationOutcome $outcome): void;

    public function outcomeOf(string $notificationId): ?NotificationOutcome;

    /**
     * Atomically record that this transaction produced $outcome (e.g. "payment_captured");
     * false if it already did.
     */
    public function claimTransaction(string $orderId, string $transactionId, string $outcome): bool;
}
