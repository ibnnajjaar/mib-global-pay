<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use IbnNajjaar\MIBGlobalPay\Core\MibGateway;
use Illuminate\Contracts\Foundation\Application;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Laravel\Support\Settings;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Support\GatewayOrderId;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\OrderIdGenerator;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationVerifier;

/**
 * Auto-discovered through extra.laravel.providers.
 */
class MibGlobalPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/mib-global-pay.php', 'mib-global-pay');

        $this->app->singleton(Settings::class, fn (Application $app): Settings => new Settings($app->make('config')));

        $this->app->singleton(GatewayConfig::class, fn (Application $app): GatewayConfig => $app->make(Settings::class)->gatewayConfig());

        $this->app->singleton(MibGateway::class, function (Application $app): MibGateway {
            $channel = $app->make(Settings::class)->logChannel();

            return new MibGateway(
                config: $app->make(GatewayConfig::class),
                logger: $channel === null ? Log::getLogger() : Log::channel($channel),
            );
        });
        $this->app->alias(MibGateway::class, Gateway::class);

        $this->app->singleton(OrderIdGenerator::class, GatewayOrderId::class);

        // Not a singleton: resolving it fails closed (ConfigurationException) while no secret is configured.
        $this->app->bind(NotificationVerifier::class, function (Application $app): NotificationVerifier {
            $settings = $app->make(Settings::class);

            if ($settings->webhookSecretUsesDeprecatedEnv()) {
                Log::warning('MIB_WEBHOOK_SIGNATURE is deprecated; rename it to MIB_WEBHOOK_SECRET.');
            }

            return new NotificationVerifier($settings->webhookSecret());
        });

        $this->app->singleton(MibGlobalPay::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/mib-global-pay.php' => $this->app->configPath('mib-global-pay.php'),
            ], 'mib-global-pay-config');
        }
    }
}
