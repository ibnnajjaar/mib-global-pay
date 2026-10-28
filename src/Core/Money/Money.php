<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Money;

use RoundingMode;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidAmount;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\CurrencyMismatch;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\UnsupportedCurrency;

/**
 * An amount in minor units (e.g. laari for MVR) with its currency.
 *
 * There is no float constructor. Values may be negative (differences, reversals);
 * request builders and operations enforce positive amounts where the gateway needs them.
 */
final readonly class Money
{
    /** Number of digits in PHP_INT_MAX; longer digit strings cannot be represented. */
    private const MAX_DIGITS = 19;

    private function __construct(
        public int $minor,
        public Currency $currency,
    ) {
    }

    /**
     * @throws UnsupportedCurrency
     */
    public static function ofMinor(int $minor, Currency $currency): self
    {
        $currency->minorUnits(); // rejects Unrecognized

        return new self($minor, $currency);
    }

    /**
     * Parse a decimal string such as "100", "100.5" or "-12.30".
     *
     * @throws InvalidAmount when the string is not a plain decimal or has more decimals than the currency allows
     * @throws UnsupportedCurrency
     */
    public static function of(string $amount, Currency $currency): self
    {
        [$negative, $integer, $fraction] = self::parse($amount);
        $units = $currency->minorUnits();

        if (strlen(rtrim($fraction, '0')) > $units) {
            throw new InvalidAmount(sprintf(
                'Amount "%s" has more than %d decimal place(s) for %s; use Money::ofRounded() to round explicitly',
                $amount,
                $units,
                $currency->value,
            ));
        }

        return new self(self::toMinor($negative, $integer, str_pad(substr($fraction, 0, $units), $units, '0'), 0, $amount), $currency);
    }

    /**
     * Parse a decimal string and round it to the currency's minor units with an explicit rounding mode.
     *
     * @throws InvalidAmount
     * @throws UnsupportedCurrency
     */
    public static function ofRounded(string $amount, Currency $currency, RoundingMode $mode): self
    {
        [$negative, $integer, $fraction] = self::parse($amount);
        $units = $currency->minorUnits();

        $kept = str_pad(substr($fraction, 0, $units), $units, '0');
        $rest = rtrim(substr($fraction, $units), '0');

        $lastDigit = (int) substr($integer . $kept, -1);
        $increment = self::roundsUp($mode, $negative, $rest, $lastDigit % 2 === 1) ? 1 : 0;

        return new self(self::toMinor($negative, $integer, $kept, $increment, $amount), $currency);
    }

    /**
     * Build from a decoded gateway JSON value (amounts are in major units).
     * Floats exist only at this decode step: a float is accepted only if it is
     * exactly representable with at most the currency's minor units.
     *
     * @throws InvalidAmount
     * @throws UnsupportedCurrency
     */
    public static function fromGateway(int|float|string $amount, Currency $currency): self
    {
        if (is_int($amount)) {
            return self::of((string) $amount, $currency);
        }

        if (is_string($amount)) {
            return self::of(trim($amount), $currency);
        }

        if (! is_finite($amount) || abs($amount) >= 1e15) {
            throw new InvalidAmount('Amount is not a finite gateway amount');
        }

        $units = $currency->minorUnits();
        for ($decimals = 0; $decimals <= $units; $decimals++) {
            $text = sprintf('%.' . $decimals . 'F', $amount);
            if ((float) $text === $amount) {
                return self::of($text, $currency);
            }
        }

        throw new InvalidAmount(sprintf(
            'Gateway amount %s has more than %d decimal place(s) for %s',
            var_export($amount, true),
            $units,
            $currency->value,
        ));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checked($this->minor + $other->minor), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checked($this->minor - $other->minor), $this->currency);
    }

    public function negate(): self
    {
        return new self(self::checked(-$this->minor), $this->currency);
    }

    public function abs(): self
    {
        return $this->minor < 0 ? $this->negate() : $this;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function isSameCurrency(self $other): bool
    {
        return $this->currency === $other->currency;
    }

    /** Same currency and same amount. Never throws. */
    public function equals(self $other): bool
    {
        return $this->isSameCurrency($other) && $this->minor === $other->minor;
    }

    /**
     * @return -1|0|1
     *
     * @throws CurrencyMismatch
     */
    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minor <=> $other->minor;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compare($other) === 1;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->compare($other) >= 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compare($other) === -1;
    }

    public function lessThanOrEqual(self $other): bool
    {
        return $this->compare($other) <= 0;
    }

    /** The decimal string the gateway expects, e.g. "100.50", "-3.00" or "1500" for JPY. */
    public function toDecimal(): string
    {
        $units = $this->currency->minorUnits();
        $digits = str_pad((string) abs($this->minor), $units + 1, '0', STR_PAD_LEFT);
        $sign = $this->minor < 0 ? '-' : '';

        if ($units === 0) {
            return $sign . $digits;
        }

        return $sign . substr($digits, 0, -$units) . '.' . substr($digits, -$units);
    }

    public function __toString(): string
    {
        return $this->toDecimal() . ' ' . $this->currency->value;
    }

    /**
     * @throws CurrencyMismatch
     */
    private function assertSameCurrency(self $other): void
    {
        if (! $this->isSameCurrency($other)) {
            throw new CurrencyMismatch(sprintf(
                'Cannot combine %s with %s',
                $this->currency->value,
                $other->currency->value,
            ));
        }
    }

    /**
     * @return array{bool, string, string} [negative, integer digits, fraction digits]
     *
     * @throws InvalidAmount
     */
    private static function parse(string $amount): array
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $amount, $m) !== 1) {
            throw new InvalidAmount(sprintf('Amount "%s" is not a plain decimal number', $amount));
        }

        $integer = ltrim($m[2], '0');
        if (strlen($integer) > self::MAX_DIGITS) {
            throw new InvalidAmount(sprintf('Amount "%s" is too large', $amount));
        }

        return [$m[1] === '-', $integer === '' ? '0' : $integer, $m[3] ?? ''];
    }

    /**
     * @throws InvalidAmount
     */
    private static function toMinor(bool $negative, string $integer, string $kept, int $increment, string $original): int
    {
        $digits = ltrim($integer . $kept, '0');
        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new InvalidAmount(sprintf('Amount "%s" is too large', $original));
        }

        $magnitude = self::checked((int) ($digits === '' ? '0' : $digits) + $increment);

        return $negative ? -$magnitude : $magnitude;
    }

    /**
     * Whether the magnitude is rounded up (away from zero) by one minor unit.
     *
     * @param string $rest the discarded digits, trailing zeros removed
     */
    private static function roundsUp(RoundingMode $mode, bool $negative, string $rest, bool $lastDigitOdd): bool
    {
        if ($rest === '') {
            return false;
        }

        // Compare the discarded fraction (e.g. "5", "51", "49") with exactly one half.
        $half = match (true) {
            $rest[0] > '5' => 1,
            $rest[0] < '5' => -1,
            default => strlen($rest) > 1 ? 1 : 0,
        };

        return match ($mode) {
            RoundingMode::TowardsZero => false,
            RoundingMode::AwayFromZero => true,
            RoundingMode::PositiveInfinity => ! $negative,
            RoundingMode::NegativeInfinity => $negative,
            RoundingMode::HalfAwayFromZero => $half >= 0,
            RoundingMode::HalfTowardsZero => $half > 0,
            RoundingMode::HalfEven => $half > 0 || ($half === 0 && $lastDigitOdd),
            RoundingMode::HalfOdd => $half > 0 || ($half === 0 && ! $lastDigitOdd),
        };
    }

    /**
     * @throws InvalidAmount on integer overflow
     */
    private static function checked(int|float $value): int
    {
        if (! is_int($value)) {
            throw new InvalidAmount('Amount overflows the supported range');
        }

        return $value;
    }
}
