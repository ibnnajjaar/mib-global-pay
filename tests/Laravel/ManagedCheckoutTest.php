<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Database\Schema\Blueprint;
use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptState;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\GuardRefusal;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableState;
use IbnNajjaar\MIBGlobalPay\Laravel\Jobs\ReverseCapture;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentFailed;
use IbnNajjaar\MIBGlobalPay\Laravel\Facades\MibGlobalPay;
use IbnNajjaar\MIBGlobalPay\Laravel\Testing\OrderFactory;
use IbnNajjaar\MIBGlobalPay\Tests\Support\PayableInvoice;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableResolver;
use IbnNajjaar\MIBGlobalPay\Core\Requests\CheckoutRequest;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentPending;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\AccountVerified;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\CaptureMismatch;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentCaptured;
use IbnNajjaar\MIBGlobalPay\Laravel\Models\CheckoutAttempt;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentAuthorized;
use IbnNajjaar\MIBGlobalPay\Laravel\Checkout\AttemptNotUnpaid;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentNeedsReview;
use IbnNajjaar\MIBGlobalPay\Core\Requests\InteractionOperation;
use IbnNajjaar\MIBGlobalPay\Laravel\Checkout\CheckoutNotAllowed;
use IbnNajjaar\MIBGlobalPay\Laravel\Testing\NotificationFactory;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentPartiallyCaptured;
use IbnNajjaar\MIBGlobalPay\Laravel\Checkout\EloquentPayableResolver;
use IbnNajjaar\MIBGlobalPay\Laravel\Testing\InteractsWithMibGlobalPay;
use IbnNajjaar\MIBGlobalPay\Core\Operations\PaymentLink\InitiatePaymentLink;

uses(InteractsWithMibGlobalPay::class);

beforeEach(function () {
    URL::forceScheme('https');
    config()->set('database.default', 'testing');
    config()->set('mib-global-pay.checkout.merchant_name', 'My Shop');
    config()->set('mib-global-pay.checkout.merchant_url', 'https://shop.example');

    Schema::create('invoices', function (Blueprint $table): void {
        $table->id();
        $table->string('reference');
        $table->string('total');
        $table->timestamps();
    });
    (require __DIR__ . '/../../database/migrations/2026_11_02_000000_create_mib_global_pay_attempts_table.php')->up();
});

function managedInvoice(string $total = '100.00'): PayableInvoice
{
    return PayableInvoice::create(['reference' => '(R)INV/1', 'total' => $total]);
}

function managedMvr(string $amount = '100.00'): Money
{
    return Money::of($amount, Currency::Mvr);
}

it('binds the package resolver by default', function () {
    expect(app(PayableResolver::class))->toBeInstanceOf(EloquentPayableResolver::class);
});

it('starts a checkout from one call and stores the attempt', function () {
    $fake = MibGlobalPay::fake()->checkoutSession(sessionId: 'SESSION1', successIndicator: 'INDICATOR1');
    $invoice = managedInvoice();

    $attempt = MibGlobalPay::startCheckout($invoice);

    expect($attempt->gatewayOrderId())->toBe('ISIMS-R-INV-1-1')
        ->and($attempt->state())->toBe(AttemptState::Live)
        ->and($attempt->sessionId())->toBe('SESSION1')
        ->and($attempt->successIndicator())->toBe('INDICATOR1')
        ->and($attempt->amount()->equals(managedMvr()))->toBeTrue()
        ->and($invoice->payableState())->toBe(PayableState::Frozen);

    $fake->assertCheckoutInitiated(function (CheckoutRequest $request): bool {
        $url = $request->returnUrl;

        return str_contains($url, '/mib/return/ISIMS-R-INV-1-1') && $request->reference() === '(R)INV/1';
    });
});

it('lets the host add to the request', function () {
    $fake = MibGlobalPay::fake();

    MibGlobalPay::startCheckout(managedInvoice(), customize: fn (CheckoutRequest $r): CheckoutRequest => $r->withDescription('Custom'));

    $fake->assertCheckoutInitiated(fn (CheckoutRequest $r): bool => $r->toBody(app(\IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig::class))['order']['description'] === 'Custom');
});

