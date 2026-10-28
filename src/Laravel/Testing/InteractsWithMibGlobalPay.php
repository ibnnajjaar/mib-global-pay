<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Testing;

use Illuminate\Testing\TestResponse;
use IbnNajjaar\MIBGlobalPay\Laravel\MibGlobalPay;
use IbnNajjaar\MIBGlobalPay\Laravel\Support\Settings;

/**
 * For Laravel HTTP tests (PHPUnit test cases, or Pest via uses(InteractsWithMibGlobalPay::class)).
 *
 * @phpstan-require-extends \Illuminate\Foundation\Testing\TestCase
 */
trait InteractsWithMibGlobalPay
{
    /**
     * POST a webhook notification to the package's route (mib-global-pay.webhook), signed with the
     * configured MIB_WEBHOOK_SECRET unless $secret is given.
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    public function postMibNotification(FakeNotification $notification, ?string $secret = null, ?string $uri = null): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on'];

        foreach ($notification->headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $server['HTTP_X_NOTIFICATION_SECRET'] = $secret ?? $this->app->make(Settings::class)->webhookSecret();

        return $this->call('POST', $uri ?? route(MibGlobalPay::WEBHOOK_ROUTE_NAME), [], [], [], $server, $notification->body);
    }
}
