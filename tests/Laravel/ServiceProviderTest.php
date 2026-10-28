<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use IbnNajjaar\MIBGlobalPay\Core\MibGateway;
use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Config\Environment;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Laravel\Facades\MibGlobalPay;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\OrderIdGenerator;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationVerifier;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\ConfigurationException;

it('builds the gateway config from config/mib-global-pay.php with the decided defaults', function () {
    $config = app(GatewayConfig::class);

    expect($config->environment)->toBe(Environment::Test)
        ->and($config->host)->toBe('test-mib.mtf.gateway.mastercard.com')
        ->and($config->apiVersion)->toBe(100)
        ->and($config->timeout)->toBe(30)
        ->and($config->currencies)->toBe([Currency::Mvr])
        ->and($config->orderPrefix)->toBe('ISIMS')
        ->and($config->approvalPolicy->acceptApprovedAuto)->toBeFalse()
        ->and($config->allowInsecureUrls)->toBeFalse()
        ->and(config('mib-global-pay.webhook.path'))->toBe('mib/webhook')
        ->and(config('mib-global-pay.webhook.register_route'))->toBeTrue()
        ->and(config('mib-global-pay.notification_store'))->toBe('cache')
        ->and(config('mib-global-pay.double_capture.auto_reverse'))->toBeFalse()
        ->and(config('mib-global-pay.attempts.expiry_grace_seconds'))->toBe(300)
        ->and(config('mib-global-pay.checkout.timeout_seconds'))->toBe(1800);
});

it('binds the gateway as a singleton behind the contract', function () {
    expect(app(Gateway::class))->toBeInstanceOf(MibGateway::class)
        ->and(app(Gateway::class))->toBe(app(MibGateway::class))
        ->and(app(OrderIdGenerator::class))->toBe(app(OrderIdGenerator::class));
});

it('requires MIB_ORDER_PREFIX', function () {
    config()->set('mib-global-pay.order_prefix', null);
    app()->forgetInstance(GatewayConfig::class);

    app(GatewayConfig::class);
})->throws(ConfigurationException::class, 'MIB_ORDER_PREFIX');

it('fails closed when no webhook secret is configured', function () {
    config()->set('mib-global-pay.webhook.secret', null);

    app(NotificationVerifier::class);
})->throws(ConfigurationException::class, 'MIB_WEBHOOK_SECRET');

it('warns when the deprecated MIB_WEBHOOK_SIGNATURE supplies the secret', function () {
    config()->set('mib-global-pay.webhook.secret_uses_deprecated_env', true);
    Log::shouldReceive('warning')->once()->withArgs(fn (string $m) => str_contains($m, 'MIB_WEBHOOK_SIGNATURE'));

    expect(app(NotificationVerifier::class))->toBeInstanceOf(NotificationVerifier::class);
});

it('exposes the helpers through the facade', function () {
    $created = new DateTimeImmutable('2026-10-28 10:00:00');

    expect(MibGlobalPay::attemptOrderId('(R)IUM/FN/2026/123', 2))->toBe('ISIMS-R-IUM-FN-2026-123-2')
        ->and(MibGlobalPay::randomOrderId())->toMatch('/^ISIMS-[A-Z2-7]{16}$/')
        ->and(MibGlobalPay::attemptExpiresAt($created)->format('H:i:s'))->toBe('10:35:00')
        ->and(MibGlobalPay::gateway())->toBe(app(Gateway::class));
});

it('builds checkout requests with the configured timeout', function () {
    $body = MibGlobalPay::newCheckoutRequest('O-1', Money::of('10', Currency::Mvr), 'IUM', 'https://students.ium.test/return')
        ->withoutWebhook()
        ->toBody(app(GatewayConfig::class));

    expect($body['interaction']['timeout'])->toBe(1800);
});

it('publishes the config file', function () {
    $this->artisan('vendor:publish', ['--tag' => 'mib-global-pay-config', '--force' => true])->assertSuccessful();

    expect(file_exists(config_path('mib-global-pay.php')))->toBeTrue();
    @unlink(config_path('mib-global-pay.php'));
});
