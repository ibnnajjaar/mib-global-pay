<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Requests\CheckoutRequest;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Core\Requests\TransactionSource;
use IbnNajjaar\MIBGlobalPay\Core\Requests\ThreeDSecureAction;
use IbnNajjaar\MIBGlobalPay\Core\Requests\InteractionOperation;
use IbnNajjaar\MIBGlobalPay\Core\Requests\CardSecurityCodeDisplay;

function checkoutConfig(array $overrides = []): GatewayConfig
{
    return GatewayConfig::fromArray($overrides + ['merchant_id' => 'TEST123', 'api_password' => 'x', 'order_prefix' => 'ISIMS']);
}

function minimalCheckout(): CheckoutRequest
{
    return CheckoutRequest::create('ISIMS-R-IUM-FN-2026-123-1', Money::of('1250.50', Currency::Mvr), 'Islamic University of Maldives', 'https://students.ium.edu.mv/payments/return');
}

it('builds the minimal Initiate Checkout body', function () {
    expect(minimalCheckout()->withNotificationUrl('https://students.ium.edu.mv/payment-gateways/mib/webhook')->toBody(checkoutConfig()))->toBe([
        'apiOperation' => 'INITIATE_CHECKOUT',
        'interaction' => [
            'operation' => 'PURCHASE',
            'merchant' => ['name' => 'Islamic University of Maldives'],
            'returnUrl' => 'https://students.ium.edu.mv/payments/return',
        ],
        'order' => [
            'id' => 'ISIMS-R-IUM-FN-2026-123-1',
            'amount' => '1250.50',
            'currency' => 'MVR',
            'notificationUrl' => 'https://students.ium.edu.mv/payment-gateways/mib/webhook',
        ],
    ]);
});

it('sends order.reference unchanged, including characters order IDs avoid', function () {
    $body = minimalCheckout()->withReference('(R)IUM/FN/2026/123')->withoutWebhook()->toBody(checkoutConfig());

    expect($body['order']['reference'])->toBe('(R)IUM/FN/2026/123');
});

it('sets every supported option', function () {
    $body = minimalCheckout()
        ->withOperation(InteractionOperation::Authorize)
        ->withDescription('Semester fees')
        ->withReference('(R)IUM/FN/2026/123')
        ->withNotificationUrl('https://example.mv/webhook')
        ->withCustomField('studentId', 'S123')
        ->withCancelUrl('https://example.mv/cancel')
        ->withTimeout(1800, 'https://example.mv/timeout')
        ->withRetryRedirect('https://example.mv/failed', 3)
        ->withMerchantDetails(url: 'https://ium.edu.mv', email: 'fees@ium.edu.mv', phone: '3001234', logoUrl: 'https://ium.edu.mv/logo.png', addressLine1: 'Male')
        ->withLocale('en_US')
        ->withStyle('#1A2B3C', 'default')
        ->withCardSecurityCode(CardSecurityCodeDisplay::Mandatory)
        ->withThreeDSecure(ThreeDSecureAction::UseGatewayRecommendation)
        ->withTransactionSource(TransactionSource::Internet)
        ->withTransactionReference('TXN-1')
        ->toBody(checkoutConfig());

    expect($body['interaction'])->toBe([
        'operation' => 'AUTHORIZE',
        'merchant' => [
            'name' => 'Islamic University of Maldives',
            'url' => 'https://ium.edu.mv',
            'email' => 'fees@ium.edu.mv',
            'phone' => '3001234',
            'logo' => 'https://ium.edu.mv/logo.png',
            'address' => ['line1' => 'Male'],
        ],
        'returnUrl' => 'https://students.ium.edu.mv/payments/return',
        'cancelUrl' => 'https://example.mv/cancel',
        'timeout' => 1800,
        'timeoutUrl' => 'https://example.mv/timeout',
        'redirectMerchantUrl' => 'https://example.mv/failed',
        'retryAttemptCount' => 3,
        'locale' => 'en_US',
        'style' => ['accentColor' => '#1A2B3C', 'theme' => 'default'],
        'displayControl' => ['cardSecurityCode' => 'MANDATORY'],
        'action' => ['3DSecure' => 'USE_GATEWAY_RECOMMENDATION'],
    ])->and($body['order'])->toBe([
        'id' => 'ISIMS-R-IUM-FN-2026-123-1',
        'amount' => '1250.50',
        'currency' => 'MVR',
        'description' => 'Semester fees',
        'reference' => '(R)IUM/FN/2026/123',
        'notificationUrl' => 'https://example.mv/webhook',
        'custom' => ['studentId' => 'S123'],
    ])->and($body['transaction'])->toBe(['reference' => 'TXN-1', 'source' => 'INTERNET']);
});

it('is immutable', function () {
    $base = minimalCheckout();
    $changed = $base->withDescription('Changed');

    expect($changed)->not->toBe($base)
        ->and(fn () => $base->withoutWebhook()->toBody(checkoutConfig()))->not->toThrow(InvalidRequest::class)
        ->and($base->withoutWebhook()->toBody(checkoutConfig())['order'])->not->toHaveKey('description');
});

