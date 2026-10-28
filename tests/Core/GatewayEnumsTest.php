<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Enums\Result;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Enums\GatewayCode;
use IbnNajjaar\MIBGlobalPay\Core\Enums\GatewayEnum;
use IbnNajjaar\MIBGlobalPay\Core\Enums\OrderStatus;
use IbnNajjaar\MIBGlobalPay\Core\Enums\TransactionType;
use IbnNajjaar\MIBGlobalPay\Core\Enums\GatewayRecommendation;

dataset('gateway enums', [
    OrderStatus::class,
    TransactionType::class,
    Result::class,
    GatewayCode::class,
    GatewayRecommendation::class,
    Currency::class,
]);

it('has an Unrecognized case backed by an empty string', function (string $enum) {
    /** @var class-string<GatewayEnum> $enum */
    expect($enum::from(''))->toBe($enum::fromGateway(null))
        ->and($enum::fromGateway(null)->isRecognized())->toBeFalse()
        ->and($enum::fromGateway(null)->name)->toBe('Unrecognized');
})->with('gateway enums');

it('maps unknown, empty and null values to Unrecognized without throwing', function (string $enum) {
    /** @var class-string<GatewayEnum> $enum */
    foreach (['SOMETHING_NEW', '', '   ', null, 'approved?'] as $value) {
        expect($enum::fromGateway($value)->name)->toBe('Unrecognized');
    }
})->with('gateway enums');

it('round-trips every recognized case and matches case-insensitively', function (string $enum) {
    /** @var class-string<GatewayEnum> $enum */
    foreach ($enum::cases() as $case) {
        if (! $case->isRecognized()) {
            continue;
        }
        expect($enum::fromGateway($case->value))->toBe($case)
            ->and($enum::fromGateway(strtolower($case->value)))->toBe($case)
            ->and($case->value)->toMatch('/^[A-Z0-9_]+$/');
    }
})->with('gateway enums');

it('uses PascalCase case names backed by the gateway string', function () {
    expect(GatewayCode::ApprovedPendingSettlement->value)->toBe('APPROVED_PENDING_SETTLEMENT')
        ->and(GatewayRecommendation::ResubmitWith3ds->value)->toBe('RESUBMIT_WITH_3DS')
        ->and(OrderStatus::PartiallyRefunded->value)->toBe('PARTIALLY_REFUNDED')
        ->and(TransactionType::VoidPayment->value)->toBe('VOID_PAYMENT');
});

it('keeps the gateway UNKNOWN value distinct from Unrecognized', function () {
    expect(Result::fromGateway('UNKNOWN'))->toBe(Result::Unknown)
        ->and(Result::Unknown->isRecognized())->toBeTrue()
        ->and(GatewayCode::fromGateway('UNKNOWN'))->toBe(GatewayCode::Unknown)
        ->and(GatewayCode::fromGateway('UNKNOWN'))->not->toBe(GatewayCode::Unrecognized);
});

it('has the documented number of values', function (string $enum, int $count) {
    /** @var class-string<GatewayEnum> $enum */
    expect(count($enum::cases()) - 1)->toBe($count);
})->with([
    [OrderStatus::class, 19],
    [TransactionType::class, 15],
    [Result::class, 5],
    [GatewayCode::class, 37],
    [GatewayRecommendation::class, 15],
]);