it('reuses a live attempt rather than starting another', function () {
    $fake = MibGlobalPay::fake();
    $invoice = managedInvoice();

    $first = MibGlobalPay::startCheckout($invoice);
    $second = MibGlobalPay::startCheckout($invoice);

    expect($second->is($first))->toBeTrue()
        ->and($fake->sent(\IbnNajjaar\MIBGlobalPay\Core\Operations\Checkout\InitiateCheckout::class))->toHaveCount(1);
});

it('retires an expired attempt and starts the next one', function () {
    MibGlobalPay::fake();
    $invoice = managedInvoice();
    $first = MibGlobalPay::startCheckout($invoice);
    $first->update(['expires_at' => new DateTimeImmutable('-1 hour')]);

    $second = MibGlobalPay::startCheckout($invoice);

    expect($second->attemptNumber())->toBe(2)
        ->and($first->fresh()->state())->toBe(AttemptState::Superseded);
});

it('shows the payment page on a signed URL with no host JavaScript', function () {
    MibGlobalPay::fake()->checkoutSession(sessionId: 'SESSION1');
    $attempt = MibGlobalPay::startCheckout(managedInvoice());

    $this->get($attempt->paymentUrl())->assertOk()->assertSee('Checkout.showPaymentPage()', false)->assertSee('SESSION1', false);
});

it('shows the configured display', function () {
    config()->set('mib-global-pay.checkout.mode', 'modal');
    MibGlobalPay::fake();
    $attempt = MibGlobalPay::startCheckout(managedInvoice());

    $this->get($attempt->paymentUrl())->assertOk()->assertSee('<dialog', false);
});

it('refuses an unsigned payment page', function () {
    MibGlobalPay::fake();
    $attempt = MibGlobalPay::startCheckout(managedInvoice());

    $this->get('/mib/pay/' . $attempt->gatewayOrderId())->assertForbidden();
});

it('records a paid return, settles the payable and shows the result', function () {
    $fake = MibGlobalPay::fake();
    $invoice = managedInvoice();
    $attempt = MibGlobalPay::startCheckout($invoice);
    $fake->order($attempt->gatewayOrderId(), OrderFactory::captured(managedMvr()));
    $seen = [];
    Event::listen(PaymentCaptured::class, function (PaymentCaptured $e) use (&$seen): void {
        $seen[] = $e->deliveryKey;
    });

    $this->get('/mib/return/' . $attempt->gatewayOrderId() . '?resultIndicator=x')->assertOk()->assertSee('successful');

    expect($seen)->toHaveCount(1)
        ->and($attempt->fresh()->state())->toBe(AttemptState::Captured)
        ->and($invoice->fresh()->payableState())->toBe(PayableState::Paid)
        ->and($invoice->fresh()->settledBy()?->transactionId)->toBe('1')
        ->and($invoice->fresh()->settledBy()?->amount->equals(managedMvr()))->toBeTrue();
});

it('redirects to the completed URL when one is set', function () {
    config()->set('mib-global-pay.checkout.completed_url', 'https://shop.example/done?x=1');
    $fake = MibGlobalPay::fake();
    $attempt = MibGlobalPay::startCheckout(managedInvoice());
    $fake->order($attempt->gatewayOrderId(), OrderFactory::captured(managedMvr()));

    $this->get('/mib/return/' . $attempt->gatewayOrderId())
        ->assertRedirect('https://shop.example/done?x=1&order=' . $attempt->gatewayOrderId() . '&status=captured');
});

it('says the payment is being confirmed when the gateway cannot be reached', function () {
    $fake = MibGlobalPay::fake();
    $attempt = MibGlobalPay::startCheckout(managedInvoice());
    $fake->respondWith(\IbnNajjaar\MIBGlobalPay\Core\Operations\Order\RetrieveOrder::class, new \IbnNajjaar\MIBGlobalPay\Core\Exceptions\TransportException('down'));

    $this->get('/mib/return/' . $attempt->gatewayOrderId())->assertOk()->assertSee('still confirming');
});

