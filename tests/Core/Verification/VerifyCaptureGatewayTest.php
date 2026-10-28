<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Tests\Support\GatewayHarness;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationStatus;

it('re-reads the order from the gateway and verifies it', function () {
    $h = new GatewayHarness([GatewayHarness::fixture(200, 'order_captured.json')]);

    $result = $h->gateway->verifyCapture('ISIMS-R-IUM-FN-2026-123-1', Money::of('1250.50', Currency::Mvr));

    expect($result->status)->toBe(VerificationStatus::Captured)
        ->and($result->transactionId())->toBe('1')
        ->and($h->request()->getMethod())->toBe('GET')
        ->and($h->request()->getUri()->getPath())->toEndWith('/order/ISIMS-R-IUM-FN-2026-123-1');
});

it('reports a mismatch when the payable amount no longer matches the session', function () {
    $h = new GatewayHarness([GatewayHarness::fixture(200, 'order_captured.json')]);

    expect($h->gateway->verifyCapture('X', Money::of('0.01', Currency::Mvr))->status)->toBe(VerificationStatus::AmountMismatch);
});

it('maps "order not found" responses to NotFound', function (int $status, array $body) {
    $h = new GatewayHarness([GatewayHarness::json($status, $body)]);

    expect($h->gateway->verifyCapture('MISSING', Money::of('1', Currency::Mvr))->status)->toBe(VerificationStatus::NotFound);
})->with([
    '404' => [404, []],
    '400 unable to find' => [400, ['error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Unable to find order MISSING']]],
    '400 not found' => [400, ['error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Order not found']]],
]);

it('rethrows other gateway errors', function () {
    $h = new GatewayHarness([GatewayHarness::fixture(400, 'error_invalid_request.json')]);

    $h->gateway->verifyCapture('X', Money::of('1', Currency::Mvr));
})->throws(GatewayException::class);

it('uses the configured APPROVED_AUTO opt-in', function () {
    $body = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/v2/order_captured.json'), true);
    $body['transaction'][1]['response']['gatewayCode'] = 'APPROVED_AUTO';

    $default = new GatewayHarness([GatewayHarness::json(200, $body)]);
    $optIn = new GatewayHarness([GatewayHarness::json(200, $body)], ['approval' => ['accept_approved_auto' => true]]);

    expect($default->gateway->verifyCapture('X', Money::of('1250.50', Currency::Mvr))->status)->toBe(VerificationStatus::NotCaptured)
        ->and($optIn->gateway->verifyCapture('X', Money::of('1250.50', Currency::Mvr))->status)->toBe(VerificationStatus::Captured);
});
