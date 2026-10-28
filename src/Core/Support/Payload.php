<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Support;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Enums\GatewayEnum;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidAmount;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\UnsupportedCurrency;

/**
 * Null-safe reader over a decoded gateway payload.
 *
 * Paths use dot notation ("response.gatewayCode", "transaction.0.result").
 * Missing keys, null and '' read as null (or the enum's Unrecognized case) and never
 * emit notices. A value that is present but has the wrong type or format throws
 * MalformedPayload, because silently ignoring it could hide a payment problem.
 */
final readonly class Payload
{
    /**
     * @param array<array-key, mixed> $data
     */
    private function __construct(
        private array $data,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /** @return array<array-key, mixed> */
    public function raw(): array
    {
        return $this->data;
    }

    public function has(string $path): bool
    {
        return $this->get($path) !== null;
    }

    public function get(string $path): mixed
    {
        $value = $this->data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @throws MalformedPayload when the value is not a scalar
     */
    public function string(string $path): ?string
    {
        $value = $this->get($path);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw $this->malformed($path, 'a string');
    }

    /**
     * @throws MalformedPayload when the value is missing or not a scalar
     */
    public function requireString(string $path): string
    {
        return $this->string($path) ?? throw new MalformedPayload("Payload is missing required value \"{$path}\"");
    }

    /**
     * @throws MalformedPayload when the value is not an integer
     */
    public function int(string $path): ?int
    {
        $value = $this->get($path);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw $this->malformed($path, 'an integer');
    }

    /**
     * @throws MalformedPayload when the value is not a boolean
     */
    public function bool(string $path): ?bool
    {
        $value = $this->get($path);

        return match (true) {
            $value === null, $value === '' => null,
            is_bool($value) => $value,
            $value === 'true' => true,
            $value === 'false' => false,
            default => throw $this->malformed($path, 'a boolean'),
        };
    }

    /**
     * Never null: a missing value resolves to the enum's Unrecognized case.
     *
     * @template T of GatewayEnum
     *
     * @param class-string<T> $enum
     *
     * @return T
     *
     * @throws MalformedPayload when the value is not a scalar
     */
    public function enum(string $path, string $enum): GatewayEnum
    {
        return $enum::fromGateway($this->string($path));
    }

    /**
     * @throws MalformedPayload when the value is not a scalar
     */
    public function currency(string $path): Currency
    {
        return Currency::fromGateway($this->string($path));
    }

    /**
     * An amount whose currency is in another field, e.g. money('order.amount', 'order.currency').
     * Null when the amount is missing.
     *
     * @throws MalformedPayload when the amount is invalid, has too many decimals or its currency is unsupported
     */
    public function money(string $amountPath, string $currencyPath): ?Money
    {
        if ($this->get($amountPath) === null) {
            return null;
        }

        $currency = $this->currency($currencyPath);
        if (! $currency->isRecognized()) {
            throw new MalformedPayload(sprintf(
                'Payload value "%s" has an unsupported or missing currency at "%s"',
                $amountPath,
                $currencyPath,
            ));
        }

        return $this->moneyIn($amountPath, $currency);
    }

    /**
     * An amount in a known currency. Null when the amount is missing.
     *
     * @throws MalformedPayload
     */
    public function moneyIn(string $amountPath, Currency $currency): ?Money
    {
        $value = $this->get($amountPath);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw $this->malformed($amountPath, 'an amount');
        }

        try {
            return Money::fromGateway($value, $currency);
        } catch (InvalidAmount|UnsupportedCurrency $e) {
            throw new MalformedPayload("Payload value \"{$amountPath}\" is not a valid amount: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * A nested object, or null when missing.
     *
     * @throws MalformedPayload when the value is not an object/array
     */
    public function payload(string $path): ?self
    {
        $value = $this->get($path);

        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw $this->malformed($path, 'an object');
        }

        return new self($value);
    }

    /**
     * A list of nested objects; empty when missing.
     *
     * @return list<self>
     *
     * @throws MalformedPayload when the value is not a list of objects
     */
    public function list(string $path): array
    {
        $value = $this->get($path);

        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw $this->malformed($path, 'a list');
        }

        $items = [];
        foreach ($value as $index => $item) {
            if (! is_array($item)) {
                throw $this->malformed("{$path}.{$index}", 'an object');
            }
            $items[] = new self($item);
        }

        return $items;
    }

    private function malformed(string $path, string $expected): MalformedPayload
    {
        return new MalformedPayload(sprintf(
            'Payload value "%s" should be %s, got %s',
            $path,
            $expected,
            get_debug_type($this->get($path)),
        ));
    }
}