it('requires a notification URL unless withoutWebhook() is called', function () {
    minimalCheckout()->toBody(checkoutConfig());
})->throws(InvalidRequest::class, 'withoutWebhook()');

it('lets withNotificationUrl() undo withoutWebhook() and vice versa', function () {
    $body = minimalCheckout()->withoutWebhook()->withNotificationUrl('https://example.mv/hook')->toBody(checkoutConfig());
    $none = minimalCheckout()->withNotificationUrl('https://example.mv/hook')->withoutWebhook()->toBody(checkoutConfig());

    expect($body['order']['notificationUrl'])->toBe('https://example.mv/hook')
        ->and($none['order'])->not->toHaveKey('notificationUrl');
});

it('deep-merges extra fields last and removes fields set to null', function () {
    $body = minimalCheckout()->withoutWebhook()->withExtraFields([
        'order' => ['customerNote' => 'Hi', 'currency' => null],
        'interaction' => ['displayControl' => ['billingAddress' => 'HIDE']],
    ])->toBody(checkoutConfig());

    expect($body['order']['customerNote'])->toBe('Hi')
        ->and($body['order'])->not->toHaveKey('currency')
        ->and($body['interaction']['displayControl'])->toBe(['billingAddress' => 'HIDE']);
});

it('enforces HTTPS unless insecure URLs are allowed', function () {
    $request = CheckoutRequest::create('O-1', Money::of('1', Currency::Mvr), 'M', 'http://localhost/return')->withoutWebhook();

    expect(fn () => $request->toBody(checkoutConfig()))->toThrow(InvalidRequest::class, 'HTTPS')
        ->and($request->toBody(checkoutConfig(['allow_insecure_urls' => true]))['interaction']['returnUrl'])->toBe('http://localhost/return');
});

it('enforces the currency allow-list at send time', function () {
    $request = CheckoutRequest::create('O-1', Money::of('1', Currency::Usd), 'M', 'https://x.mv/r')->withoutWebhook();

    expect(fn () => $request->toBody(checkoutConfig()))->toThrow(InvalidRequest::class, 'allow-list')
        ->and($request->toBody(checkoutConfig(['currencies' => 'MVR,USD']))['order']['currency'])->toBe('USD');
});

it('rejects invalid input', function (Closure $build) {
    $build();
})->with([
    'zero amount' => [fn () => CheckoutRequest::create('O', Money::of('0', Currency::Mvr), 'M', 'https://x.mv')],
    'negative amount' => [fn () => CheckoutRequest::create('O', Money::of('-1', Currency::Mvr), 'M', 'https://x.mv')],
    'empty order id' => [fn () => CheckoutRequest::create('', Money::of('1', Currency::Mvr), 'M', 'https://x.mv')],
    'long order id' => [fn () => CheckoutRequest::create(str_repeat('a', 41), Money::of('1', Currency::Mvr), 'M', 'https://x.mv')],
    'control char in order id' => [fn () => CheckoutRequest::create("O\n1", Money::of('1', Currency::Mvr), 'M', 'https://x.mv')],
    'long merchant name' => [fn () => CheckoutRequest::create('O', Money::of('1', Currency::Mvr), str_repeat('m', 41), 'https://x.mv')],
    'bad return url' => [fn () => CheckoutRequest::create('O', Money::of('1', Currency::Mvr), 'M', 'javascript:alert(1)')],
    'relative return url' => [fn () => CheckoutRequest::create('O', Money::of('1', Currency::Mvr), 'M', '/return')],
    'long description' => [fn () => minimalCheckout()->withDescription(str_repeat('d', 128))],
    'long reference' => [fn () => minimalCheckout()->withReference(str_repeat('r', 41))],
    'bad custom key' => [fn () => minimalCheckout()->withCustomField('student-id', 'x')],
    'long custom value' => [fn () => minimalCheckout()->withCustomField('k', str_repeat('v', 251))],
    'short timeout' => [fn () => minimalCheckout()->withTimeout(599)],
    'long timeout' => [fn () => minimalCheckout()->withTimeout(1801)],
    'retry count' => [fn () => minimalCheckout()->withRetryRedirect('https://x.mv', 4)],
    'bad locale' => [fn () => minimalCheckout()->withLocale('english')],
    'bad accent' => [fn () => minimalCheckout()->withStyle('red')],
    'bad email' => [fn () => minimalCheckout()->withMerchantDetails(email: 'nope')],
])->throws(InvalidRequest::class);

it('limits custom fields to 50', function () {
    $request = minimalCheckout();
    for ($i = 0; $i < 50; $i++) {
        $request = $request->withCustomField("k{$i}", 'v');
    }

    expect(fn () => $request->withCustomField('k0', 'replaced'))->not->toThrow(InvalidRequest::class);

    $request->withCustomField('k50', 'v');
})->throws(InvalidRequest::class, '50');
