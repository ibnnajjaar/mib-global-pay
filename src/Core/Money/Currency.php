<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Money;

use IbnNajjaar\MIBGlobalPay\Core\Enums\GatewayEnum;
use IbnNajjaar\MIBGlobalPay\Core\Enums\ResolvesFromGateway;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\UnsupportedCurrency;

/**
 * A curated subset of ISO 4217 currencies, each with its ISO minor-unit exponent.
 *
 * Which currencies a merchant may actually use is decided by the configuration
 * allow-list (GatewayConfig::$currencies, default [MVR]), not by this enum.
 *
 * To add a currency:
 *  1. add a case named after the ISO code in PascalCase, backed by the uppercase
 *     ISO code (e.g. `case Bhd = 'BHD';`);
 *  2. add its ISO 4217 minor-unit exponent to minorUnits() (e.g. BHD => 3);
 *  3. add it to the dataset in tests/Core/CurrencyTest.php.
 */
enum Currency: string implements GatewayEnum
{
    use ResolvesFromGateway;

    case Aed = 'AED';
    case Eur = 'EUR';
    case Gbp = 'GBP';
    case Inr = 'INR';
    case Jpy = 'JPY';
    case Kwd = 'KWD';
    case Lkr = 'LKR';
    case Mvr = 'MVR';
    case Sgd = 'SGD';
    case Usd = 'USD';

    /** The gateway sent a currency this package does not support. */
    case Unrecognized = '';

    /**
     * Number of digits after the decimal point (ISO 4217 minor units).
     *
     * @throws UnsupportedCurrency for Unrecognized
     */
    public function minorUnits(): int
    {
        return match ($this) {
            self::Jpy => 0,
            self::Kwd => 3,
            self::Aed, self::Eur, self::Gbp, self::Inr, self::Lkr, self::Mvr, self::Sgd, self::Usd => 2,
            self::Unrecognized => throw new UnsupportedCurrency('Unrecognized currency has no minor units'),
        };
    }

    /**
     * Strict lookup for configuration and input: unknown codes throw.
     *
     * @throws UnsupportedCurrency
     */
    public static function fromCode(string $code): self
    {
        $currency = self::fromGateway($code);
        if (! $currency->isRecognized()) {
            throw new UnsupportedCurrency("Unsupported currency code: {$code}");
        }

        return $currency;
    }
}
