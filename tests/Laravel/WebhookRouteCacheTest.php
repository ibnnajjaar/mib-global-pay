<?php

declare(strict_types=1);

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Contracts\Console\Kernel;
use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use Illuminate\Routing\CompiledRouteCollection;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use Illuminate\Foundation\Console\RouteCacheCommand;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Laravel\Testing\OrderFactory;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentCaptured;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use IbnNajjaar\MIBGlobalPay\Laravel\Testing\NotificationFactory;
use IbnNajjaar\MIBGlobalPay\Laravel\Testing\InteractsWithMibGlobalPay;
use IbnNajjaar\MIBGlobalPay\Laravel\MibGlobalPay as MibGlobalPayService;
use IbnNajjaar\MIBGlobalPay\Laravel\Http\Middleware\VerifyNotificationSecret;

uses(InteractsWithMibGlobalPay::class);

const WEBHOOK_PATH = 'payment-gateways/mib/webhook';
const NOT_THE_WEBHOOK = 'not-the-webhook';

/**
 * Boots an application, registers the routes, runs the real route:cache command on it and boots
 * a second application that loads that file. Only the route registration step differs from a
 * deploy: the command's getFreshApplication() is the application under test, because Testbench's
 * skeleton bootstrap/app.php knows nothing about the routes each test defines. The macro never
 * runs in the second application.
 *
 * @param array<string, mixed> $config
 */
function bootFromRouteCache(Closure $routes, array $config = []): void
{
    $config += ['mib-global-pay.allow_insecure_urls' => true];
    $path = tempnam(sys_get_temp_dir(), 'mib-routes-') . '.php';
    routeCacheEnvironment($path);

    test()->bootWith($config);
    $routes();
    Route::post(NOT_THE_WEBHOOK, fn () => 'ok')->name(NOT_THE_WEBHOOK);

    $command = new class (app('files')) extends RouteCacheCommand {
        protected function getFreshApplication()
        {
            return $this->laravel;
        }
    };
    app(Kernel::class)->registerCommand($command);
    Artisan::call('route:cache');

    expect(is_file($path))->toBeTrue();

    test()->bootWith($config);
    require $path;

    expect(app()->routesAreCached())->toBeTrue()
        ->and(Route::getRoutes())->toBeInstanceOf(CompiledRouteCollection::class);
}

function bootWithoutRouteCache(Closure $routes, array $config = []): void
{
    $config += ['mib-global-pay.allow_insecure_urls' => true];
    routeCacheEnvironment(null);

    test()->bootWith($config);
    $routes();
    Route::post(NOT_THE_WEBHOOK, fn () => 'ok')->name(NOT_THE_WEBHOOK);
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();

    expect(app()->routesAreCached())->toBeFalse();
}

function routeCacheEnvironment(?string $path): void
{
    foreach ([&$_ENV, &$_SERVER] as &$variables) {
        if ($path === null) {
            unset($variables['APP_ROUTES_CACHE']);
        } else {
            $variables['APP_ROUTES_CACHE'] = $path;
        }
    }

    putenv($path === null ? 'APP_ROUTES_CACHE' : "APP_ROUTES_CACHE={$path}");
}

function webhookOutcome(): array
{
    $request = app(MibGlobalPayService::class)->newCheckoutRequest(LARAVEL_ORDER, Money::of('10', Currency::Mvr), 'IUM', 'https://students.ium.test/return');

    return [
        'webhookUrl' => app(MibGlobalPayService::class)->webhookUrl(),
        'notificationUrl' => $request->notificationUrl(),
        'body' => $request->notificationUrl() === null ? null : $request->toBody(app(GatewayConfig::class))['order']['notificationUrl'] ?? null,
    ];
}

