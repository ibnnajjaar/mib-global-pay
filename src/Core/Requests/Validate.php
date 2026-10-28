<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Requests;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * Input validation shared by request builders and operations. Throws before anything is sent.
 *
 * @internal
 */
final class Validate
{
    /**
     * Order IDs, transaction IDs and references: the gateway allows any characters, 1-40 long.
     * Control characters are rejected because they cannot be sent safely.
     *
     * @throws InvalidRequest
     */
    public static function identifier(string $value, string $field, int $max = 40): string
    {
        $length = mb_strlen($value);

        if ($length < 1 || $length > $max) {
            throw new InvalidRequest("{$field} must be 1-{$max} characters");
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidRequest("{$field} must not contain control characters");
        }

        return $value;
    }

    /**
     * @throws InvalidRequest
     */
    public static function optionalText(?string $value, string $field, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            throw new InvalidRequest("{$field} cannot exceed {$max} characters");
        }

        return $value;
    }

    /**
     * An absolute http(s) URL. Whether http is allowed is checked against the config by url().
     *
     * @throws InvalidRequest
     */
    public static function urlShape(?string $value, string $field): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        if (filter_var($value, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['https', 'http'], true)) {
            throw new InvalidRequest("{$field} must be an absolute http(s) URL");
        }

        return $value;
    }

    /**
     * @throws InvalidRequest when the URL is not HTTPS and the config does not allow insecure URLs
     */
    public static function url(?string $value, string $field, GatewayConfig $config): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $config->allowInsecureUrls && strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https') {
            throw new InvalidRequest("{$field} must use HTTPS (allowInsecureUrls is off)");
        }

        return $value;
    }

    /**
     * A positive amount in a currency allowed by the configuration.
     *
     * @throws InvalidRequest
     */
    public static function amount(Money $amount, string $field, GatewayConfig $config): Money
    {
        if (! $amount->isPositive()) {
            throw new InvalidRequest("{$field} must be greater than zero");
        }

        if (! $config->allowsCurrency($amount->currency)) {
            throw new InvalidRequest("{$field} currency {$amount->currency->value} is not in the configured currency allow-list");
        }

        return $amount;
    }
}
