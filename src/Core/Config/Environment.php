<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Config;

use IbnNajjaar\MIBGlobalPay\Core\Exceptions\ConfigurationException;

/**
 * Gateway environment presets. The host can always be overridden in GatewayConfig.
 */
enum Environment: string
{
    case Test = 'test';
    case Production = 'production';

    /**
     * The documented gateway host for this environment, or null when there is no preset.
     */
    public function presetHost(): ?string
    {
        return match ($this) {
            self::Test => 'test-mib.mtf.gateway.mastercard.com',
            // TODO: add the production host once MIB confirms it
            // (DESIGN-v2.md §12, "Needs MIB" question 14: production gateway host name).
            self::Production => null,
        };
    }

    /**
     * @throws ConfigurationException
     */
    public static function fromConfig(string $value): self
    {
        return self::tryFrom(strtolower(trim($value)))
            ?? throw new ConfigurationException("Unknown environment \"{$value}\"; expected \"test\" or \"production\"");
    }
}
