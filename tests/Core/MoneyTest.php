<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidAmount;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\CurrencyMismatch;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\UnsupportedCurrency;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MibGlobalPayException;

describe('Money::of', function () {
    it('parses decimal strings into minor units', function (string $amount, Currency $currency, int $minor, string $decimal) {
        $money = Money::of($amount, $currency);

        expect($money->minor)->toBe($minor)
            ->and($money->currency)->toBe($currency)
            ->and($money->toDecimal())->toBe($decimal);
    })->with([
        'whole' => ['100', Currency::Mvr, 10000, '100.00'],
        'one decimal' => ['100.5', Currency::Mvr, 10050, '100.50'],
        'two decimals' => ['100.50', Currency::Mvr, 10050, '100.50'],
        'trailing zeros beyond precision' => ['100.5000', Currency::Mvr, 10050, '100.50'],
        'zero' => ['0', Currency::Mvr, 0, '0.00'],
        'small' => ['0.01', Currency::Usd, 1, '0.01'],
        'leading zeros' => ['007.10', Currency::Usd, 710, '7.10'],
        'negative' => ['-12.30', Currency::Mvr, -1230, '-12.30'],
        'negative small' => ['-0.05', Currency::Mvr, -5, '-0.05'],
        'zero-decimal currency' => ['1500', Currency::Jpy, 1500, '1500'],
        'three-decimal currency' => ['1.234', Currency::Kwd, 1234, '1.234'],
        'large' => ['92233720368547758.07', Currency::Mvr, PHP_INT_MAX, '92233720368547758.07'],
    ]);

    it('rejects more decimals than the currency allows', function (string $amount, Currency $currency) {
        Money::of($amount, $currency);
    })->with([
        ['100.505', Currency::Mvr],
        ['0.001', Currency::Usd],
        ['1.5', Currency::Jpy],
        ['1.2345', Currency::Kwd],
    ])->throws(InvalidAmount::class, 'Money::ofRounded()');

    it('rejects strings that are not plain decimals', function (string $amount) {
        Money::of($amount, Currency::Mvr);
    })->with(['', ' 1', '1 ', '+1', '1.', '.5', '1e3', '1,000.00', 'abc', '--1', '0x10', 'NaN'])
        ->throws(InvalidAmount::class);

    it('rejects amounts that overflow', function () {
        Money::of('92233720368547758.08', Currency::Mvr);
    })->throws(InvalidAmount::class);

    it('rejects an unrecognized currency', function () {
        Money::of('1.00', Currency::Unrecognized);
    })->throws(UnsupportedCurrency::class);

    it('throws exceptions that implement the marker interface', function () {
        try {
            Money::of('x', Currency::Mvr);
            $this->fail('Expected an exception');
        } catch (InvalidAmount $e) {
            expect($e)->toBeInstanceOf(MibGlobalPayException::class)
                ->toBeInstanceOf(InvalidArgumentException::class);
        }
    });
});

describe('Money::ofRounded', function () {
    it('rounds with the given mode', function (string $amount, RoundingMode $mode, string $expected) {
        expect(Money::ofRounded($amount, Currency::Mvr, $mode)->toDecimal())->toBe($expected);
    })->with([
        ['1.005', RoundingMode::HalfAwayFromZero, '1.01'],
        ['-1.005', RoundingMode::HalfAwayFromZero, '-1.01'],
        ['1.005', RoundingMode::HalfTowardsZero, '1.00'],
        ['1.0051', RoundingMode::HalfTowardsZero, '1.01'],
        ['1.005', RoundingMode::HalfEven, '1.00'],
        ['1.015', RoundingMode::HalfEven, '1.02'],
        ['1.005', RoundingMode::HalfOdd, '1.01'],
        ['1.015', RoundingMode::HalfOdd, '1.01'],
        ['1.004', RoundingMode::HalfAwayFromZero, '1.00'],
        ['1.001', RoundingMode::AwayFromZero, '1.01'],
        ['-1.001', RoundingMode::AwayFromZero, '-1.01'],
        ['1.009', RoundingMode::TowardsZero, '1.00'],
        ['1.001', RoundingMode::PositiveInfinity, '1.01'],
        ['-1.009', RoundingMode::PositiveInfinity, '-1.00'],
        ['1.009', RoundingMode::NegativeInfinity, '1.00'],
        ['-1.001', RoundingMode::NegativeInfinity, '-1.01'],
        ['0.999', RoundingMode::HalfAwayFromZero, '1.00'],
        ['9.995', RoundingMode::HalfAwayFromZero, '10.00'],
        ['2.50', RoundingMode::HalfEven, '2.50'],
    ]);

    it('rounds to the currency precision', function () {
        expect(Money::ofRounded('2.5', Currency::Jpy, RoundingMode::HalfEven)->toDecimal())->toBe('2')
            ->and(Money::ofRounded('3.5', Currency::Jpy, RoundingMode::HalfEven)->toDecimal())->toBe('4')
            ->and(Money::ofRounded('1.23456', Currency::Kwd, RoundingMode::HalfAwayFromZero)->toDecimal())->toBe('1.235');
    });

    it('still rejects malformed strings', function () {
        Money::ofRounded('1e3', Currency::Mvr, RoundingMode::HalfEven);
    })->throws(InvalidAmount::class);
});

