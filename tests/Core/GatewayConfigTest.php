<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Config\Environment;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\ConfigurationException;

function gatewayConfig(array $overrides = []): GatewayConfig
{
    return GatewayConfig::fromArray(array_merge([
        'merchant_id' => 'TEST123',
        'api_password' => 'secret',
        'order_prefix' => 'ISIMS',
    ], $overrides));
}

it('applies the decided defaults', function () {
    $config = gatewayConfig();

    expect($config->environment)->toBe(Environment::Test)
        ->and($config->host)->toBe('test-mib.mtf.gateway.mastercard.com')
        ->and($config->apiVersion)->toBe(100)
        ->and($config->timeout)->toBe(30)
        ->and($config->currencies)->toBe([Currency::Mvr])
        ->and($config->defaultCurrency)->toBe(Currency::Mvr)
        ->and($config->approvalPolicy->acceptApprovedAuto)->toBeFalse()
        ->and($config->allowInsecureUrls)->toBeFalse()
        ->and($config->baseUrl())->toBe('https://test-mib.mtf.gateway.mastercard.com/api/rest/version/100/merchant/TEST123/');
});

it('requires an explicit host in production', function () {
    gatewayConfig(['environment' => 'production']);
})->throws(ConfigurationException::class, 'no preset host');

it('accepts an explicit production host and normalizes it', function () {
    $config = gatewayConfig(['environment' => 'production', 'host' => 'https://Gateway.Example.com/']);

    expect($config->environment)->toBe(Environment::Production)
        ->and($config->host)->toBe('gateway.example.com');
});

it('lets an explicit host override the test preset', function () {
    expect(gatewayConfig(['host' => 'other.example.com'])->host)->toBe('other.example.com');
});

it('requires the order prefix with no default', function (mixed $prefix) {
    gatewayConfig(['order_prefix' => $prefix]);
})->with([[null], [''], ['ISIMS-1'], ['WAYTOOLONGPREFIX'], ['bad prefix']])
    ->throws(ConfigurationException::class, 'MIB_ORDER_PREFIX');

it('rejects invalid configuration', function (array $overrides, string $message) {
    expect(fn () => gatewayConfig($overrides))->toThrow(ConfigurationException::class, $message);
})->with([
    'missing merchant' => [['merchant_id' => ''], 'Merchant ID'],
    'bad merchant' => [['merchant_id' => 'has space'], 'Merchant ID'],
    'missing password' => [['api_password' => ''], 'API password'],
    'http host' => [['host' => 'http://insecure.example.com'], 'HTTPS'],
    'bad host' => [['host' => 'not a host'], 'Invalid gateway host'],
    'bad environment' => [['environment' => 'staging'], 'Unknown environment'],
    'zero version' => [['api_version' => 0], 'API version'],
    'zero timeout' => [['timeout' => '0'], 'Timeout'],
    'non-numeric timeout' => [['timeout' => 'abc'], 'timeout must be an integer'],
    'unknown currency' => [['currencies' => 'MVR,XYZ'], 'Unsupported currency'],
    'default not allowed' => [['currencies' => ['USD']], 'not in the currency allow-list'],
    'bad bool' => [['allow_insecure_urls' => 'maybe'], 'boolean'],
]);

it('parses the currency allow-list from a comma-separated string or a list', function () {
    $fromString = gatewayConfig(['currencies' => 'MVR, usd', 'currency' => 'USD']);
    $fromList = gatewayConfig(['currencies' => ['MVR', Currency::Usd, 'MVR']]);

    expect($fromString->currencies)->toBe([Currency::Mvr, Currency::Usd])
        ->and($fromString->defaultCurrency)->toBe(Currency::Usd)
        ->and($fromString->allowsCurrency(Currency::Usd))->toBeTrue()
        ->and($fromList->currencies)->toBe([Currency::Mvr, Currency::Usd])
        ->and(gatewayConfig()->allowsCurrency(Currency::Usd))->toBeFalse();
});

it('reads the approval opt-in and boolean strings', function () {
    $config = gatewayConfig(['approval' => ['accept_approved_auto' => 'true'], 'allow_insecure_urls' => '1', 'api_version' => '100']);

    expect($config->approvalPolicy->acceptApprovedAuto)->toBeTrue()
        ->and($config->allowInsecureUrls)->toBeTrue();
});

it('can be built with named arguments', function () {
    $config = new GatewayConfig(merchantId: 'TEST123', apiPassword: 'secret', orderPrefix: 'ISIMS', apiVersion: 85);

    expect($config->baseUrl())->toContain('/version/85/');
});

it('does not leak the API password in debug output', function () {
    $dump = print_r(gatewayConfig(['api_password' => 'super-secret-value']), true);

    expect($dump)->not->toContain('super-secret-value')->toContain('********');
});

it('has a preset only for the test environment', function () {
    expect(Environment::Test->presetHost())->toBe('test-mib.mtf.gateway.mastercard.com')
        ->and(Environment::Production->presetHost())->toBeNull()
        ->and(Environment::fromConfig(' Production '))->toBe(Environment::Production);
});
