<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\Source;
use IbnNajjaar\MIBGlobalPay\Laravel\PaymentOutcome;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Settlement;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptState;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableState;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentFailed;
use IbnNajjaar\MIBGlobalPay\Tests\Support\GatewayHarness;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\MismatchReason;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentPending;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\CaptureMismatch;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentCaptured;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentReversed;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\RefundProcessed;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationOutcome;
use IbnNajjaar\MIBGlobalPay\Laravel\Outcome\OutcomeProcessor;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationVerifier;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationStatus;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\UnknownOrderNotification;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentCapturedForUnpayableOrder;

function processor(): OutcomeProcessor
{
    return app(OutcomeProcessor::class);
}

/** Records [event class, payable was locked at dispatch time]. */
function recordEvents(): ArrayObject
{
    $seen = new ArrayObject();
    foreach ([PaymentCaptured::class, PaymentCapturedForUnpayableOrder::class, CaptureMismatch::class, PaymentPending::class, PaymentFailed::class, PaymentReversed::class, UnknownOrderNotification::class, RefundProcessed::class] as $class) {
        Event::listen($class, function (object $event) use ($seen): void {
            $seen[] = [$event::class, property_exists($event, 'payable') ? $event->payable->inLock : null, $event];
        });
    }

    return $seen;
}

it('dispatches PaymentCaptured inside the payable lock, once per capture', function () {
    $payable = hostPayable();
    $resolved = bindResolver($payable)->resolveAttempt(LARAVEL_ORDER);
    fakeGatewayResponses([GatewayHarness::json(200, orderJson()), GatewayHarness::json(200, orderJson())]);
    $seen = recordEvents();

    $first = processor()->process($resolved, Source::Reconcile);
    $second = processor()->process($resolved, Source::Webhook);

    expect($first->event)->toBeInstanceOf(PaymentCaptured::class)
        ->and($first->event->transactionId)->toBe('1')
        ->and($first->event->source)->toBe(Source::Reconcile)
        ->and($second->duplicate)->toBeTrue()
        ->and($second->event)->toBeNull()
        ->and(iterator_to_array($seen))->toHaveCount(1)
        ->and($seen[0][1])->toBeTrue()
        ->and($payable->locks)->toBe(2)
        ->and($payable->state)->toBe(PayableState::Frozen); // the package never marks anything paid
});

it('maps each verification and payable state to the §6.7 E event', function (Closure $payable, array $order, int $status, ?string $event, ?Closure $check = null) {
    $p = $payable();
    $resolved = bindResolver($p)->resolveAttempt(LARAVEL_ORDER);
    fakeGatewayResponses([GatewayHarness::json($status, $order)]);
    $seen = recordEvents();

    $result = processor()->process($resolved, Source::Webhook);

    expect($result->event === null ? null : $result->event::class)->toBe($event);
    if ($event !== null) {
        expect($seen[0][1])->toBeTrue();
    }
    if ($check !== null) {
        $check($result->event);
    }
})->with([
    'captured, paid by the same capture → nothing' => [
        fn () => hostPayable(PayableState::Paid, settlement: new Settlement(LARAVEL_ORDER, '1', frozenAmount(), new DateTimeImmutable())),
        orderJson(), 200, null,
    ],
    'captured, paid by another attempt → double capture' => [
        fn () => hostPayable(PayableState::Paid, settlement: new Settlement('ISIMS-OTHER-1', '1', frozenAmount(), new DateTimeImmutable())),
        orderJson(), 200, CaptureMismatch::class,
        fn (CaptureMismatch $e) => expect($e->reason)->toBe(MismatchReason::DoubleCapture)->and($e->existingSettlement?->gatewayOrderId)->toBe('ISIMS-OTHER-1'),
    ],
    'captured, paid without a settlement → double capture' => [
        fn () => hostPayable(PayableState::Paid), orderJson(), 200, CaptureMismatch::class,
        fn (CaptureMismatch $e) => expect($e->reason)->toBe(MismatchReason::DoubleCapture)->and($e->existingSettlement)->toBeNull(),
    ],
    'captured after cancel' => [
        fn () => hostPayable(PayableState::Cancelled), orderJson(), 200, PaymentCapturedForUnpayableOrder::class,
        fn (PaymentCapturedForUnpayableOrder $e) => expect($e->stateAtCapture)->toBe(PayableState::Cancelled),
    ],
    'captured after failure' => [fn () => hostPayable(PayableState::Failed), orderJson(), 200, PaymentCapturedForUnpayableOrder::class],
    'amount mismatch' => [
        fn () => hostPayable(), orderJson(captured: 1000.0), 200, CaptureMismatch::class,
        fn (CaptureMismatch $e) => expect($e->reason)->toBe(MismatchReason::AmountMismatch)->and($e->verification->captured?->toDecimal())->toBe('1000.00'),
    ],
    'missing captured total → amount mismatch' => [
        fn () => hostPayable(), array_diff_key(orderJson(), ['totalCapturedAmount' => 1]), 200, CaptureMismatch::class,
        fn (CaptureMismatch $e) => expect($e->reason)->toBe(MismatchReason::AmountMismatch),
    ],
    'pending' => [fn () => hostPayable(), orderJson('PENDING', 'PENDING', 0), 200, PaymentPending::class],
    'reversed' => [
        fn () => hostPayable(), orderJson(extra: ['totalRefundedAmount' => 1250.5]), 200, PaymentReversed::class,
        fn (PaymentReversed $e) => expect($e->reversedAmount->toDecimal())->toBe('1250.50'),
    ],
    'not captured, attempt still live → nothing' => [fn () => hostPayable(), orderJson('DECLINED', 'FAILURE', 0), 200, null],
    'not captured, attempt expired → failed' => [fn () => hostPayable(expires: '-5 minutes'), orderJson('DECLINED', 'FAILURE', 0), 200, PaymentFailed::class],
    'not found, attempt expired → failed' => [
        fn () => hostPayable(expires: '-5 minutes'), [], 404, PaymentFailed::class,
        fn (PaymentFailed $e) => expect($e->verification->status)->toBe(VerificationStatus::NotFound),
    ],
    'not found, attempt live → nothing' => [fn () => hostPayable(), [], 404, null],
]);