describe('Money::fromGateway', function () {
    it('accepts ints, numeric strings and exactly representable floats', function (int|float|string $value, Currency $currency, string $expected) {
        expect(Money::fromGateway($value, $currency)->toDecimal())->toBe($expected);
    })->with([
        'int' => [100, Currency::Mvr, '100.00'],
        'string' => ['100.50', Currency::Mvr, '100.50'],
        'float one decimal' => [100.5, Currency::Mvr, '100.50'],
        'float two decimals' => [0.3, Currency::Mvr, '0.30'],
        'float 1234.56' => [1234.56, Currency::Mvr, '1234.56'],
        'float whole' => [25.0, Currency::Usd, '25.00'],
        'float negative' => [-10.25, Currency::Mvr, '-10.25'],
        'float jpy' => [1500.0, Currency::Jpy, '1500'],
        'float kwd' => [1.234, Currency::Kwd, '1.234'],
        'max gateway length' => [99999999999.99, Currency::Mvr, '99999999999.99'],
    ]);

    it('rejects floats with more decimals than the currency allows', function (float $value, Currency $currency) {
        Money::fromGateway($value, $currency);
    })->with([
        [100.505, Currency::Mvr],
        [0.1 + 0.2, Currency::Mvr],
        [1.5, Currency::Jpy],
    ])->throws(InvalidAmount::class);

    it('rejects non-finite and huge floats', function (float $value) {
        Money::fromGateway($value, Currency::Mvr);
    })->with([INF, -INF, NAN, 1e15])->throws(InvalidAmount::class);
});

describe('arithmetic and comparison', function () {
    it('adds, subtracts and negates', function () {
        $a = Money::of('10.00', Currency::Mvr);
        $b = Money::of('2.50', Currency::Mvr);

        expect($a->plus($b)->toDecimal())->toBe('12.50')
            ->and($b->minus($a)->toDecimal())->toBe('-7.50')
            ->and($b->minus($a)->isNegative())->toBeTrue()
            ->and($b->minus($a)->abs()->toDecimal())->toBe('7.50')
            ->and($a->negate()->toDecimal())->toBe('-10.00')
            ->and($a->minus($a)->isZero())->toBeTrue()
            ->and($a->isPositive())->toBeTrue();
    });

    it('compares amounts of the same currency', function () {
        $a = Money::of('10.00', Currency::Mvr);
        $b = Money::of('10', Currency::Mvr);
        $c = Money::of('9.99', Currency::Mvr);

        expect($a->equals($b))->toBeTrue()
            ->and($a->compare($c))->toBe(1)
            ->and($c->compare($a))->toBe(-1)
            ->and($a->compare($b))->toBe(0)
            ->and($a->greaterThan($c))->toBeTrue()
            ->and($a->greaterThanOrEqual($b))->toBeTrue()
            ->and($c->lessThan($a))->toBeTrue()
            ->and($c->lessThanOrEqual($c))->toBeTrue();
    });

    it('treats different currencies as unequal without throwing', function () {
        expect(Money::of('1.00', Currency::Mvr)->equals(Money::of('1.00', Currency::Usd)))->toBeFalse();
    });

    it('refuses to combine or compare different currencies', function (Closure $operation) {
        $operation(Money::of('1.00', Currency::Mvr), Money::of('1.00', Currency::Usd));
    })->with([
        'plus' => [fn (Money $a, Money $b) => $a->plus($b)],
        'minus' => [fn (Money $a, Money $b) => $a->minus($b)],
        'compare' => [fn (Money $a, Money $b) => $a->compare($b)],
        'greaterThan' => [fn (Money $a, Money $b) => $a->greaterThan($b)],
    ])->throws(CurrencyMismatch::class);

    it('detects overflow', function () {
        Money::ofMinor(PHP_INT_MAX, Currency::Mvr)->plus(Money::ofMinor(1, Currency::Mvr));
    })->throws(InvalidAmount::class);

    it('detects overflow when negating the smallest value', function () {
        Money::ofMinor(PHP_INT_MIN, Currency::Mvr)->negate();
    })->throws(InvalidAmount::class);

    it('formats as a string with the currency code', function () {
        expect((string) Money::ofMinor(5, Currency::Usd))->toBe('0.05 USD');
    });
});
