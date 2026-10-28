<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Enums;

/**
 * Shared implementation of GatewayEnum for string-backed enums with an
 * `Unrecognized = ''` case. Matching is case-insensitive and ignores
 * surrounding whitespace (version 1 of this package exposed lowercase values).
 */
trait ResolvesFromGateway
{
    public static function fromGateway(?string $value): static
    {
        if ($value === null) {
            return self::Unrecognized;
        }

        $normalized = strtoupper(trim($value));
        if ($normalized === '') {
            return self::Unrecognized;
        }

        return self::tryFrom($normalized) ?? self::Unrecognized;
    }

    public function isRecognized(): bool
    {
        return $this !== self::Unrecognized;
    }
}