it('answers 404 for an order it does not know', function () {
    MibGlobalPay::fake();

    $this->get('/mib/return/NOPE')->assertNotFound();
});

it('follows authorize, partial capture and final capture', function () {
    $fake = MibGlobalPay::fake();
    $invoice = managedInvoice();
    $attempt = MibGlobalPay::startCheckout($invoice, InteractionOperation::Authorize);
    $orderId = $attempt->gatewayOrderId();
    $events = [];
    foreach ([PaymentAuthorized::class, PaymentPartiallyCaptured::class, PaymentCaptured::class, CaptureMismatch::class] as $class) {
        Event::listen($class, function (object $e) use (&$events): void {
            $events[] = class_basename($e);
        });
    }

    $fake->order($orderId, OrderFactory::authorizedOnly(managedMvr()));
    $this->postMibNotification(NotificationFactory::authorized($orderId, managedMvr()))->assertOk();
    expect($attempt->fresh()->state())->toBe(AttemptState::Authorized)
        ->and($invoice->fresh()->payableState())->toBe(PayableState::Frozen);

    $fake->order($orderId, OrderFactory::partiallyCaptured(managedMvr(), managedMvr('40.00')));
    $this->get('/mib/return/' . $orderId)->assertOk()->assertSee('approved');
    expect($attempt->fresh()->state())->toBe(AttemptState::Authorized);

    $fake->order($orderId, OrderFactory::partiallyCaptured(managedMvr(), managedMvr('100.00')));
    $this->get('/mib/return/' . $orderId)->assertOk()->assertSee('successful');

    expect($events)->toBe(['PaymentAuthorized', 'PaymentPartiallyCaptured', 'PaymentCaptured'])
        ->and($attempt->fresh()->state())->toBe(AttemptState::Captured)
        ->and($invoice->fresh()->payableState())->toBe(PayableState::Paid);
});

it('never fails or replaces an authorization that is holding funds', function () {
    $fake = MibGlobalPay::fake();
    $invoice = managedInvoice();
    $attempt = MibGlobalPay::startCheckout($invoice, InteractionOperation::Authorize);
    $attempt->update(['expires_at' => new DateTimeImmutable('-1 hour')]);
    $fake->order($attempt->gatewayOrderId(), OrderFactory::authorizedOnly(managedMvr()));

    try {
        MibGlobalPay::startCheckout($invoice);
        $this->fail('Expected CheckoutNotAllowed');
    } catch (CheckoutNotAllowed $e) {
        expect($e->result->reason)->toBe(GuardRefusal::AuthorizationHeld);
    }

    expect($attempt->fresh()->state())->toBe(AttemptState::Authorized);
});

it('keeps a capture the guard finds, even though it refuses the new checkout', function () {
    $fake = MibGlobalPay::fake();
    $invoice = managedInvoice();
    $attempt = MibGlobalPay::startCheckout($invoice);
    $attempt->update(['expires_at' => new DateTimeImmutable('-1 hour')]);
    $fake->order($attempt->gatewayOrderId(), OrderFactory::captured(managedMvr()));
    $fired = 0;
    Event::listen(PaymentCaptured::class, function () use (&$fired): void {
        $fired++;
    });

    expect(fn () => MibGlobalPay::startCheckout($invoice))->toThrow(CheckoutNotAllowed::class);

    expect($fired)->toBe(1)
        ->and($attempt->fresh()->state())->toBe(AttemptState::Captured)
        ->and($invoice->fresh()->payableState())->toBe(PayableState::Paid);
});

