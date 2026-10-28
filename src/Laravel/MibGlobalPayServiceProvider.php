<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use IbnNajjaar\MIBGlobalPay\Core\MibGateway;
use Illuminate\Contracts\Foundation\Application;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Laravel\Support\Settings;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Support\GatewayOrderId;
use IbnNajjaar\MIBGlobalPay\Laravel\Support\WebhookRoute;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationStore;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\CaptureMismatch;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\OrderIdGenerator;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use IbnNajjaar\MIBGlobalPay\Laravel\Console\ReconcileCommand;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationVerifier;
use IbnNajjaar\MIBGlobalPay\Laravel\Stores\CacheNotificationStore;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\ConfigurationException;
use IbnNajjaar\MIBGlobalPay\Laravel\Stores\DatabaseNotificationStore;
use IbnNajjaar\MIBGlobalPay\Laravel\Console\PruneNotificationsCommand;
use IbnNajjaar\MIBGlobalPay\Laravel\Http\Controllers\WebhookController;
use IbnNajjaar\MIBGlobalPay\Laravel\Listeners\AutoReverseDoubleCapture;
use IbnNajjaar\MIBGlobalPay\Laravel\Http\Middleware\VerifyNotificationSecret;

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
        $this->app->singleton(WebhookRoute::class);

        $this->app->singleton(NotificationStore::class, function (Application $app): NotificationStore {
            return match ($store = $app->make(Settings::class)->notificationStore()) {
                'database' => new DatabaseNotificationStore($app->make('db')->connection()),
                'cache' => new CacheNotificationStore($app->make('cache')->store()),
                default => throw new ConfigurationException("Unknown notification_store \"{$store}\"; expected \"database\" or \"cache\""),
            };
        });
    }

    public function boot(): void
    {
        $this->registerRouteMacro();

        // Checks MIB_AUTO_REVERSE_DOUBLE_CAPTURE itself; does nothing while it is off (the default).
        Event::listen(CaptureMismatch::class, AutoReverseDoubleCapture::class);

        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'mib-global-pay');
        Blade::componentNamespace('IbnNajjaar\\MIBGlobalPay\\Laravel\\View\\Components', 'mib-global-pay');

        $settings = $this->app->make(Settings::class);
        if ($settings->webhookEnabled() && $settings->registerRoute() && ! $this->app->routesAreCached()) {
            $route = self::webhookRoute($this->app->make(Router::class), $settings->webhookPath());

            if ($settings->webhookDomain() !== null) {
                $route->domain($settings->webhookDomain());
            }
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/mib-global-pay.php' => $this->app->configPath('mib-global-pay.php'),
            ], 'mib-global-pay-config');

            $this->commands([ReconcileCommand::class, PruneNotificationsCommand::class]);

            $this->publishesMigrations([
                __DIR__ . '/../../database/migrations' => $this->app->databasePath('migrations'),
            ], 'mib-global-pay-migrations');
        }
    }

    /**
     * Route::mibGlobalPayWebhook($uri): POST, the package controller and secret middleware,
     * named mib-global-pay.webhook, without CSRF. Returns the Route so hosts can register it in
     * their own route file and domain group (set webhook.register_route to false).
     */
    private function registerRouteMacro(): void
    {
        if (Router::hasMacro('mibGlobalPayWebhook')) {
            return;
        }

        Router::macro('mibGlobalPayWebhook', function (string $uri): Route {
            /** @var Router $this */
            return MibGlobalPayServiceProvider::webhookRoute($this, $uri);
        });
    }

    /** @internal the body of the Route::mibGlobalPayWebhook() macro */
    public static function webhookRoute(Router $router, string $uri): Route
    {
        $route = $router->post($uri, WebhookController::class)
            ->name(MibGlobalPay::WEBHOOK_ROUTE_NAME)
            ->middleware(VerifyNotificationSecret::class)
            ->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        // Remember the Route itself: a host group may prefix its name or set its domain.
        \Illuminate\Container\Container::getInstance()->make(WebhookRoute::class)->remember($route);

        return $route;
    }
}
