<?php

declare(strict_types=1);

/*
 * A host-style Pest test (as ISIMS would write it): webhook → job → PaymentCaptured, all through
 * the fake, no network. The host side here is the in-memory Payable/Resolver test doubles.
 */

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Event;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\Source;
use IbnNajjaar\MIBGlobalPay\Laravel\PaymentOutcome;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Settlement;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableState;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentFailed;
use IbnNajjaar\MIBGlobalPay\Laravel\Facades\MibGlobalPay;
use IbnNajjaar\MIBGlobalPay\Laravel\Testing\OrderFactory;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\MismatchReason;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\CaptureMismatch;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentCaptured;
use IbnNajjaar\MIBGlobalPay\Laravel\Testing\NotificationFactory;
use IbnNajjaar\MIBGlobalPay\Laravel\Testing\InteractsWithMibGlobalPay;

uses(InteractsWithMibGlobalPay::class);

it('marks nothing paid itself, but dispatches PaymentCaptured for a verified webhook', function () {
    $fake = MibGlobalPay::fake()->order(LARAVEL_ORDER, OrderFactory::captured(frozenAmount()));
    $payable = hostPayable();
    bindResolver($payable);
    Event::fake([PaymentCaptured::class]);

    $this->postMibNotification(NotificationFactory::paymentCaptured(LARAVEL_ORDER, frozenAmount(), notificationId: 'NOTIF-77'))
        ->assertOk()
        ->assertJson(['status' => 'accepted']);

    Event::assertDispatched(PaymentCaptured::class, fn (PaymentCaptured $e) => $e->gatewayOrderId === LARAVEL_ORDER
        && $e->transactionId === '1'
        && $e->source === Source::Webhook
        && $e->notification?->id() === 'NOTIF-77'
        && $e->verification->isCapturedExactly());
    $fake->assertOrderRetrieved(LARAVEL_ORDER);
    expect($payable->state)->toBe(PayableState::Frozen);
});

it('answers a redelivered notification with 200 and processes it once', function () {
    MibGlobalPay::fake()->order(LARAVEL_ORDER, OrderFactory::captured(frozenAmount()));
    bindResolver(hostPayable());
    Event::fake([PaymentCaptured::class]);
    $notification = NotificationFactory::paymentCaptured(LARAVEL_ORDER, frozenAmount());

    $this->postMibNotification($notification)->assertOk();
    $this->postMibNotification($notification)->assertOk()->assertJson(['status' => 'duplicate']);

    Event::assertDispatchedTimes(PaymentCaptured::class, 1);
});

it('rejects a notification signed with the wrong secret', function () {
    MibGlobalPay::fake();
    bindResolver(hostPayable());

    $this->postMibNotification(NotificationFactory::paymentCaptured(LARAVEL_ORDER, frozenAmount()), secret: 'wrong')->assertStatus(401);
});

it('flags a capture that no longer matches the frozen amount', function () {
    MibGlobalPay::fake()->order(LARAVEL_ORDER, OrderFactory::captured(\IbnNajjaar\MIBGlobalPay\Core\Money\Money::of('99.00', frozenAmount()->currency)));
    bindResolver(hostPayable());
    Event::fake([CaptureMismatch::class, PaymentCaptured::class]);

    $this->postMibNotification(NotificationFactory::paymentCaptured(LARAVEL_ORDER, frozenAmount()))->assertOk();

    Event::assertNotDispatched(PaymentCaptured::class);
    Event::assertDispatched(CaptureMismatch::class, fn (CaptureMismatch $e) => $e->reason === MismatchReason::AmountMismatch);
});

it('reports a double capture on an already settled payable', function () {
    MibGlobalPay::fake()->order(LARAVEL_ORDER, OrderFactory::captured(frozenAmount()));
    bindResolver(hostPayable(PayableState::Paid, settlement: new Settlement('ISIMS-FIRST-1', '1', frozenAmount(), new DateTimeImmutable())));
    Event::fake([CaptureMismatch::class]);

    $this->postMibNotification(NotificationFactory::paymentCaptured(LARAVEL_ORDER, frozenAmount()))->assertOk();

    Event::assertDispatched(CaptureMismatch::class, fn (CaptureMismatch $e) => $e->reason === MismatchReason::DoubleCapture);
});

it('reports a declined payment', function () {
    MibGlobalPay::fake()->order(LARAVEL_ORDER, OrderFactory::declined(frozenAmount()));
    bindResolver(hostPayable());
    Event::fake([PaymentFailed::class]);

    $this->postMibNotification(NotificationFactory::paymentDeclined(LARAVEL_ORDER, frozenAmount()))->assertOk();

    Event::assertDispatched(PaymentFailed::class);
});

it('runs checkout, return URL and reconciliation through the fake', function () {
    URL::forceScheme('https'); // the generated webhook URL must be HTTPS
    $fake = MibGlobalPay::fake()
        ->checkoutSession(LARAVEL_ORDER, sessionId: 'SESSION0001', successIndicator: 'f8e1b1e3c2d54a7b')
        ->order(LARAVEL_ORDER, OrderFactory::pending(frozenAmount()), OrderFactory::captured(frozenAmount()));
    bindResolver(hostPayable());
    Event::fake([PaymentCaptured::class]);

    $session = MibGlobalPay::initiateCheckout(
        MibGlobalPay::newCheckoutRequest(LARAVEL_ORDER, frozenAmount(), 'Islamic University of Maldives', 'https://students.ium.test/return')
            ->withReference('(R)IUM/FN/2026/123'),
    );
    $return = app(PaymentOutcome::class)->resolveFromReturn(LARAVEL_ORDER, ['resultIndicator' => $session->successIndicator]);
    $this->artisan('mib:reconcile')->assertSuccessful();

    expect($session->sessionId)->toBe('SESSION0001')
        ->and($return['indicatorMatched'])->toBeTrue()
        ->and($return['outcome']?->event)->toBeInstanceOf(\IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentPending::class); // first read: pending
    Event::assertDispatchedTimes(PaymentCaptured::class, 1); // captured on the reconcile read
    $fake->assertCheckoutInitiated(fn ($r) => $r->reference() === '(R)IUM/FN/2026/123' && $r->notificationUrl() === route('mib-global-pay.webhook'));
});