it('verifies an account with a zero amount', function () {
    $fake = MibGlobalPay::fake();
    $invoice = managedInvoice('0.00');
    $attempt = MibGlobalPay::startCheckout($invoice, InteractionOperation::Verify);
    $fired = 0;
    Event::listen(AccountVerified::class, function () use (&$fired): void {
        $fired++;
    });
    $fake->order($attempt->gatewayOrderId(), OrderFactory::verified(managedMvr('0.00')));

    $this->postMibNotification(NotificationFactory::verified($attempt->gatewayOrderId(), managedMvr('0.00')))->assertOk();

    expect($fired)->toBe(1)->and($attempt->fresh()->state())->toBe(AttemptState::Verified);
});

it('creates a payment link', function () {
    $fake = MibGlobalPay::fake();

    $attempt = MibGlobalPay::startPaymentLink(managedInvoice(), new DateTimeImmutable('+7 days'), 3);

    expect($attempt->paymentUrl())->toContain('/pbl/PAYLINK')
        ->and($attempt->state())->toBe(AttemptState::Live)
        ->and($attempt->expiresAt() > new DateTimeImmutable('+7 days'))->toBeTrue();
    $fake->assertSent(InitiatePaymentLink::class);
    $body = $fake->sent(InitiatePaymentLink::class)[0]->body(app(\IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig::class));
    expect($body['paymentLink']['errorUrl'])->toContain('/mib/link-error')
        ->and($body['paymentLink']['numberOfAllowedAttempts'])->toBe(3);
});

it('sends 25 attempts on a payment link started without a count', function () {
    $fake = MibGlobalPay::fake();

    MibGlobalPay::startPaymentLink(managedInvoice(), new DateTimeImmutable('+7 days'));

    $body = $fake->sent(InitiatePaymentLink::class)[0]->body(app(\IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig::class));
    expect($body['paymentLink']['numberOfAllowedAttempts'])->toBe(25);
});

it('explains a payment link error using known codes only', function (string $code, string $text) {
    $this->get('/mib/link-error?errorCode=' . $code . '&errorDescription=%3Cscript%3E')
        ->assertOk()->assertSee($text)->assertDontSee('<script>', false);
})->with([
    ['EXPIRED', 'expired'],
    ['PAID', 'already been paid'],
    ['USAGE_COUNT_EXCEEDED', 'too many times'],
    ['DOES_NOT_EXIST', 'no longer valid'],
    ['SOMETHING_NEW', 'could not be opened'],
]);

it('reconciles attempts from the package table', function () {
    $fake = MibGlobalPay::fake();
    $attempt = MibGlobalPay::startCheckout(managedInvoice());
    $fake->order($attempt->gatewayOrderId(), OrderFactory::captured(managedMvr()));

    Artisan::call('mib:reconcile');

    expect($attempt->fresh()->state())->toBe(AttemptState::Captured);
});

it('marks a reversed payable as cancelled', function () {
    $fake = MibGlobalPay::fake();
    $invoice = managedInvoice();
    $attempt = MibGlobalPay::startCheckout($invoice);
    $fake->order($attempt->gatewayOrderId(), OrderFactory::captured(managedMvr()), OrderFactory::refunded(managedMvr()));

    $this->get('/mib/return/' . $attempt->gatewayOrderId());
    $this->get('/mib/return/' . $attempt->gatewayOrderId());

    expect($invoice->fresh()->payableState())->toBe(PayableState::Cancelled)
        ->and($invoice->fresh()->settledBy())->toBeNull();
});

it('leaves attempts stored elsewhere alone when managed attempts are off', function () {
    $this->bootWith(['mib-global-pay.checkout.managed_attempts' => false]);

    expect(app()->bound(PayableResolver::class))->toBeFalse();
});

it('keeps refusing a new attempt while a payment is timed out, even after it expires', function () {
    $fake = MibGlobalPay::fake();
    $invoice = managedInvoice();
    $attempt = MibGlobalPay::startCheckout($invoice);
    $attempt->update(['expires_at' => new DateTimeImmutable('-1 hour')]);
    $fake->order($attempt->gatewayOrderId(), OrderFactory::timedOut(managedMvr()));

    try {
        MibGlobalPay::startCheckout($invoice);
        $this->fail('Expected CheckoutNotAllowed');
    } catch (CheckoutNotAllowed $e) {
        expect($e->result->reason)->toBe(GuardRefusal::VerificationPending);
    }

    expect($attempt->fresh()->state())->toBe(AttemptState::Live);
    $this->get('/mib/return/' . $attempt->gatewayOrderId())->assertOk()->assertSee('still confirming');
});

