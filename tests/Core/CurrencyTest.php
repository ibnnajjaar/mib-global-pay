<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\UnsupportedCurrency;

it('has the ISO 4217 minor-unit exponent for every supported currency', function (Currency $currency, int $units) {
    expect($currency->minorUnits())->toBe($units);
})->with([
    [Currency::Aed, 2],
    [Currency::Eur, 2],
    [Currency::Gbp, 2],
    [Currency::Inr, 2],
    [Currency::Jpy, 0],
    [Currency::Kwd, 3],
    [Currency::Lkr, 2],
    [Currency::Mvr, 2],
    [Currency::Sgd, 2],
    [Currency::Usd, 2],
]);

it('covers every case in the dataset above', function () {
    expect(Currency::cases())->toHaveCount(11); // 10 currencies + Unrecognized
});

it('resolves gateway codes case-insensitively and never throws', function () {
    expect(Currency::fromGateway('MVR'))->toBe(Currency::Mvr)
        ->and(Currency::fromGateway(' usd '))->toBe(Currency::Usd)
        ->and(Currency::fromGateway('XYZ'))->toBe(Currency::Unrecognized)
        ->and(Currency::fromGateway(''))->toBe(Currency::Unrecognized)
        ->and(Currency::fromGateway(null))->toBe(Currency::Unrecognized)
        ->and(Currency::fromGateway('XYZ')->isRecognized())->toBeFalse();
});

it('looks up codes strictly with fromCode', function () {
    expect(Currency::fromCode('mvr'))->toBe(Currency::Mvr);

    Currency::fromCode('XYZ');
})->throws(UnsupportedCurrency::class);

it('has no minor units for Unrecognized', function () {
    Currency::Unrecognized->minorUnits();
})->throws(UnsupportedCurrency::class);