/** The webhook outcome without and with a route cache, each from its own application. */
function compareCachedToUncached(Closure $routes, array $config = [], ?Closure $afterBoot = null): array
{
    bootWithoutRouteCache($routes, $config);
    $afterBoot?->__invoke();
    $uncached = webhookOutcome();

    bootFromRouteCache($routes, $config);
    $afterBoot?->__invoke();
    $cached = webhookOutcome();

    return [$uncached, $cached];
}

afterEach(function () {
    $path = $_ENV['APP_ROUTES_CACHE'] ?? null;
    routeCacheEnvironment(null);

    if (is_string($path) && is_file($path)) {
        unlink($path);
    }
});

it('gives the same webhook URL from a route cache as without one', function (Closure $routes, array $config, ?Closure $afterBoot, string $expected) {
    [$uncached, $cached] = compareCachedToUncached($routes, $config, $afterBoot);

    expect($cached)->toBe($uncached)
        ->and($cached)->toBe(['webhookUrl' => $expected, 'notificationUrl' => $expected, 'body' => $expected]);
})->with([
    'name prefix' => [
        fn () => Route::name('student.')->group(fn () => Route::mibGlobalPayWebhook(WEBHOOK_PATH)),
        ['mib-global-pay.webhook.register_route' => false],
        null,
        'http://localhost/payment-gateways/mib/webhook',
    ],
    'domain and name prefix, as ISIMS' => [
        fn () => Route::domain('students.ium.test')->name('student.')->middleware('web')->group(fn () => Route::mibGlobalPayWebhook(WEBHOOK_PATH)),
        ['mib-global-pay.webhook.register_route' => false],
        null,
        'http://students.ium.test/payment-gateways/mib/webhook',
    ],
    'domain group without a name prefix' => [
        fn () => Route::domain('students.ium.test')->group(fn () => Route::mibGlobalPayWebhook(WEBHOOK_PATH)),
        ['mib-global-pay.webhook.register_route' => false],
        null,
        'http://students.ium.test/payment-gateways/mib/webhook',
    ],
    'domain with HTTPS forced' => [
        fn () => Route::domain('students.ium.test')->name('student.')->group(fn () => Route::mibGlobalPayWebhook(WEBHOOK_PATH)),
        ['mib-global-pay.webhook.register_route' => false],
        fn () => URL::forceScheme('https'),
        'https://students.ium.test/payment-gateways/mib/webhook',
    ],
    'no domain with a forced root URL' => [
        fn () => Route::name('student.')->group(fn () => Route::mibGlobalPayWebhook(WEBHOOK_PATH)),
        ['mib-global-pay.webhook.register_route' => false],
        fn () => URL::forceRootUrl('https://pay.example.com'), // the scheme still follows the request
        'http://pay.example.com/payment-gateways/mib/webhook',
    ],
    'path prefix and name prefix' => [
        fn () => Route::prefix('api/v1')->name('api.')->group(fn () => Route::mibGlobalPayWebhook('mib/webhook')),
        ['mib-global-pay.webhook.register_route' => false],
        null,
        'http://localhost/api/v1/mib/webhook',
    ],
    'a name the host added and a domain set on the returned route' => [
        fn () => Route::mibGlobalPayWebhook(WEBHOOK_PATH)->name('.renamed')->domain('pay.example.com'),
        ['mib-global-pay.webhook.register_route' => false],
        null,
        'http://pay.example.com/payment-gateways/mib/webhook',
    ],
    'auto-registered' => [
        fn () => null,
        [],
        null,
        'http://localhost/mib/webhook',
    ],
    'auto-registered with a configured path and domain' => [
        fn () => null,
        ['mib-global-pay.webhook.path' => 'hooks/mib', 'mib-global-pay.webhook.domain' => 'pay.example.com'],
        null,
        'http://pay.example.com/hooks/mib',
    ],
    'a host macro route with the auto-registered route left on' => [
        fn () => Route::name('student.')->group(fn () => Route::mibGlobalPayWebhook(WEBHOOK_PATH)),
        [],
        null,
        'http://localhost/payment-gateways/mib/webhook',
    ],
    'a host macro route on a domain with the auto-registered route left on' => [
        fn () => Route::domain('students.ium.test')->name('student.')->group(fn () => Route::mibGlobalPayWebhook(WEBHOOK_PATH)),
        [],
        null,
        'http://students.ium.test/payment-gateways/mib/webhook',
    ],
    'the last of two macro routes' => [
        function () {
            Route::name('first.')->group(fn () => Route::mibGlobalPayWebhook('first/webhook'));
            Route::name('second.')->group(fn () => Route::mibGlobalPayWebhook('second/webhook'));
        },
        ['mib-global-pay.webhook.register_route' => false],
        null,
        'http://localhost/second/webhook',
    ],
    'the last of two macro routes when the first has a domain' => [
        function () {
            Route::domain('first.example.com')->name('first.')->group(fn () => Route::mibGlobalPayWebhook('first/webhook'));
            Route::name('second.')->group(fn () => Route::mibGlobalPayWebhook('second/webhook'));
        },
        ['mib-global-pay.webhook.register_route' => false],
        null,
        'http://localhost/second/webhook',
    ],
    'the last of two macro routes when the last has a domain' => [
        function () {
            Route::name('first.')->group(fn () => Route::mibGlobalPayWebhook('first/webhook'));
            Route::domain('second.example.com')->name('second.')->group(fn () => Route::mibGlobalPayWebhook('second/webhook'));
        },
        ['mib-global-pay.webhook.register_route' => false],
        null,
        'http://second.example.com/second/webhook',
    ],
    'a macro route while the webhook is disabled' => [
        fn () => Route::name('student.')->group(fn () => Route::mibGlobalPayWebhook(WEBHOOK_PATH)),
        ['mib-global-pay.webhook.register_route' => false, 'mib-global-pay.webhook.enabled' => false],
        null,
        'http://localhost/payment-gateways/mib/webhook',
    ],
    'a hand-written route with the package name' => [
        fn () => Route::post('hand/written', IbnNajjaar\MIBGlobalPay\Laravel\Http\Controllers\WebhookController::class)->name(MibGlobalPayService::WEBHOOK_ROUTE_NAME),
        ['mib-global-pay.webhook.register_route' => false],
        null,
        'http://localhost/hand/written',
    ],
]);