it('asks for a review once after the review period, instead of reporting pending', function () {
    $fake = MibGlobalPay::fake();
    $attempt = MibGlobalPay::startCheckout(managedInvoice());
    $since = new DateTimeImmutable('-25 hours');
    $fake->order($attempt->gatewayOrderId(), OrderFactory::timedOut(managedMvr(), since: $since));
    $reviews = [];
    $pending = 0;
    Event::listen(PaymentNeedsReview::class, function (PaymentNeedsReview $e) use (&$reviews): void {
        $reviews[] = $e;
    });
    Event::listen(PaymentPending::class, function () use (&$pending): void {
        $pending++;
    });

    Artisan::call('mib:reconcile');
    Artisan::call('mib:reconcile');

    expect($reviews)->toHaveCount(1)
        ->and($pending)->toBe(0)
        ->and($reviews[0]->pendingSince->format('Y-m-d H:i'))->toBe($since->format('Y-m-d H:i'))
        ->and($reviews[0]->deliveryKey)->toHaveLength(64);
});

it('reports pending, not a review, before the review period ends', function () {
    config()->set('mib-global-pay.attempts.review_after_seconds', 7200);
    $fake = MibGlobalPay::fake();
    $attempt = MibGlobalPay::startCheckout(managedInvoice());
    $fake->order($attempt->gatewayOrderId(), OrderFactory::timedOut(managedMvr(), since: new DateTimeImmutable('-1 hour')));
    Event::fake([PaymentPending::class, PaymentNeedsReview::class]);

    Artisan::call('mib:reconcile');

    Event::assertDispatched(PaymentPending::class);
    Event::assertNotDispatched(PaymentNeedsReview::class);
});

it('honours a shorter review period from the config', function () {
    config()->set('mib-global-pay.attempts.review_after_seconds', 1800);
    $fake = MibGlobalPay::fake();
    $attempt = MibGlobalPay::startCheckout(managedInvoice());
    $fake->order($attempt->gatewayOrderId(), OrderFactory::pending(managedMvr(), since: new DateTimeImmutable('-1 hour')));
    Event::fake([PaymentNeedsReview::class]);

    Artisan::call('mib:reconcile');

    Event::assertDispatched(PaymentNeedsReview::class);
});

it('unblocks a new attempt once a person confirms the old one unpaid', function () {
    $fake = MibGlobalPay::fake();
    $invoice = managedInvoice();
    $first = MibGlobalPay::startCheckout($invoice);
    $fake->order($first->gatewayOrderId(), OrderFactory::timedOut(managedMvr(), since: new DateTimeImmutable('-25 hours')));

    MibGlobalPay::confirmUnpaid($first, 'finance@example.mv', 'Acquirer says no charge');

    $second = MibGlobalPay::startCheckout($invoice);
    $fresh = $first->fresh();

    expect($fresh->state())->toBe(AttemptState::Failed)
        ->and($fresh->confirmed_unpaid_by)->toBe('finance@example.mv')
        ->and($fresh->confirmed_unpaid_reason)->toBe('Acquirer says no charge')
        ->and($fresh->confirmed_unpaid_at)->not->toBeNull()
        ->and($second->attemptNumber())->toBe(2);
});

