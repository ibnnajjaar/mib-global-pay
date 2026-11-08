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
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentFailed;
use IbnNajjaar\MIBGlobalPay\Laravel\Support\WebhookRoute;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableResolver;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationStore;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\AccountVerified;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\CaptureMismatch;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentCaptured;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentReversed;
use IbnNajjaar\MIBGlobalPay\Laravel\Models\CheckoutAttempt;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\OrderIdGenerator;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use IbnNajjaar\MIBGlobalPay\Laravel\Checkout\CheckoutStarter;
use IbnNajjaar\MIBGlobalPay\Laravel\Console\ReconcileCommand;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentAuthorized;
use IbnNajjaar\MIBGlobalPay\Laravel\Outcome\OutcomeProcessor;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationVerifier;
use IbnNajjaar\MIBGlobalPay\Laravel\Stores\CacheNotificationStore;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\ConfigurationException;
use IbnNajjaar\MIBGlobalPay\Laravel\Listeners\RecordAttemptOutcome;
use IbnNajjaar\MIBGlobalPay\Laravel\Checkout\EloquentPayableResolver;
use IbnNajjaar\MIBGlobalPay\Laravel\Stores\DatabaseNotificationStore;
use IbnNajjaar\MIBGlobalPay\Laravel\Console\PruneNotificationsCommand;
use IbnNajjaar\MIBGlobalPay\Laravel\Http\Controllers\ReturnController;
use IbnNajjaar\MIBGlobalPay\Laravel\Http\Controllers\WebhookController;
use IbnNajjaar\MIBGlobalPay\Laravel\Listeners\AutoReverseDoubleCapture;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentCapturedForUnpayableOrder;
use IbnNajjaar\MIBGlobalPay\Laravel\Http\Controllers\CheckoutPageController;
use IbnNajjaar\MIBGlobalPay\Laravel\Http\Middleware\VerifyNotificationSecret;
use IbnNajjaar\MIBGlobalPay\Laravel\Http\Controllers\PaymentLinkErrorController;

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

        $this->app->when(OutcomeProcessor::class)
            ->needs('$reviewAfterSeconds')
            ->give(fn (Application $app): int => $app->make(Settings::class)->reviewAfterSeconds());
        $this->app->singleton(WebhookRoute::class);

        $this->app->singleton(NotificationStore::class, fn (Application $app): NotificationStore => match ($store = $app->make(Settings::class)->notificationStore()) {
            'database' => new DatabaseNotificationStore($app->make('db')->connection(), $app->make('db.transactions')),
            'cache' => new CacheNotificationStore($app->make('cache')->store(), $app->bound('db.transactions') ? $app->make('db.transactions') : null),
            default => throw new ConfigurationException("Unknown notification_store \"{$store}\"; expected \"database\" or \"cache\""),
        });
    }

    public function boot(): void
    {
        $this->registerRouteMacro();

        // Checks MIB_AUTO_REVERSE_DOUBLE_CAPTURE itself; does nothing while it is off (the default).
        Event::listen(CaptureMismatch::class, AutoReverseDoubleCapture::class);

        Event::listen([
            PaymentCaptured::class,
            PaymentCapturedForUnpayableOrder::class,
            PaymentAuthorized::class,
            AccountVerified::class,
            PaymentFailed::class,
            PaymentReversed::class,
        ], RecordAttemptOutcome::class);

        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'mib-global-pay');
        Blade::componentNamespace('IbnNajjaar\\MIBGlobalPay\\Laravel\\View\\Components', 'mib-global-pay');

        $settings = $this->app->make(Settings::class);

        // In boot(), so a host's PayableResolver bound in any register() method wins.
        if ($settings->managedAttempts()) {
            $this->app->bindIf(PayableResolver::class, EloquentPayableResolver::class, true);
        }

        if ($settings->webhookEnabled() && $settings->registerRoute() && ! $this->app->routesAreCached()) {
            $route = self::webhookRoute($this->app->make(Router::class), $settings->webhookPath());

            if ($settings->webhookDomain() !== null) {
                $route->domain($settings->webhookDomain());
            }
        }

        if ($settings->checkoutRoutes() && ! $this->app->routesAreCached()) {
            $this->registerCheckoutRoutes($this->app->make(Router::class), $settings);
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

    private function registerCheckoutRoutes(Router $router, Settings $settings): void
    {
        $router->middleware($settings->checkoutRouteMiddleware())->prefix($settings->checkoutRoutePrefix())->group(function () use ($router): void {
            $router->get('pay/{order}', CheckoutPageController::class)->name(CheckoutAttempt::CHECKOUT_ROUTE)->middleware('signed');
            $router->get('return/{order}', ReturnController::class)->name(CheckoutStarter::RETURN_ROUTE);
            $router->get('link-error', PaymentLinkErrorController::class)->name(CheckoutStarter::LINK_ERROR_ROUTE);
        });
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
        // Marked so it can be found even after route:cache, where a host group's name prefix or domain is all that is left.
        return WebhookRoute::mark($router->post($uri, WebhookController::class))
            ->name(MibGlobalPay::WEBHOOK_ROUTE_NAME)
            ->middleware(VerifyNotificationSecret::class)
            ->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
    }
}