it('gives null with no webhook route, with or without a route cache', function (array $config) {
    [$uncached, $cached] = compareCachedToUncached(fn () => null, $config);

    expect($uncached)->toBe(['webhookUrl' => null, 'notificationUrl' => null, 'body' => null])
        ->and($cached)->toBe($uncached);
})->with([
    'host never registered it' => [['mib-global-pay.webhook.register_route' => false]],
    'disabled, so nothing was auto-registered' => [['mib-global-pay.webhook.enabled' => false]],
]);

it('is not fooled by another route that only looks like the webhook', function () {
    [$uncached, $cached] = compareCachedToUncached(
        fn () => Route::post('other/webhook', fn () => 'ok')->name('student.mib-global-pay.webhook'),
        ['mib-global-pay.webhook.register_route' => false],
    );

    expect($cached)->toBe($uncached)
        ->and($cached['webhookUrl'])->toBeNull();
});

it('lets route:cache refuse two macro routes with the same name', function () {
    bootWithoutRouteCache(function () {
        Route::mibGlobalPayWebhook('first/webhook');
        Route::mibGlobalPayWebhook('second/webhook');
    }, ['mib-global-pay.webhook.register_route' => false]);

    expect(webhookOutcome()['webhookUrl'])->toBe('http://localhost/second/webhook');

    bootFromRouteCache(function () {
        Route::mibGlobalPayWebhook('first/webhook');
        Route::mibGlobalPayWebhook('second/webhook');
    }, ['mib-global-pay.webhook.register_route' => false]);
})->throws(LogicException::class, 'Another route has already been assigned name [mib-global-pay.webhook]');

