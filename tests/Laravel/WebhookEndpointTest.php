<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Contracts\Bus\Dispatcher;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationStore;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentCaptured;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationOutcome;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\NotificationReceived;
use IbnNajjaar\MIBGlobalPay\Laravel\MibGlobalPay as MibGlobalPayService;

it('auto-registers POST {path} named mib-global-pay.webhook', function () {
    $route = Route::getRoutes()->getByName(MibGlobalPayService::WEBHOOK_ROUTE_NAME);

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('mib/webhook')
        ->and($route->methods())->toContain('POST')
        ->and($route->getDomain())->toBeNull()
        ->and($route->excludedMiddleware())->toContain(ValidateCsrfToken::class);
});

it('uses MIB_WEBHOOK_DOMAIN and MIB_WEBHOOK_PATH', function () {
    $this->bootWith(['mib-global-pay.webhook.domain' => 'students.ium.test', 'mib-global-pay.webhook.path' => 'payment-gateways/mib/webhook']);

    $route = Route::getRoutes()->getByName(MibGlobalPayService::WEBHOOK_ROUTE_NAME);

    expect($route->uri())->toBe('payment-gateways/mib/webhook')
        ->and($route->getDomain())->toBe('students.ium.test');
});

it('registers no route when register_route is false, and the macro registers it in a host group', function () {
    $this->bootWith(['mib-global-pay.webhook.register_route' => false]);

    expect(Route::getRoutes()->getByName(MibGlobalPayService::WEBHOOK_ROUTE_NAME))->toBeNull();

    Route::domain('students.ium.test')->middleware('web')->group(function () use (&$route) {
        $route = Route::mibGlobalPayWebhook('/payment-gateways/mib/webhook');
    });
    Route::getRoutes()->refreshNameLookups();

    expect($route)->toBeInstanceOf(Illuminate\Routing\Route::class)
        ->and(Route::getRoutes()->getByName(MibGlobalPayService::WEBHOOK_ROUTE_NAME))->toBe($route)
        ->and($route->getDomain())->toBe('students.ium.test')
        ->and($route->uri())->toBe('payment-gateways/mib/webhook')
        ->and($route->excludedMiddleware())->toContain(ValidateCsrfToken::class)
        ->and(app(MibGlobalPayService::class)->webhookUrl())->toBe('http://students.ium.test/payment-gateways/mib/webhook');
});

it('registers no route when the webhook is disabled', function () {
    $this->bootWith(['mib-global-pay.webhook.enabled' => false]);

    expect(Route::getRoutes()->getByName(MibGlobalPayService::WEBHOOK_ROUTE_NAME))->toBeNull();
});

it('accepts a verified notification, queues processing and answers 200', function () {
    bindResolver($payable = hostPayable());
    fakeGatewayResponses([\IbnNajjaar\MIBGlobalPay\Tests\Support\GatewayHarness::json(200, orderJson())]);
    Event::fake([PaymentCaptured::class, NotificationReceived::class]);

    $this->call('POST', '/mib/webhook', [], [], [], webhookServer(), notificationBody())
        ->assertOk()->assertJson(['status' => 'accepted']);

    Event::assertDispatched(NotificationReceived::class);
    Event::assertDispatched(PaymentCaptured::class, fn (PaymentCaptured $e) => $e->transactionId === '1' && $e->payable === $payable);
    expect(app(NotificationStore::class)->outcomeOf('NOTIF-1'))->toBe(NotificationOutcome::Processed);
});

it('answers 200 to a duplicate without reprocessing', function () {
    bindResolver(hostPayable());
    $harness = fakeGatewayResponses([\IbnNajjaar\MIBGlobalPay\Tests\Support\GatewayHarness::json(200, orderJson())]);
    Event::fake([PaymentCaptured::class]);

    $this->call('POST', '/mib/webhook', [], [], [], webhookServer(), notificationBody())->assertOk();
    $this->call('POST', '/mib/webhook', [], [], [], webhookServer(), notificationBody())
        ->assertOk()->assertJson(['status' => 'duplicate']);

    Event::assertDispatchedTimes(PaymentCaptured::class, 1);
    expect($harness->history)->toHaveCount(1);
});

it('rejects a missing or wrong secret with 401 and never 200', function (?string $secret) {
    bindResolver(hostPayable());
    Event::fake([NotificationReceived::class, PaymentCaptured::class]);

    $this->call('POST', '/mib/webhook', [], [], [], webhookServer(secret: $secret), notificationBody())->assertStatus(401);

    Event::assertNotDispatched(NotificationReceived::class);
    Event::assertNotDispatched(PaymentCaptured::class);
})->with([[null], ['wrong'], ['']]);

it('rejects a malformed notification with 400', function () {
    bindResolver(hostPayable());
    $server = webhookServer();
    unset($server['HTTP_X_NOTIFICATION_ID']);

    $this->call('POST', '/mib/webhook', [], [], [], $server, notificationBody())->assertStatus(400);
    $this->call('POST', '/mib/webhook', [], [], [], webhookServer('NOTIF-2'), 'not json')->assertStatus(400);
});

it('fails closed with 503 when no secret is configured', function () {
    config()->set('mib-global-pay.webhook.secret', null);
    bindResolver(hostPayable());

    $this->call('POST', '/mib/webhook', [], [], [], webhookServer(), notificationBody())->assertStatus(503);
});

it('answers 503 while no PayableResolver is bound', function () {
    Event::fake([NotificationReceived::class]);

    $this->call('POST', '/mib/webhook', [], [], [], webhookServer(), notificationBody())->assertStatus(503);

    Event::assertNotDispatched(NotificationReceived::class);
    expect(app(NotificationStore::class)->claim('NOTIF-1'))->toBeTrue();
});

it('releases the claim and answers 503 when queueing fails', function () {
    bindResolver(hostPayable());
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));
    app()->instance(Dispatcher::class, $bus);

    $this->call('POST', '/mib/webhook', [], [], [], webhookServer(), notificationBody())->assertStatus(503);

    expect(app(NotificationStore::class)->claim('NOTIF-1'))->toBeTrue();
});

it('queues on the configured connection and queue', function () {
    config()->set('mib-global-pay.webhook.queue', 'payments');
    bindResolver(hostPayable());
    Illuminate\Support\Facades\Bus::fake();

    $this->call('POST', '/mib/webhook', [], [], [], webhookServer(), notificationBody())->assertOk();

    Illuminate\Support\Facades\Bus::assertDispatched(
        IbnNajjaar\MIBGlobalPay\Laravel\Jobs\ProcessNotification::class,
        fn ($job) => $job->queue === 'payments' && ! array_key_exists('X-Notification-Secret', $job->headers),
    );
});
