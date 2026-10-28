<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Http;

/**
 * Masks secrets before request and response bodies are logged.
 */
final class Redactor
{
    public const MASK = '[REDACTED]';

    /** Keys whose values are always masked, compared case-insensitively. */
    private const SENSITIVE_KEYS = [
        'password',
        'apipassword',
        'secret',
        'notificationsecret',
        'successindicator',
        'resultindicator',
        'securitycode',
        'cardsecuritycode',
        'number',
        'token',
        'authorization',
    ];

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
