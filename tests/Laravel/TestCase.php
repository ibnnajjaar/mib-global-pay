<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Tests\Laravel;

use Orchestra\Testbench\TestCase as Orchestra;
use IbnNajjaar\MIBGlobalPay\Laravel\MibGlobalPayServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MibGlobalPayServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['MibGlobalPay' => \IbnNajjaar\MIBGlobalPay\Laravel\Facades\MibGlobalPay::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mib-global-pay.merchant_id', 'TEST123');
        $app['config']->set('mib-global-pay.api_password', 'super-secret-password');
        $app['config']->set('mib-global-pay.order_prefix', 'ISIMS');
        $app['config']->set('mib-global-pay.webhook.secret', '7C1F3A9E5B2D4F6081A3C5E7092B4D6F');
        $app['config']->set('mib-global-pay.notification_store', 'cache');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('app.url', 'https://students.ium.test');
    }
}
