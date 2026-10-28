<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Responses\OrderResponse;
use IbnNajjaar\MIBGlobalPay\Core\Verification\ApprovalPolicy;
use IbnNajjaar\MIBGlobalPay\Core\Verification\CaptureVerifier;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationStatus;

function txn(string $id, string $type, string $result = 'SUCCESS', ?string $code = 'APPROVED', ?float $amount = 100.0, ?string $target = null): array
{
    return array_filter([
        'result' => $result,
        'response' => $code === null ? null : ['gatewayCode' => $code],
        'transaction' => array_filter([
            'id' => $id, 'type' => $type, 'amount' => $amount, 'currency' => $amount === null ? null : 'MVR', 'targetTransactionId' => $target,
        ], fn ($v) => $v !== null),
    ], fn ($v) => $v !== null);
}

function order(array $transactions, array $totals = ['totalCapturedAmount' => 100.0], string $currency = 'MVR'): OrderResponse
{
    return OrderResponse::fromPayload(Payload::fromArray(['id' => 'ORDER-1', 'currency' => $currency, 'amount' => 100.0] + $totals + ['transaction' => $transactions]));
}

function verify(OrderResponse $order, string $expected = '100.00', Currency $currency = Currency::Mvr, ?ApprovalPolicy $policy = null): VerificationStatus
{
    return (new CaptureVerifier($policy ?? new ApprovalPolicy()))->verify($order, Money::of($expected, $currency))->status;
}

it('is Captured when an approved payment exists and the captured total matches exactly', function () {
    $order = order([txn('auth', 'AUTHENTICATION', code: null), txn('1', 'PAYMENT')]);
    $result = (new CaptureVerifier())->verify($order, Money::of('100', Currency::Mvr));

    expect($result->status)->toBe(VerificationStatus::Captured)
        ->and($result->isCapturedExactly())->toBeTrue()
        ->and($result->isMoneyCaptured())->toBeTrue()
        ->and($result->transactionId())->toBe('1')
        ->and($result->captured?->toDecimal())->toBe('100.00')
        ->and($result->order)->toBe($order)
        ->and($result->orderId)->toBe('ORDER-1');
});

it('accepts an approved capture after an authorization', function () {
    expect(verify(order([txn('a', 'AUTHORIZATION'), txn('c', 'CAPTURE')])))->toBe(VerificationStatus::Captured);
});

it('accepts APPROVED_PENDING_SETTLEMENT', function () {
    expect(verify(order([txn('1', 'PAYMENT', code: 'APPROVED_PENDING_SETTLEMENT')])))->toBe(VerificationStatus::Captured);
});

it('reports AmountMismatch when the captured total differs', function (string $expected, array $totals) {
    expect(verify(order([txn('1', 'PAYMENT')], $totals), $expected))->toBe(VerificationStatus::AmountMismatch);
})->with([
    'under-captured' => ['100.00', ['totalCapturedAmount' => 60.0]],
    'over-captured' => ['100.00', ['totalCapturedAmount' => 150.0]],
    'payable changed after session' => ['0.01', ['totalCapturedAmount' => 100.0]],
]);

it('treats a missing totalCapturedAmount as a mismatch, never a match', function () {
    $order = order([txn('1', 'PAYMENT')], totals: []);

    expect($order->isTotalCapturedReported())->toBeFalse()
        ->and(verify($order))->toBe(VerificationStatus::AmountMismatch)
        ->and(verify($order, '0.01'))->toBe(VerificationStatus::AmountMismatch);
});

it('reports CurrencyMismatch when the order currency differs', function () {
    $order = OrderResponse::fromPayload(Payload::fromArray([
        'id' => 'O', 'currency' => 'USD', 'totalCapturedAmount' => 100.0,
        'transaction' => [['result' => 'SUCCESS', 'response' => ['gatewayCode' => 'APPROVED'], 'transaction' => ['id' => '1', 'type' => 'PAYMENT', 'amount' => 100.0, 'currency' => 'USD']]],
    ]));

    expect(verify($order))->toBe(VerificationStatus::CurrencyMismatch);
});

