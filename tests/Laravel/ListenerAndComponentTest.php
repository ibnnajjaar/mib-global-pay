<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Blade;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\Source;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Settlement;
use IbnNajjaar\MIBGlobalPay\Tests\Support\ArrayLogger;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableState;
use IbnNajjaar\MIBGlobalPay\Core\Support\TransactionId;
use IbnNajjaar\MIBGlobalPay\Laravel\Jobs\ReverseCapture;
use IbnNajjaar\MIBGlobalPay\Tests\Support\GatewayHarness;
use IbnNajjaar\MIBGlobalPay\Laravel\Outcome\OutcomeProcessor;

function doubleCapture(): void
{
    $payable = hostPayable(PayableState::Paid, settlement: new Settlement('ISIMS-FIRST-1', '1', frozenAmount(), new DateTimeImmutable()));
    $resolved = bindResolver($payable)->resolveAttempt(LARAVEL_ORDER);
    fakeGatewayResponses([GatewayHarness::json(200, orderJson())]);

    app(OutcomeProcessor::class)->process($resolved, Source::Webhook);
}

it('does not auto-reverse a double capture by default', function () {
    Bus::fake([ReverseCapture::class]);

    doubleCapture();

    Bus::assertNotDispatched(ReverseCapture::class);
});

it('queues a reversal of the second capture when opted in', function () {
    config()->set('mib-global-pay.double_capture.auto_reverse', true);
    Bus::fake([ReverseCapture::class]);

    doubleCapture();

    Bus::assertDispatched(ReverseCapture::class, fn (ReverseCapture $job) => $job->gatewayOrderId === LARAVEL_ORDER);
});

it('reverses with void, falling back to refund', function () {
    $harness = fakeGatewayResponses([
        GatewayHarness::json(200, orderJson()),
        GatewayHarness::json(200, ['result' => 'FAILURE', 'response' => ['gatewayCode' => 'DECLINED'], 'transaction' => ['type' => 'VOID_PAYMENT']]),
        GatewayHarness::json(200, ['result' => 'SUCCESS', 'response' => ['gatewayCode' => 'APPROVED'], 'transaction' => ['type' => 'REFUND']]),
    ]);

    $job = new ReverseCapture(LARAVEL_ORDER);
    app()->call([$job, 'handle']);

    expect($harness->history)->toHaveCount(3)
        ->and($harness->requestBody(1)['apiOperation'])->toBe('VOID')
        ->and($harness->requestBody(2)['apiOperation'])->toBe('REFUND')
        ->and($harness->request(1)->getUri()->getPath())->toEndWith('/transaction/' . TransactionId::derive('void', LARAVEL_ORDER, '1'))
        ->and($harness->request(2)->getUri()->getPath())->toEndWith('/transaction/' . TransactionId::derive('refund', LARAVEL_ORDER, '1'));
});

it('logs a pending void as unconfirmed and not reversed, without refunding', function () {
    $harness = fakeGatewayResponses([
        GatewayHarness::json(200, orderJson()),
        GatewayHarness::json(200, ['result' => 'PENDING', 'response' => ['gatewayCode' => 'PENDING'], 'transaction' => ['id' => 'void-1', 'type' => 'VOID_PAYMENT']]),
    ]);
    $logger = new ArrayLogger();

    (new ReverseCapture(LARAVEL_ORDER))->withFakeQueueInteractions()->handle($harness->gateway, $logger);

    expect($harness->history)->toHaveCount(2)
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('warning')
        ->and($logger->records[0]['context'])->toMatchArray(['method' => 'unconfirmed', 'reversed' => false, 'transaction' => 'void-1']);
});

it('renders the checkout redirect component', function () {
    $html = Blade::render('<x-mib-global-pay::checkout :session="$id" />', ['id' => 'SESSION0002899787259G30902270H6']);

    expect($html)->toContain('https://test-mib.mtf.gateway.mastercard.com/static/checkout/checkout.min.js')
        ->toContain("id: 'SESSION0002899787259G30902270H6'")
        ->toContain('Checkout.showPaymentPage()');
});

it('escapes the session ID and honours MIB_CHECKOUT_JS_URL', function () {
    config()->set('mib-global-pay.checkout_js_url', 'https://cdn.example.test/checkout.js');

    $html = Blade::render('<x-mib-global-pay::checkout :session="$id" />', ['id' => '</script><script>alert(1)']);

    expect($html)->toContain('https://cdn.example.test/checkout.js')
        ->not->toContain('</script><script>alert(1)');
});
