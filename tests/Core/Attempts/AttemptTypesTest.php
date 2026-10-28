<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Enums\OrderStatus;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Settlement;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptState;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableState;
use IbnNajjaar\MIBGlobalPay\Core\Enums\TransactionType;

it('allows only the documented attempt transitions', function () {
    $allowed = [
        'created' => ['live', 'failed', 'superseded'],
        'live' => ['captured', 'expired', 'failed', 'superseded'],
        'expired' => ['captured', 'failed', 'superseded'],
        'superseded' => ['captured'],
        'captured' => [],
        'failed' => [],
    ];

    foreach (AttemptState::cases() as $from) {
        foreach (AttemptState::cases() as $to) {
            expect($from->canTransitionTo($to))->toBe(in_array($to->value, $allowed[$from->value], true), "{$from->value} -> {$to->value}");
        }
    }
});

it('knows which attempts may still be paid', function () {
    $open = array_filter(AttemptState::cases(), fn (AttemptState $s) => $s->mayStillBePaid());

    expect(array_values($open))->toBe([AttemptState::Created, AttemptState::Live, AttemptState::Expired]);
});

it('treats only Open and Frozen payables as payable', function () {
    expect(array_values(array_filter(PayableState::cases(), fn (PayableState $s) => $s->isPayable())))
        ->toBe([PayableState::Open, PayableState::Frozen]);
});

it('compares settlements by order and transaction', function () {
    $s = new Settlement('O-1', '1', Money::of('1', Currency::Mvr), new DateTimeImmutable());

    expect($s->isSameCapture('O-1', '1'))->toBeTrue()
        ->and($s->isSameCapture('O-2', '1'))->toBeFalse()
        ->and($s->isSameCapture('O-1', null))->toBeFalse();
});

it('defines the enum helpers as decided', function () {
    $terminal = array_values(array_filter(OrderStatus::cases(), fn (OrderStatus $s) => $s->isTerminalWithoutPayment()));
    $moneyIn = array_values(array_filter(TransactionType::cases(), fn (TransactionType $t) => $t->movesMoneyIn()));
    $voids = array_values(array_filter(TransactionType::cases(), fn (TransactionType $t) => $t->isVoid()));
    $reversals = array_values(array_filter(TransactionType::cases(), fn (TransactionType $t) => $t->reversesMoneyIn()));

    expect($terminal)->toBe([OrderStatus::AuthenticationUnsuccessful, OrderStatus::Cancelled, OrderStatus::Failed])
        ->and($moneyIn)->toBe([TransactionType::Capture, TransactionType::Payment])
        ->and(TransactionType::Authorization->movesMoneyIn())->toBeFalse()
        ->and($voids)->toBe([TransactionType::VoidAuthorization, TransactionType::VoidCapture, TransactionType::VoidPayment, TransactionType::VoidRefund])
        ->and($reversals)->toBe([TransactionType::VoidCapture, TransactionType::VoidPayment]);
});
