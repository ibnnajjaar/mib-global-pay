<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Support;

use Illuminate\Contracts\Config\Repository;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\ConfigurationException;

/**
 * Typed access to config/mib-global-pay.php.
 */
final readonly class Settings
{
    public function __construct(
        private Repository $config,
    ) {
    }

    /**
     * @throws ConfigurationException
     */
    public function gatewayConfig(): GatewayConfig
    {
        /** @var array<string, mixed> $values */
        $values = (array) $this->config->get('mib-global-pay', []);

        return GatewayConfig::fromArray($values);
    }

    public function webhookEnabled(): bool
    {
        return $this->bool('webhook.enabled', true);
    }

    public function webhookSecret(): string
    {
        return $this->string('webhook.secret') ?? '';
    }

    public function webhookSecretUsesDeprecatedEnv(): bool
    {
        return $this->bool('webhook.secret_uses_deprecated_env', false);
    }

    public function registerRoute(): bool
    {
        return $this->bool('webhook.register_route', true);
    }

    public function webhookPath(): string
    {
        return $this->string('webhook.path') ?? 'mib/webhook';
    }

    public function webhookDomain(): ?string
    {
        return $this->string('webhook.domain');
    }

    public function webhookQueue(): ?string
    {
        return $this->string('webhook.queue');
    }

    public function webhookConnection(): ?string
    {
        return $this->string('webhook.connection');
    }

    public function notificationStore(): string
    {
        return $this->string('notification_store') ?? 'database';
    }

    public function autoReverseDoubleCapture(): bool
    {
        return $this->bool('double_capture.auto_reverse', true);
    }

    public function reviewAfterSeconds(): int
    {
        return $this->int('attempts.review_after_seconds', 86400);
    }

    public function lateCaptureWatchSeconds(): int
    {
        return $this->int('attempts.late_capture_watch_seconds', 604800);
    }

    public function expiryGraceSeconds(): int
    {
        return $this->int('attempts.expiry_grace_seconds', 300);
    }

    public function checkoutTimeoutSeconds(): int
    {
        return $this->int('checkout.timeout_seconds', 1800);
    }

    public function managedAttempts(): bool
    {
        return $this->bool('checkout.managed_attempts', true);
    }

    public function checkoutRoutes(): bool
    {
        return $this->bool('checkout.routes.enabled', true);
    }

    public function checkoutRoutePrefix(): string
    {
        return $this->string('checkout.routes.prefix') ?? 'mib';
    }

    /** @return list<string> */
    public function checkoutRouteMiddleware(): array
    {
        $middleware = $this->config->get('mib-global-pay.checkout.routes.middleware', ['web']);

        return is_array($middleware) ? array_values(array_filter($middleware, is_string(...))) : ['web'];
    }

    public function checkoutMode(): string
    {
        return $this->string('checkout.mode') ?? 'payment';
    }

    public function checkoutCompletedUrl(): ?string
    {
        return $this->string('checkout.completed_url');
    }

    public function merchantName(): string
    {
        return $this->string('checkout.merchant_name')
            ?? throw new ConfigurationException('Set the merchant name shown to the payer (MIB_MERCHANT_NAME)');
    }

    public function merchantUrl(): ?string
    {
        return $this->string('checkout.merchant_url');
    }

    public function merchantLogoUrl(): ?string
    {
        return $this->string('checkout.merchant_logo_url');
    }

    public function checkoutJsUrl(): ?string
    {
        return $this->string('checkout_js_url');
    }

    public function logChannel(): ?string
    {
        return $this->string('logging.channel');
    }

    private function string(string $key): ?string
    {
        $value = $this->config->get('mib-global-pay.' . $key);

        return is_scalar($value) && ! is_bool($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function bool(string $key, bool $default): bool
    {
        $value = $this->config->get('mib-global-pay.' . $key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value) ? (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default) : $default;
    }

    private function int(string $key, int $default): int
    {
        $value = $this->config->get('mib-global-pay.' . $key, $default);

        return is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : $default);
    }
}