it('reports NotCaptured without an approved payment or capture', function (array $transactions) {
    expect(verify(order($transactions, ['totalCapturedAmount' => 0])))->toBe(VerificationStatus::NotCaptured);
})->with([
    'no transactions' => [[]],
    'declined payment' => [[txn('1', 'PAYMENT', 'FAILURE', 'DECLINED')]],
    'authorization only' => [[txn('a', 'AUTHORIZATION')]],
    'authentication only' => [[txn('auth', 'AUTHENTICATION', code: null)]],
    'partially approved' => [[txn('1', 'PAYMENT', 'SUCCESS', 'PARTIALLY_APPROVED')]],
    'unrecognized code' => [[txn('1', 'PAYMENT', 'SUCCESS', 'APPROVED_SOMETHING_NEW')]],
]);

it('reports NotCaptured even if a total is reported without an approved transaction', function () {
    expect(verify(order([txn('1', 'PAYMENT', 'FAILURE', 'DECLINED')], ['totalCapturedAmount' => 100.0])))->toBe(VerificationStatus::NotCaptured);
});

it('treats APPROVED_AUTO as captured only when opted in', function () {
    $order = order([txn('1', 'PAYMENT', code: 'APPROVED_AUTO')]);

    expect(verify($order))->toBe(VerificationStatus::NotCaptured)
        ->and(verify($order, policy: new ApprovalPolicy(acceptApprovedAuto: true)))->toBe(VerificationStatus::Captured);
});

it('reports Pending when the latest transaction is pending or unknown', function (array $transaction) {
    expect(verify(order([txn('auth', 'AUTHENTICATION', code: null), $transaction], ['totalCapturedAmount' => 0])))->toBe(VerificationStatus::Pending);
})->with([
    'result pending' => [txn('1', 'PAYMENT', 'PENDING', 'PENDING')],
    'result unknown' => [txn('1', 'PAYMENT', 'UNKNOWN', 'UNKNOWN')],
    'submitted' => [txn('1', 'PAYMENT', 'SUCCESS', 'SUBMITTED')],
    'timed out' => [txn('1', 'PAYMENT', 'FAILURE', 'TIMED_OUT')],
    'deferred' => [txn('1', 'PAYMENT', 'PENDING', 'DEFERRED_TRANSACTION_RECEIVED')],
]);

it('does not report Pending when a later transaction has a final status', function () {
    expect(verify(order([txn('1', 'PAYMENT', 'PENDING', 'PENDING'), txn('2', 'PAYMENT', 'FAILURE', 'DECLINED')], ['totalCapturedAmount' => 0])))
        ->toBe(VerificationStatus::NotCaptured);
});

it('reports Reversed after an approved void of the payment', function () {
    $order = order([txn('1', 'PAYMENT'), txn('v1', 'VOID_PAYMENT', amount: null, target: '1')], ['totalCapturedAmount' => 100.0]);

    expect(verify($order))->toBe(VerificationStatus::Reversed);
});

it('ignores a declined void', function () {
    $order = order([txn('1', 'PAYMENT'), txn('v1', 'VOID_PAYMENT', 'FAILURE', 'DECLINED', null, '1')]);

    expect(verify($order))->toBe(VerificationStatus::Captured);
});

it('reports Reversed after a full refund', function () {
    $order = order([txn('1', 'PAYMENT'), txn('r1', 'REFUND', target: '1')], ['totalCapturedAmount' => 100.0, 'totalRefundedAmount' => 100.0]);

    expect(verify($order))->toBe(VerificationStatus::Reversed);
});

it('stays Captured after a partial refund, with the refunded total exposed', function () {
    $order = order([txn('1', 'PAYMENT'), txn('r1', 'REFUND', amount: 30.0)], ['totalCapturedAmount' => 100.0, 'totalRefundedAmount' => 30.0]);
    $result = (new CaptureVerifier())->verify($order, Money::of('100', Currency::Mvr));

    expect($result->status)->toBe(VerificationStatus::Captured)
        ->and($result->refunded?->toDecimal())->toBe('30.00');
});

it('builds a NotFound result without an order', function () {
    $result = (new CaptureVerifier())->notFound('MISSING', Money::of('1', Currency::Mvr));

    expect($result->status)->toBe(VerificationStatus::NotFound)
        ->and($result->order)->toBeNull()
        ->and($result->captured)->toBeNull()
        ->and($result->isMoneyCaptured())->toBeFalse();
});
