<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Support;

/**
 * Helpers for building JSON request bodies.
 */
final class RequestBody
{
    /**
     * Recursively remove null values, then arrays left empty.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    public static function withoutNulls(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = self::withoutNulls($value);
                $data[$key] = $value;
            }

            if ($value === null || $value === []) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Deep-merge $overrides into $data; overrides win, and a null override removes the key
     * (once withoutNulls() runs).
     *
     * @param array<array-key, mixed> $data
     * @param array<array-key, mixed> $overrides
     *
     * @return array<array-key, mixed>
     */
    public static function merge(array $data, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($data[$key]) && is_array($data[$key]) && ! array_is_list($value)) {
                $data[$key] = self::merge($data[$key], $value);
            } else {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    public static function segment(string $value): string
    {
        return rawurlencode($value);
    }
}
