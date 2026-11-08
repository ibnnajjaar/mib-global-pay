<?php

declare(strict_types=1);

use IbnNajjaar\MIBGlobalPay\Core\Checkout\CheckoutJs;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Checkout\CheckoutDisplay;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

function checkoutJsConfig(): GatewayConfig
{
    return GatewayConfig::fromArray(['merchant_id' => 'TEST123', 'api_password' => 'x', 'order_prefix' => 'ISIMS']);
}

it('renders a whole page that starts the Payment Page', function () {
    $html = CheckoutJs::for(checkoutJsConfig(), 'SESSION1')->page();

    expect($html)->toContain('<!doctype html>')
        ->toContain('https://test-mib.mtf.gateway.mastercard.com/static/checkout/checkout.min.js')
        ->toContain('data-error="mibGlobalPayError"')
        ->toContain('id:"SESSION1"')
        ->toContain('Checkout.showPaymentPage();');
});

it('renders each display', function (CheckoutDisplay $display, string $expected) {
    expect(CheckoutJs::for(checkoutJsConfig(), 'S', $display)->scripts())->toContain($expected);
})->with([
    'payment' => [CheckoutDisplay::Payment, 'Checkout.showPaymentPage()'],
    'embedded' => [CheckoutDisplay::Embedded, 'Checkout.showEmbeddedPage("#"+CSS.escape("mib-global-pay-checkout"));'],
    'modal' => [CheckoutDisplay::Modal, 'Checkout.showEmbeddedPage("#"+CSS.escape("mib-global-pay-checkout"),openDialog);'],
]);

it('adds a dialog and reopen button for a modal', function () {
    $html = CheckoutJs::for(checkoutJsConfig(), 'S', CheckoutDisplay::Modal, targetId: 'pay')->markup();

    expect($html)->toContain('<button type="button" id="pay-open">')
        ->toContain('<dialog id="pay-dialog"><div id="pay">');
});

it('passes named callbacks to Checkout.js', function () {
    $html = CheckoutJs::for(checkoutJsConfig(), 'S', callbacks: ['error' => 'onError', 'cancel' => 'onCancel', 'beforeRedirect' => 'Checkout.saveFormFields'])->scripts();

    expect($html)->toContain('data-error="onError"')
        ->toContain('data-cancel="onCancel"')
        ->toContain('data-beforeRedirect="Checkout.saveFormFields"');
});

it('cannot be broken out of with a hostile session ID or message', function () {
    $html = (new CheckoutJs('</script><script>alert(1)', 'https://x.mv/c.js', errorMessage: '</script>"'))->page();

    expect($html)->not->toContain('</script><script>alert(1)')
        ->not->toContain('textContent="</script>');
});

it('refuses a bad setup', function (Closure $build, string $message) {
    expect($build)->toThrow(InvalidRequest::class, $message);
})->with([
    'empty session' => [fn () => new CheckoutJs('', 'https://x.mv/c.js'), 'Session ID'],
    'unknown callback' => [fn () => new CheckoutJs('S', 'https://x.mv/c.js', callbacks: ['nope' => 'f']), 'Unknown Checkout.js callback'],
    'not a function name' => [fn () => new CheckoutJs('S', 'https://x.mv/c.js', callbacks: ['error' => 'alert(1)']), 'global JavaScript function'],
    'cancel on the Embedded Page' => [fn () => new CheckoutJs('S', 'https://x.mv/c.js', CheckoutDisplay::Embedded, callbacks: ['cancel' => 'f']), 'Payment Page only'],
    'cancel in a modal' => [fn () => new CheckoutJs('S', 'https://x.mv/c.js', CheckoutDisplay::Modal, callbacks: ['cancel' => 'f']), 'Payment Page only'],
]);
