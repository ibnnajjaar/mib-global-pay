<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Reversal\ReversalHelper;
use IbnNajjaar\MIBGlobalPay\Core\Reversal\ReversalMethod;
use IbnNajjaar\MIBGlobalPay\Tests\Support\GatewayHarness;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\GatewayException;

function approvedVoid(): array
{
    return ['result' => 'SUCCESS', 'response' => ['gatewayCode' => 'APPROVED'], 'transaction' => ['id' => 'void-1', 'type' => 'VOID_PAYMENT', 'targetTransactionId' => '1']];
}

function refundResponse(string $result = 'SUCCESS', string $code = 'APPROVED'): array
{
    return ['result' => $result, 'response' => ['gatewayCode' => $code], 'transaction' => ['id' => 'refund-1', 'type' => 'REFUND', 'amount' => 1250.5, 'currency' => 'MVR']];
}

it('voids the approved payment when the gateway accepts the void', function () {
    $h = new GatewayHarness([GatewayHarness::fixture(200, 'order_captured.json'), GatewayHarness::json(200, approvedVoid())]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1', 'DOUBLE-CAPTURE');

    expect($outcome->method)->toBe(ReversalMethod::Void)
        ->and($outcome->reversed)->toBeTrue()
        ->and($h->history)->toHaveCount(2)
        ->and($h->request(1)->getUri()->getPath())->toEndWith('/order/ORDER-1/transaction/void-1')
        ->and($h->requestBody(1))->toBe(['apiOperation' => 'VOID', 'transaction' => ['targetTransactionId' => '1', 'reference' => 'DOUBLE-CAPTURE']]);
});

it('refunds the refundable amount when the void is declined', function () {
    $h = new GatewayHarness([
        GatewayHarness::fixture(200, 'order_captured.json'),
        GatewayHarness::fixture(200, 'transaction_void_declined.json'),
        GatewayHarness::json(200, refundResponse()),
    ]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Refund)
        ->and($outcome->reversed)->toBeTrue()
        ->and($outcome->voidAttempt?->isFailure())->toBeTrue()
        ->and($h->request(2)->getUri()->getPath())->toEndWith('/transaction/refund-1')
        ->and($h->requestBody(2))->toBe(['apiOperation' => 'REFUND', 'transaction' => ['amount' => '1250.50', 'currency' => 'MVR']]);
});

it('refunds when the void is rejected with HTTP 4xx', function () {
    $h = new GatewayHarness([
        GatewayHarness::fixture(200, 'order_captured.json'),
        GatewayHarness::json(400, ['error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Void not allowed after settlement']]),
        GatewayHarness::json(200, refundResponse()),
    ]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Refund)
        ->and($outcome->voidError?->httpStatus())->toBe(400)
        ->and($outcome->reversed)->toBeTrue();
});

it('reports a declined refund as not reversed', function () {
    $h = new GatewayHarness([
        GatewayHarness::fixture(200, 'order_captured.json'),
        GatewayHarness::fixture(200, 'transaction_void_declined.json'),
        GatewayHarness::json(200, refundResponse('FAILURE', 'DECLINED')),
    ]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Refund)->and($outcome->reversed)->toBeFalse();
});

it('does not fall back to a refund on a 5xx void error', function () {
    $h = new GatewayHarness([GatewayHarness::fixture(200, 'order_captured.json'), GatewayHarness::json(503, '')]);

    expect(fn () => (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1'))->toThrow(GatewayException::class)
        ->and($h->history)->toHaveCount(2);
});

it('does nothing when there is no approved capture or nothing left to refund', function (string $fixture) {
    $h = new GatewayHarness([GatewayHarness::fixture(200, $fixture)]);

    $outcome = (new ReversalHelper($h->gateway))->reverse('ORDER-1', 'void-1', 'refund-1');

    expect($outcome->method)->toBe(ReversalMethod::Nothing)
        ->and($outcome->reversed)->toBeFalse()
        ->and($h->history)->toHaveCount(1);
})->with(['order_without_totals.json']);
