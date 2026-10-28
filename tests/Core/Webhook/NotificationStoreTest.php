<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationStore;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationOutcome;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\InMemoryNotificationStore;

it('implements NotificationStore', function () {
    expect(new InMemoryNotificationStore())->toBeInstanceOf(NotificationStore::class);
});

it('claims a notification ID once', function () {
    $store = new InMemoryNotificationStore();

    expect($store->claim('NOTIF-1', 'ORDER-1'))->toBeTrue()
        ->and($store->claim('NOTIF-1', 'ORDER-1'))->toBeFalse()
        ->and($store->claim('NOTIF-1'))->toBeFalse()
        ->and($store->claim('NOTIF-2', 'ORDER-1'))->toBeTrue();
});

it('records outcomes', function () {
    $store = new InMemoryNotificationStore();
    $store->claim('NOTIF-1');

    expect($store->outcomeOf('NOTIF-1'))->toBeNull();

    $store->markProcessed('NOTIF-1', NotificationOutcome::Processed);

    expect($store->outcomeOf('NOTIF-1'))->toBe(NotificationOutcome::Processed)
        ->and($store->outcomeOf('UNKNOWN'))->toBeNull()
        ->and($store->claim('NOTIF-1'))->toBeFalse();
});

it('releases a claim so a redelivery is processed', function () {
    $store = new InMemoryNotificationStore();
    $store->claim('NOTIF-1');
    $store->release('NOTIF-1');

    expect($store->claim('NOTIF-1'))->toBeTrue();
});

it('dedupes on order ID plus transaction ID per outcome', function () {
    $store = new InMemoryNotificationStore();

    expect($store->claimTransaction('ORDER-1', '1', 'payment_captured'))->toBeTrue()
        ->and($store->claimTransaction('ORDER-1', '1', 'payment_captured'))->toBeFalse()
        ->and($store->claimTransaction('ORDER-1', '1', 'refund_processed'))->toBeTrue()
        ->and($store->claimTransaction('ORDER-1', '2', 'payment_captured'))->toBeTrue()
        ->and($store->claimTransaction('ORDER-2', '1', 'payment_captured'))->toBeTrue();
});

it('does not confuse keys that concatenate to the same string', function () {
    $store = new InMemoryNotificationStore();

    expect($store->claimTransaction('A-1', '2', 'x'))->toBeTrue()
        ->and($store->claimTransaction('A', '1-2', 'x'))->toBeTrue();
});
