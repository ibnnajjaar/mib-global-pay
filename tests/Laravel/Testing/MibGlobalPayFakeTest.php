<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Enums\GatewayCode;
use IbnNajjaar\MIBGlobalPay\Core\Enums\OrderStatus;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\Refund;
use IbnNajjaar\MIBGlobalPay\Laravel\Facades\MibGlobalPay;
use IbnNajjaar\MIBGlobalPay\Laravel\Testing\OrderFactory;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\Capture;
use IbnNajjaar\MIBGlobalPay\Core\Requests\CheckoutRequest;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Laravel\Testing\MibGlobalPayFake;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\TransportException;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationStatus;

function mvrAmount(string $amount = '1250.50'): Money
{
    return Money::of($amount, Currency::Mvr);
}

function fakeCheckout(string $orderId = 'ISIMS-R-1-1'): CheckoutRequest
{
    return CheckoutRequest::create($orderId, mvrAmount(), 'IUM', 'https://students.ium.test/return')->withoutWebhook();
}

it('replaces the gateway in the container and behind the facade', function () {
    $fake = MibGlobalPay::fake();

    expect($fake)->toBeInstanceOf(MibGlobalPayFake::class)
        ->and(app(Gateway::class))->toBe($fake)
        ->and(MibGlobalPay::gateway())->toBe($fake);
});

it('returns gateway-like defaults for unstubbed calls', function () {
    $fake = MibGlobalPay::fake();

    $session = MibGlobalPay::initiateCheckout(fakeCheckout());
    $details = $fake->retrieveSession($session->sessionId);

    expect($session->sessionId)->toStartWith('SESSION')
        ->and(strlen($session->successIndicator))->toBe(16)
        ->and($details->orderId)->toBe('ISIMS-R-1-1')
        ->and($details->orderAmount?->toDecimal())->toBe('1250.50')
        ->and($fake->verifyCapture('UNKNOWN', mvrAmount())->status)->toBe(VerificationStatus::NotFound)
        ->and($fake->capture('O-1', 'capture-1', mvrAmount())->isSuccessful())->toBeTrue()
        ->and($fake->refund('O-1', 'refund-1', mvrAmount('10'))->amount?->toDecimal())->toBe('10.00')
        ->and($fake->void('O-1', 'void-1', '1')->gatewayCode)->toBe(GatewayCode::Approved)
        ->and($fake->updateAuthorization('O-1', 'upd-1')->isSuccessful())->toBeTrue()
        ->and($fake->checkGateway()->isOperating())->toBeTrue()
        ->and($fake->paymentOptionsInquiry()->cardCurrencies)->toBe(['MVR']);

    expect(fn () => $fake->retrieveOrder('UNKNOWN'))->toThrow(GatewayException::class);
});

it('uses checkout session stubs per order or for any order', function () {
    $fake = MibGlobalPay::fake()
        ->checkoutSession('ISIMS-R-1-1', sessionId: 'SESSION0001', successIndicator: 'abc')
        ->checkoutSession(sessionId: 'SESSION-ANY', successIndicator: 'any');

    expect($fake->initiateCheckout(fakeCheckout())->sessionId)->toBe('SESSION0001')
        ->and($fake->initiateCheckout(fakeCheckout())->successIndicator)->toBe('abc')
        ->and($fake->initiateCheckout(fakeCheckout('OTHER-1'))->sessionId)->toBe('SESSION-ANY');
});

it('returns order stubs in sequence, then repeats the last', function () {
    $fake = MibGlobalPay::fake()->order('ISIMS-R-1-1', OrderFactory::pending(mvrAmount()), OrderFactory::captured(mvrAmount()));

    expect($fake->verifyCapture('ISIMS-R-1-1', mvrAmount())->status)->toBe(VerificationStatus::Pending)
        ->and($fake->verifyCapture('ISIMS-R-1-1', mvrAmount())->status)->toBe(VerificationStatus::Captured)
        ->and($fake->verifyCapture('ISIMS-R-1-1', mvrAmount())->status)->toBe(VerificationStatus::Captured)
        ->and($fake->retrieveOrder('ISIMS-R-1-1')->id)->toBe('ISIMS-R-1-1');
});

it('builds realistic orders with the factories', function (array $order, VerificationStatus $status) {
    $fake = MibGlobalPay::fake()->order('O-1', $order);

    expect($fake->verifyCapture('O-1', mvrAmount())->status)->toBe($status);
})->with([
    'captured' => [OrderFactory::captured(mvrAmount()), VerificationStatus::Captured],
    'authorized only' => [OrderFactory::authorizedOnly(mvrAmount()), VerificationStatus::NotCaptured],
    'declined' => [OrderFactory::declined(mvrAmount()), VerificationStatus::NotCaptured],
    'pending' => [OrderFactory::pending(mvrAmount()), VerificationStatus::Pending],
    'partially refunded' => [OrderFactory::partiallyRefunded(mvrAmount(), mvrAmount('250')), VerificationStatus::Captured],
    'refunded' => [OrderFactory::refunded(mvrAmount()), VerificationStatus::Reversed],
    'voided' => [OrderFactory::voided(mvrAmount()), VerificationStatus::Reversed],
    'without captured total' => [OrderFactory::withoutCapturedTotal(mvrAmount()), VerificationStatus::AmountMismatch],
    'different amount' => [OrderFactory::captured(mvrAmount('1000')), VerificationStatus::AmountMismatch],
]);

