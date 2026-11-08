<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Support;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use IbnNajjaar\MIBGlobalPay\Laravel\MibGlobalPay;

/**
 * Finds the webhook route, whatever name prefix or domain a host group gave it and whether or
 * not routes come from route:cache. The macro does not run when they do, so the route can only
 * be recognised by what compilation keeps: the action array, where mark() numbers each macro
 * route. The number makes the last registration win, as Laravel's route order cannot: it lists
 * routes with a domain first, and the cache file keeps that order.
 *
 * @internal
 */
final class WebhookRoute
{
    private const string ACTION_KEY = 'mib_global_pay_webhook';

    private static int $registrations = 0;

    public function __construct(private readonly Router $router)
    {
    }

    public static function mark(Route $route): Route
    {
        $route->action[self::ACTION_KEY] = ++self::$registrations;

        return $route;
    }

    /**
     * The last route registered with Route::mibGlobalPayWebhook(), else a route with the
     * package's name. Under route:cache this builds Route objects for POST routes (Laravel 13)
     * or all routes (Laravel 12), which is cheap next to the checkout call that follows.
     */
    public function route(): ?Route
    {
        $routes = $this->router->getRoutes();
        $last = null;
        $lastRegistration = 0;

        foreach ($routes->get('POST') as $route) {
            $registration = $route->getAction(self::ACTION_KEY);

            if (is_int($registration) && $registration > $lastRegistration) {
                $last = $route;
                $lastRegistration = $registration;
            }
        }

        return $last ?? $routes->getByName(MibGlobalPay::WEBHOOK_ROUTE_NAME);
    }
}
