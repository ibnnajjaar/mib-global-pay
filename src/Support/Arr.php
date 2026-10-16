<?php

namespace IbnNajjaar\MIBGlobalPay\Support;

final class Arr
{
    /**
     * Read a nested value using dot notation, e.g. "response.gatewayCode".
     * Returns $default when any segment is missing or not an array.
     *
     * @param array $data
     * @param string $path
     * @param mixed $default
     * @return mixed
     */
    public static function get(array $data, string $path, $default = null)
    {
        $value = $data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Lowercase a non-empty scalar value, otherwise null.
     *
     * @param mixed $value
     */
    public static function lower($value): ?string
    {
        if (!is_scalar($value) || $value === '' || is_bool($value)) {
            return null;
        }

        return strtolower((string) $value);
    }

    /**
     * Cast a non-empty scalar value to string, otherwise null.
     *
     * @param mixed $value
     */
    public static function string($value): ?string
    {
        if (!is_scalar($value) || $value === '' || is_bool($value)) {
            return null;
        }

        return (string) $value;
    }

    /**
     * Cast a numeric value to float, otherwise null.
     *
     * @param mixed $value
     */
    public static function float($value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Recursively remove null values, then arrays left empty.
     */
    public static function filterNull(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = self::filterNull($value);
                $data[$key] = $value;
            }

            if ($value === null || $value === []) {
                unset($data[$key]);
            }
        }

        return $data;
    }
}