it('refuses to confirm an attempt unpaid when the gateway reports a result', function (Closure $order) {
    $fake = MibGlobalPay::fake();
    $attempt = MibGlobalPay::startCheckout(managedInvoice());
    $fake->order($attempt->gatewayOrderId(), $order());

    expect(fn () => MibGlobalPay::confirmUnpaid($attempt, 'finance@example.mv'))->toThrow(AttemptNotUnpaid::class);

    expect($attempt->fresh()->state())->toBe(AttemptState::Live)
        ->and($attempt->fresh()->confirmed_unpaid_at)->toBeNull();
})->with([
    'captured' => [fn () => OrderFactory::captured(managedMvr())],
    'authorized' => [fn () => OrderFactory::authorizedOnly(managedMvr())],
    'partly captured' => [fn () => OrderFactory::partiallyCaptured(managedMvr(), managedMvr('10.00'))],
    'refunded' => [fn () => OrderFactory::refunded(managedMvr())],
]);

it('keeps watching retired attempts without repeating failure events', function () {
    $fake = MibGlobalPay::fake();
    $invoice = managedInvoice();
    $first = MibGlobalPay::startCheckout($invoice);
    $first->update(['expires_at' => new DateTimeImmutable('-1 hour')]);
    $second = MibGlobalPay::startCheckout($invoice);
    $fake->order($second->gatewayOrderId(), OrderFactory::pending(managedMvr()));
    $failed = 0;
    Event::listen(PaymentFailed::class, function () use (&$failed): void {
        $failed++;
    });

    Artisan::call('mib:reconcile');
    Artisan::call('mib:reconcile');

    $fake->assertOrderRetrieved($first->gatewayOrderId());
    expect($failed)->toBe(0)->and($first->fresh()->state())->toBe(AttemptState::Superseded);
});

it('stops watching retired attempts after the watch period', function () {
    config()->set('mib-global-pay.attempts.late_capture_watch_seconds', 3600);
    MibGlobalPay::fake();
    $invoice = managedInvoice();
    $old = MibGlobalPay::startCheckout($invoice);
    $old->update(['state' => AttemptState::Superseded]);
    CheckoutAttempt::query()->whereKey($old->getKey())->toBase()->update(['updated_at' => new DateTimeImmutable('-2 hours')]);

    expect(iterator_to_array(app(PayableResolver::class)->awaitingGateway(), false))->toBeEmpty();
});

it('does not watch a declined attempt nobody confirmed', function () {
    MibGlobalPay::fake();
    $attempt = MibGlobalPay::startCheckout(managedInvoice());
    $attempt->update(['state' => AttemptState::Failed]);

    expect(iterator_to_array(app(PayableResolver::class)->awaitingGateway(), false))->toBeEmpty();
});

it('settles from a late charge when nothing else was paid', function () {
    $fake = MibGlobalPay::fake();
    $invoice = managedInvoice();
    $first = MibGlobalPay::startCheckout($invoice);
    $fake->order($first->gatewayOrderId(), OrderFactory::timedOut(managedMvr()));
    MibGlobalPay::confirmUnpaid($first, 'finance@example.mv');
    $fake->order($first->gatewayOrderId(), OrderFactory::captured(managedMvr()));

    Artisan::call('mib:reconcile');

    expect($first->fresh()->state())->toBe(AttemptState::Captured)
        ->and($invoice->fresh()->payableState())->toBe(PayableState::Paid);
});

it('reverses a late charge automatically when a newer attempt was already paid', function () {
    config()->set('mib-global-pay.double_capture.auto_reverse', true);
    Bus::fake([ReverseCapture::class]);
    $fake = MibGlobalPay::fake();
    $invoice = managedInvoice();
    $first = MibGlobalPay::startCheckout($invoice);
    $fake->order($first->gatewayOrderId(), OrderFactory::timedOut(managedMvr()));
    MibGlobalPay::confirmUnpaid($first, 'finance@example.mv');

    $second = MibGlobalPay::startCheckout($invoice);
    $fake->order($second->gatewayOrderId(), OrderFactory::captured(managedMvr()));
    $this->get('/mib/return/' . $second->gatewayOrderId())->assertOk();

    $fake->order($first->gatewayOrderId(), OrderFactory::captured(managedMvr()));
    Artisan::call('mib:reconcile');

    Bus::assertDispatched(ReverseCapture::class);
    expect($invoice->fresh()->settledBy()?->gatewayOrderId)->toBe($second->gatewayOrderId());
});