it('retrieves transactions from the stubbed order', function () {
    $fake = MibGlobalPay::fake()->order('O-1', OrderFactory::partiallyRefunded(mvrAmount(), mvrAmount('250')));

    expect($fake->retrieveTransaction('O-1', 'refund-1')->amount?->toDecimal())->toBe('250.00')
        ->and($fake->retrieveOrder('O-1')->status)->toBe(OrderStatus::PartiallyRefunded);

    $fake->retrieveTransaction('O-1', 'missing');
})->throws(GatewayException::class);

it('lets any operation be stubbed with payloads, exceptions or closures', function () {
    $fake = MibGlobalPay::fake()
        ->respondWith(Capture::class, new TransportException('timeout'), ['result' => 'FAILURE', 'response' => ['gatewayCode' => 'DECLINED']])
        ->respondWith(Refund::class, fn (Refund $op) => OrderFactory::transaction($op->transactionId, 'REFUND', $op->amount));

    expect(fn () => $fake->capture('O-1', 'c-1', mvrAmount()))->toThrow(TransportException::class)
        ->and($fake->capture('O-1', 'c-1', mvrAmount())->isFailure())->toBeTrue()
        ->and($fake->capture('O-1', 'c-1', mvrAmount())->isFailure())->toBeTrue()
        ->and($fake->refund('O-1', 'r-9', mvrAmount('5'))->transactionId)->toBe('r-9');
});

it('still validates requests against the configuration', function () {
    $fake = MibGlobalPay::fake();

    expect(fn () => $fake->capture('O-1', 'c-1', Money::of('1', Currency::Usd)))->toThrow(InvalidRequest::class, 'allow-list')
        ->and(fn () => $fake->initiateCheckout(CheckoutRequest::create('O', mvrAmount(), 'M', 'https://x.test')))->toThrow(InvalidRequest::class, 'withoutWebhook')
        ->and($fake->sent())->toBe([]);
});

it('throws a clear LogicException for unstubbed calls when strict', function (Closure $call, string $message) {
    $fake = MibGlobalPay::fake()->strict();

    expect(fn () => $call($fake))->toThrow(LogicException::class, $message);
})->with([
    'checkout' => [fn (MibGlobalPayFake $f) => $f->initiateCheckout(fakeCheckout()), 'InitiateCheckout'],
    'order' => [fn (MibGlobalPayFake $f) => $f->verifyCapture('O-9', mvrAmount()), 'order O-9'],
    'capture' => [fn (MibGlobalPayFake $f) => $f->capture('O-1', 'c', mvrAmount()), 'Capture'],
    'check gateway' => [fn (MibGlobalPayFake $f) => $f->checkGateway(), 'CheckGateway'],
]);

it('serves stubs when strict', function () {
    $fake = MibGlobalPay::fake()->strict()->order('O-1', OrderFactory::captured(mvrAmount()))->checkoutSession();

    expect($fake->verifyCapture('O-1', mvrAmount())->isCapturedExactly())->toBeTrue()
        ->and($fake->initiateCheckout(fakeCheckout())->sessionId)->toStartWith('SESSION');
});

it('records operations and offers assertions', function () {
    $fake = MibGlobalPay::fake();
    $fake->assertNothingSent();
    $fake->assertCheckoutNotInitiated();

    $fake->initiateCheckout(fakeCheckout()->withReference('(R)IUM/FN/2026/123'));
    $fake->verifyCapture('ISIMS-R-1-1', mvrAmount());
    $fake->capture('O-1', 'capture-1', mvrAmount('40'));
    $fake->refund('O-1', 'refund-1', mvrAmount('10'));
    $fake->void('O-1', 'void-1', '1');

    $fake->assertCheckoutInitiated();
    $fake->assertCheckoutInitiated(fn (CheckoutRequest $r) => $r->reference() === '(R)IUM/FN/2026/123');
    $fake->assertOrderRetrieved('ISIMS-R-1-1');
    $fake->assertCaptured(fn (Capture $c) => $c->amount->toDecimal() === '40.00');
    $fake->assertRefunded(fn (Refund $r) => $r->transactionId === 'refund-1');
    $fake->assertVoided();
    $fake->assertSent(Capture::class);
    MibGlobalPay::assertCheckoutInitiated();

    expect($fake->sent(Capture::class))->toHaveCount(1)
        ->and($fake->sent())->toHaveCount(5);
});

it('fails assertions that do not hold', function (Closure $assert) {
    $fake = MibGlobalPay::fake();
    $fake->capture('O-1', 'capture-1', mvrAmount());

    $assert($fake);
})->with([
    'nothing sent' => [fn (MibGlobalPayFake $f) => $f->assertNothingSent()],
    'checkout initiated' => [fn (MibGlobalPayFake $f) => $f->assertCheckoutInitiated()],
    'refunded' => [fn (MibGlobalPayFake $f) => $f->assertRefunded()],
    'captured with callback' => [fn (MibGlobalPayFake $f) => $f->assertCaptured(fn (Capture $c) => $c->amount->toDecimal() === '1.00')],
    'order retrieved' => [fn (MibGlobalPayFake $f) => $f->assertOrderRetrieved('O-1')],
])->throws(AssertionFailedError::class);
