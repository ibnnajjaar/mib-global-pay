<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Webhook;

/**
 * Dedupe and idempotency for notifications. Implementations must make claim() and
 * claimTransaction() atomic (e.g. a unique index or an atomic cache add).
 *
 * Three uses:
 *  1. notification level: the gateway redelivers the same X-Notification-ID (up to 20 times
 *     over 3 days), so claim() returns false for an ID already claimed;
 *  2. advisory events: claimTransaction() returns false when (order ID, transaction ID, outcome)
 *     was already recorded, and is taken before dispatching, for at most once;
 *  3. money events: wasDelivered() is checked before dispatching and markDelivered() is called only
 *     once a dispatch has completed, for at least once. A failed dispatch records nothing, so a
 *     retry or a later trigger dispatches again.
 *
 * claimTransaction() and markDelivered() share one record per (order ID, transaction ID, outcome).
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

    /** True if a dispatch of this event has been recorded as complete. */
    public function wasDelivered(string $orderId, string $transactionId, string $outcome): bool;

    /**
     * Record that a dispatch of this event completed. Idempotent, including when several processes
     * record the same event at once. Must not outlive a host transaction that rolls back, so that
     * the event goes out again together with the work that was undone.
     */
    public function markDelivered(string $orderId, string $transactionId, string $outcome): void;
}
