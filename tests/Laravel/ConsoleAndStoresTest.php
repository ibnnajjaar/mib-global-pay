<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptState;
use IbnNajjaar\MIBGlobalPay\Tests\Support\GatewayHarness;
use IbnNajjaar\MIBGlobalPay\Tests\Support\InMemoryAttempt;
use IbnNajjaar\MIBGlobalPay\Tests\Support\InMemoryPayable;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationStore;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentCaptured;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationOutcome;
use IbnNajjaar\MIBGlobalPay\Laravel\Stores\CacheNotificationStore;
use IbnNajjaar\MIBGlobalPay\Laravel\Stores\DatabaseNotificationStore;

function useDatabaseStore(object $test): DatabaseNotificationStore
{
    config()->set('database.default', 'testing');
    config()->set('mib-global-pay.notification_store', 'database');
    $migration = require __DIR__ . '/../../database/migrations/2026_10_28_000000_create_mib_global_pay_notifications_table.php';
    $migration->up();
    app()->forgetInstance(NotificationStore::class);

    return app(NotificationStore::class);
}

dataset('stores', [
    'database' => [fn () => useDatabaseStore(test())],
    'cache' => [fn () => app(CacheNotificationStore::class)],
]);

it('dedupes notifications and transactions', function (Closure $make) {
    /** @var NotificationStore $store */
    $store = $make();

    expect($store->claim('N1', 'O-1'))->toBeTrue()
        ->and($store->claim('N1', 'O-1'))->toBeFalse()
        ->and($store->outcomeOf('N1'))->toBeNull();

    $store->markProcessed('N1', NotificationOutcome::Processed);
    expect($store->outcomeOf('N1'))->toBe(NotificationOutcome::Processed)
        ->and($store->claim('N1'))->toBeFalse();

    $store->claim('N2');
    $store->release('N2');
    expect($store->claim('N2'))->toBeTrue()
        ->and($store->claimTransaction('O-1', '1', 'payment_captured'))->toBeTrue()
        ->and($store->claimTransaction('O-1', '1', 'payment_captured'))->toBeFalse()
        ->and($store->claimTransaction('O-1', '1', 'double_capture'))->toBeTrue()
        ->and($store->claimTransaction('O-1', '2', 'payment_captured'))->toBeTrue();
})->with('stores');

it('binds the database store when configured, and the migration creates its table', function () {
    $store = useDatabaseStore(test());

    expect($store)->toBeInstanceOf(DatabaseNotificationStore::class)
        ->and(Schema::hasTable('mib_global_pay_notifications'))->toBeTrue();
});

it('reconciles attempts awaiting the gateway', function () {
    $payable = hostPayable();
    $done = new InMemoryPayable(frozenAmount(), attempts: [new InMemoryAttempt(1, 'ISIMS-DONE-1', frozenAmount(), AttemptState::Failed, new DateTimeImmutable('-1 hour'))]);
    bindResolver($payable, $done);
    $harness = fakeGatewayResponses([GatewayHarness::json(200, orderJson())]);
    Event::fake([PaymentCaptured::class]);

    $this->artisan('mib:reconcile')
        ->expectsTable(['Order ID', 'Verification', 'Event'], [[LARAVEL_ORDER, 'captured', 'PaymentCaptured']])
        ->assertSuccessful();

    Event::assertDispatched(PaymentCaptured::class);
    expect($harness->history)->toHaveCount(1);
});

it('reports reconcile errors and keeps going', function () {
    bindResolver(hostPayable());
    fakeGatewayResponses([GatewayHarness::json(500, '')]);

    $this->artisan('mib:reconcile')->assertFailed();
});

it('says when nothing awaits the gateway', function () {
    bindResolver();

    $this->artisan('mib:reconcile')->expectsOutput('No attempts awaiting the gateway.')->assertSuccessful();
});

it('prunes old database records only', function () {
    useDatabaseStore(test());
    DB::table('mib_global_pay_notifications')->insert([
        ['notification_id' => 'OLD', 'status' => 'processed', 'created_at' => now()->subDays(40), 'updated_at' => now()->subDays(40)],
        ['notification_id' => 'NEW', 'status' => 'processed', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->artisan('mib:prune-notifications', ['--days' => 30])->expectsOutput('Deleted 1 record(s).')->assertSuccessful();
    $this->artisan('mib:prune-notifications', ['--days' => 2])->assertExitCode(2);

    expect(DB::table('mib_global_pay_notifications')->pluck('notification_id')->all())->toBe(['NEW']);
});