it('lets route:cache refuse an unprefixed macro route next to the auto-registered one', function () {
    bootFromRouteCache(fn () => Route::mibGlobalPayWebhook(WEBHOOK_PATH));
})->throws(LogicException::class, 'Another route has already been assigned name [mib-global-pay.webhook]');

it('keeps the secret middleware and the CSRF exclusion on the cached route', function () {
    bootFromRouteCache(
        fn () => Route::domain('students.ium.test')->name('student.')->middleware('web')->group(fn () => Route::mibGlobalPayWebhook(WEBHOOK_PATH)),
        ['mib-global-pay.webhook.register_route' => false],
    );

    // Shows the exclusion list survives compilation. Middleware groups are not cached, so one is defined here.
    Route::middlewareGroup('web', [ValidateCsrfToken::class]);

    $route = Route::getRoutes()->getByName('student.mib-global-pay.webhook');
    $middleware = Route::gatherRouteMiddleware($route);

    expect($route->excludedMiddleware())->toContain(ValidateCsrfToken::class)
        ->and($middleware)->toContain(VerifyNotificationSecret::class)
        ->and($middleware)->not->toContain(ValidateCsrfToken::class);
});

it('serves a notification through postMibNotification from the cached route', function (Closure $routes, array $config) {
    bootFromRouteCache($routes, $config);
    fakeCapturedOrderForCachedRoute();

    $this->postMibNotification(NotificationFactory::paymentCaptured(LARAVEL_ORDER, frozenAmount()))->assertOk();

    Event::assertDispatched(PaymentCaptured::class);
})->with([
    'name prefix' => [fn () => Route::name('student.')->group(fn () => Route::mibGlobalPayWebhook(WEBHOOK_PATH)), ['mib-global-pay.webhook.register_route' => false]],
    'domain and name prefix' => [fn () => Route::domain('students.ium.test')->name('student.')->group(fn () => Route::mibGlobalPayWebhook(WEBHOOK_PATH)), ['mib-global-pay.webhook.register_route' => false]],
    'auto-registered' => [fn () => null, []],
]);

it('still rejects a bad secret on the cached route', function () {
    bootFromRouteCache(
        fn () => Route::name('student.')->group(fn () => Route::mibGlobalPayWebhook(WEBHOOK_PATH)),
        ['mib-global-pay.webhook.register_route' => false],
    );
    fakeCapturedOrderForCachedRoute();

    $this->postMibNotification(NotificationFactory::paymentCaptured(LARAVEL_ORDER, frozenAmount()), secret: 'wrong')->assertStatus(401);

    Event::assertNotDispatched(PaymentCaptured::class);
});

it('answers 404 from a disabled macro route, with or without a route cache', function (string $boot) {
    $boot(
        fn () => Route::name('student.')->group(fn () => Route::mibGlobalPayWebhook(WEBHOOK_PATH)),
        ['mib-global-pay.webhook.register_route' => false, 'mib-global-pay.webhook.enabled' => false],
    );

    $this->postMibNotification(NotificationFactory::paymentCaptured(LARAVEL_ORDER, frozenAmount()))->assertNotFound();
})->with(['bootWithoutRouteCache', 'bootFromRouteCache']);

it('explains itself when postMibNotification finds no cached route', function () {
    bootFromRouteCache(fn () => null, ['mib-global-pay.webhook.register_route' => false]);

    $this->postMibNotification(NotificationFactory::paymentCaptured(LARAVEL_ORDER, frozenAmount()));
})->throws(LogicException::class, 'The MIB webhook route is not registered');

function fakeCapturedOrderForCachedRoute(): void
{
    IbnNajjaar\MIBGlobalPay\Laravel\Facades\MibGlobalPay::fake()->order(LARAVEL_ORDER, OrderFactory::captured(frozenAmount()));
    bindResolver(hostPayable());
    Event::fake([PaymentCaptured::class]);
}
