<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Http\PathScope;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Http\HttpMethod;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Operation;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Requests\SessionUpdate;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\Refund;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\Capture;
use IbnNajjaar\MIBGlobalPay\Core\Requests\CheckoutRequest;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\RetrieveOrder;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Gateway\CheckGateway;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\VoidTransaction;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Session\UpdateSession;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Session\RetrieveSession;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Checkout\InitiateCheckout;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\RetrieveTransaction;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\UpdateAuthorization;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Gateway\PaymentOptionsInquiry;

function opConfig(): GatewayConfig
{
    return GatewayConfig::fromArray(['merchant_id' => 'TEST123', 'api_password' => 'x', 'order_prefix' => 'ISIMS']);
}

function mvr(string $amount): Money
{
    return Money::of($amount, Currency::Mvr);
}

it('describes each 2.0 operation', function (Operation $op, HttpMethod $method, PathScope $scope, string $path, bool $idempotent) {
    expect($op->method())->toBe($method)
        ->and($op->scope())->toBe($scope)
        ->and($op->path())->toBe($path)
        ->and($op->isIdempotent())->toBe($idempotent);
})->with([
    'initiate checkout' => [new InitiateCheckout(CheckoutRequest::create('O-1', mvr('1'), 'M', 'https://x.mv')->withoutWebhook()), HttpMethod::Post, PathScope::Merchant, 'session', false],
    'retrieve session' => [new RetrieveSession('SESSION0001'), HttpMethod::Get, PathScope::Merchant, 'session/SESSION0001', true],
    'update session' => [new UpdateSession('SESSION0001', new SessionUpdate(orderAmount: mvr('5'))), HttpMethod::Put, PathScope::Merchant, 'session/SESSION0001', false],
    'retrieve order' => [new RetrieveOrder('O/1'), HttpMethod::Get, PathScope::Merchant, 'order/O%2F1', true],
    'retrieve transaction' => [new RetrieveTransaction('O-1', 'T 1'), HttpMethod::Get, PathScope::Merchant, 'order/O-1/transaction/T%201', true],
    'capture' => [new Capture('O-1', 'capture-1', mvr('5')), HttpMethod::Put, PathScope::Merchant, 'order/O-1/transaction/capture-1', true],
    'refund' => [new Refund('O-1', 'refund-1', mvr('5')), HttpMethod::Put, PathScope::Merchant, 'order/O-1/transaction/refund-1', true],
    'void' => [new VoidTransaction('O-1', 'void-1', '1'), HttpMethod::Put, PathScope::Merchant, 'order/O-1/transaction/void-1', true],
    'update authorization' => [new UpdateAuthorization('O-1', 'upd-1'), HttpMethod::Put, PathScope::Merchant, 'order/O-1/transaction/upd-1', true],
    'check gateway' => [new CheckGateway(), HttpMethod::Get, PathScope::Gateway, 'information', true],
    'payment options' => [new PaymentOptionsInquiry(), HttpMethod::Post, PathScope::Merchant, 'paymentOptionsInquiry', false],
]);

it('builds the transaction operation bodies', function () {
    $config = opConfig();

    expect((new Capture('O-1', 'c-1', mvr('40'), 'SHIP-1'))->body($config))->toBe([
        'apiOperation' => 'CAPTURE',
        'transaction' => ['amount' => '40.00', 'currency' => 'MVR', 'reference' => 'SHIP-1'],
    ])->and((new Refund('O-1', 'r-1', mvr('25.5'), '1', 'RMA-1'))->body($config))->toBe([
        'apiOperation' => 'REFUND',
        'transaction' => ['amount' => '25.50', 'currency' => 'MVR', 'targetTransactionId' => '1', 'reference' => 'RMA-1'],
    ])->and((new Refund('O-1', 'r-1', mvr('1')))->body($config))->toBe([
        'apiOperation' => 'REFUND',
        'transaction' => ['amount' => '1.00', 'currency' => 'MVR'],
    ])->and((new VoidTransaction('O-1', 'v-1', '1'))->body($config))->toBe([
        'apiOperation' => 'VOID',
        'transaction' => ['targetTransactionId' => '1'],
    ])->and((new UpdateAuthorization('O-1', 'u-1', mvr('120')))->body($config))->toBe([
        'apiOperation' => 'UPDATE_AUTHORIZATION',
        'transaction' => ['amount' => '120.00', 'currency' => 'MVR'],
    ])->and((new UpdateAuthorization('O-1', 'u-1'))->body($config))->toBe([
        'apiOperation' => 'UPDATE_AUTHORIZATION',
    ]);
});

it('has no body for GET operations', function () {
    expect((new RetrieveOrder('O'))->body(opConfig()))->toBeNull()
        ->and((new RetrieveTransaction('O', 'T'))->body(opConfig()))->toBeNull()
        ->and((new RetrieveSession('S'))->body(opConfig()))->toBeNull()
        ->and((new CheckGateway())->body(opConfig()))->toBeNull();
});

it('builds the Update Session and Payment Options bodies', function () {
    expect((new UpdateSession('S', new SessionUpdate(orderAmount: mvr('9.99'), orderDescription: 'Fees')))->body(opConfig()))->toBe([
        'order' => ['amount' => '9.99', 'currency' => 'MVR', 'description' => 'Fees'],
    ])->and((new PaymentOptionsInquiry(Currency::Mvr))->body(opConfig()))->toBe(['order' => ['currency' => 'MVR']])
        ->and((new PaymentOptionsInquiry(amount: mvr('10')))->body(opConfig()))->toBe(['order' => ['currency' => 'MVR', 'amount' => '10.00']])
        ->and((new PaymentOptionsInquiry())->body(opConfig()))->toBe([]);
});

it('rejects invalid operation input', function (Closure $build) {
    $build();
})->with([
    'empty order id' => [fn () => new RetrieveOrder('')],
    'long transaction id' => [fn () => new Capture('O', str_repeat('t', 41), mvr('1'))],
    'empty void target' => [fn () => new VoidTransaction('O', 'v', '')],
    'zero capture' => [fn () => (new Capture('O', 'c', mvr('0')))->body(opConfig())],
    'negative refund' => [fn () => (new Refund('O', 'r', mvr('-5')))->body(opConfig())],
    'currency not allowed' => [fn () => (new Refund('O', 'r', Money::of('5', Currency::Usd)))->body(opConfig())],
    'long reference' => [fn () => new Capture('O', 'c', mvr('1'), str_repeat('r', 41))],
    'empty session update' => [fn () => (new UpdateSession('S', new SessionUpdate()))->body(opConfig())],
    'long session id' => [fn () => new RetrieveSession(str_repeat('s', 36))],
])->throws(InvalidRequest::class);