it('reports a failed webhook transaction as PaymentFailed even before expiry', function () {
    bindResolver(hostPayable());
    fakeGatewayResponses([GatewayHarness::json(200, orderJson('DECLINED', 'FAILURE', 0))]);
    Event::fake([PaymentFailed::class]);

    $notification = NotificationVerifier::unverified(notificationBody(result: 'FAILURE', code: 'DECLINED'), ['X-Notification-ID' => 'N1']);

    expect(processor()->handleNotification($notification))->toBe(NotificationOutcome::Processed);
    Event::assertDispatched(PaymentFailed::class, fn (PaymentFailed $e) => $e->gatewayCode?->value === 'DECLINED');
});

it('reports unknown orders, and ignores authentication and other notifications', function () {
    bindResolver();
    Event::fake([UnknownOrderNotification::class]);

    $payment = NotificationVerifier::unverified(notificationBody(), ['X-Notification-ID' => 'N1']);
    $auth = NotificationVerifier::unverified(notificationBody('AUTHENTICATION'), ['X-Notification-ID' => 'N2']);
    $other = NotificationVerifier::unverified('{"alert":{}}', ['X-Notification-ID' => 'N3']);
    $authorization = NotificationVerifier::unverified(notificationBody('AUTHORIZATION'), ['X-Notification-ID' => 'N4']);

    expect(processor()->handleNotification($payment))->toBe(NotificationOutcome::Processed)
        ->and(processor()->handleNotification($auth))->toBe(NotificationOutcome::Ignored)
        ->and(processor()->handleNotification($other))->toBe(NotificationOutcome::Ignored)
        ->and(processor()->handleNotification($authorization))->toBe(NotificationOutcome::Ignored);

    Event::assertDispatchedTimes(UnknownOrderNotification::class, 1);
});

it('dispatches RefundProcessed once, then re-verifies the order', function () {
    bindResolver(hostPayable(PayableState::Paid, settlement: new Settlement(LARAVEL_ORDER, '1', frozenAmount(), new DateTimeImmutable())));
    fakeGatewayResponses([
        GatewayHarness::json(200, orderJson(extra: ['totalRefundedAmount' => 1250.5])),
        GatewayHarness::json(200, orderJson(extra: ['totalRefundedAmount' => 1250.5])),
    ]);
    Event::fake([RefundProcessed::class, PaymentReversed::class]);

    $refund = NotificationVerifier::unverified(notificationBody('REFUND', txnId: 'refund-1'), ['X-Notification-ID' => 'N1']);
    processor()->handleNotification($refund);
    processor()->handleNotification(NotificationVerifier::unverified(notificationBody('REFUND', txnId: 'refund-1'), ['X-Notification-ID' => 'N2']));

    Event::assertDispatchedTimes(RefundProcessed::class, 1);
    Event::assertDispatchedTimes(PaymentReversed::class, 1);
    Event::assertDispatched(RefundProcessed::class, fn (RefundProcessed $e) => $e->resolved !== null && $e->transaction->transactionId === 'refund-1');
});

it('runs the same pipeline from the return URL', function () {
    bindResolver(hostPayable());
    fakeGatewayResponses([GatewayHarness::json(200, orderJson())]);
    Event::fake([PaymentCaptured::class]);

    $result = app(PaymentOutcome::class)->resolveFromReturn(LARAVEL_ORDER, ['resultIndicator' => 'f8e1b1e3c2d54a7b']);

    expect($result['indicatorMatched'])->toBeTrue()
        ->and($result['outcome']?->event)->toBeInstanceOf(PaymentCaptured::class);
    Event::assertDispatched(PaymentCaptured::class, fn (PaymentCaptured $e) => $e->source === Source::ReturnUrl);
});

it('verifies from the gateway even when the return indicator does not match', function () {
    bindResolver(hostPayable());
    fakeGatewayResponses([GatewayHarness::json(200, orderJson())]);
    Event::fake([PaymentCaptured::class]);

    $result = app(PaymentOutcome::class)->resolveFromReturn(LARAVEL_ORDER, ['resultIndicator' => 'forged']);

    expect($result['indicatorMatched'])->toBeFalse()
        ->and($result['outcome']?->verification?->status)->toBe(VerificationStatus::Captured);
});

it('returns nothing from the return URL for an unknown order', function () {
    bindResolver();

    expect(app(PaymentOutcome::class)->resolveFromReturn('UNKNOWN', []))->toBe(['indicatorMatched' => false, 'attempt' => null, 'outcome' => null]);
});

it('does not run the pipeline for an expired attempt without notification when the store already has the event', function () {
    $resolved = bindResolver(hostPayable(attemptState: AttemptState::Expired, expires: '-1 minute'))->resolveAttempt(LARAVEL_ORDER);
    fakeGatewayResponses([GatewayHarness::json(200, orderJson()), GatewayHarness::json(200, orderJson())]);
    Event::fake([PaymentCaptured::class]);

    processor()->process($resolved, Source::Reconcile);
    processor()->process($resolved, Source::Reconcile);

    Event::assertDispatchedTimes(PaymentCaptured::class, 1);
});
