<?php

declare(strict_types=1);

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\Source;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptState;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\GuardRefusal;
use IbnNajjaar\MIBGlobalPay\Laravel\Facades\MibGlobalPay;
use IbnNajjaar\MIBGlobalPay\Laravel\Testing\OrderFactory;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentCaptured;
use IbnNajjaar\MIBGlobalPay\Laravel\Testing\NotificationFactory;
use IbnNajjaar\MIBGlobalPay\Laravel\Testing\InteractsWithMibGlobalPay;
use IbnNajjaar\MIBGlobalPay\Laravel\MibGlobalPay as MibGlobalPayService;

uses(InteractsWithMibGlobalPay::class);

it('runs the capture pipeline when the guard finds a live attempt already captured', function () {
    MibGlobalPay::fake()->order(LARAVEL_ORDER, OrderFactory::captured(frozenAmount()));
    $payable = hostPayable(attemptState: AttemptState::Live); // not expired
    bindResolver($payable);
    Event::fake([PaymentCaptured::class]);

    $result = $payable->lock(fn () => MibGlobalPay::attemptGuard()->canStartNewAttempt($payable));

    expect($result->reason)->toBe(GuardRefusal::MoneyCaptured)
        ->and($result->reusableAttempt)->toBeNull()
        ->and($payable->locks)->toBe(2); // the host's lock, then the pipeline's nested lock
    Event::assertDispatched(PaymentCaptured::class, fn (PaymentCaptured $e) => $e->source === Source::AttemptGuard && $e->transactionId === '1');
});

it('offers a live, unpaid attempt for reuse after asking the bank', function () {
    $fake = MibGlobalPay::fake(); // unknown order = not paid yet
    $payable = hostPayable(attemptState: AttemptState::Live);

    $result = MibGlobalPay::attemptGuard()->canStartNewAttempt($payable);

    expect($result->reason)->toBe(GuardRefusal::AttemptStillLive)
        ->and($result->reusableAttempt)->toBe($payable->attempts[0]);
    $fake->assertOrderRetrieved(LARAVEL_ORDER);
});

it('finds the webhook route inside a name-prefixed, domain-scoped host group', function () {
    $this->bootWith(['mib-global-pay.webhook.register_route' => false]);
    URL::forceScheme('https');

    Route::domain('students.ium.test')->name('student.')->middleware('web')->group(function () {
        Route::mibGlobalPayWebhook('payment-gateways/mib/webhook');
    });
    Route::getRoutes()->refreshNameLookups();

    expect(Route::has('mib-global-pay.webhook'))->toBeFalse()
        ->and(Route::has('student.mib-global-pay.webhook'))->toBeTrue()
        ->and(app(MibGlobalPayService::class)->webhookUrl())->toBe('https://students.ium.test/payment-gateways/mib/webhook');

    $request = MibGlobalPay::newCheckoutRequest(LARAVEL_ORDER, Money::of('10', Currency::Mvr), 'IUM', 'https://students.ium.test/return');

    expect($request->notificationUrl())->toBe('https://students.ium.test/payment-gateways/mib/webhook');
});

it('posts test notifications to a name-prefixed route', function () {
    $this->bootWith(['mib-global-pay.webhook.register_route' => false]);
    Route::name('student.')->group(fn () => Route::mibGlobalPayWebhook('payment-gateways/mib/webhook'));
    Route::getRoutes()->refreshNameLookups();
    MibGlobalPay::fake()->order(LARAVEL_ORDER, OrderFactory::captured(frozenAmount()));
    bindResolver(hostPayable());
    Event::fake([PaymentCaptured::class]);

    $this->postMibNotification(NotificationFactory::paymentCaptured(LARAVEL_ORDER, frozenAmount()))->assertOk();

    Event::assertDispatched(PaymentCaptured::class);
});
