<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\ConnectException;
use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Http\RetryPolicy;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Responses\OrderResponse;
use IbnNajjaar\MIBGlobalPay\Tests\Support\GatewayHarness;
use IbnNajjaar\MIBGlobalPay\Core\Requests\CheckoutRequest;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\TransportException;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\RetrieveOrder;

function connectError(): ConnectException
{
    return new ConnectException('Connection timed out', new Request('GET', 'https://example.test'));
}

function checkoutRequest(): CheckoutRequest
{
    return CheckoutRequest::create('ORDER-1', Money::of('10.00', Currency::Mvr), 'IUM', 'https://example.mv/return')
        ->withNotificationUrl('https://example.mv/webhook');
}

it('implements the Gateway contract', function () {
    expect((new GatewayHarness([]))->gateway)->toBeInstanceOf(Gateway::class);
});

it('sends merchant operations to the merchant base URL with basic auth', function () {
    $h = new GatewayHarness([GatewayHarness::fixture(200, 'order_captured.json')]);

    $order = $h->gateway->retrieveOrder('ISIMS-R-IUM-FN-2026-123-1');

    $request = $h->request();
    expect($order)->toBeInstanceOf(OrderResponse::class)
        ->and($request->getMethod())->toBe('GET')
        ->and((string) $request->getUri())->toBe('https://test-mib.mtf.gateway.mastercard.com/api/rest/version/100/merchant/TEST123/order/ISIMS-R-IUM-FN-2026-123-1')
        ->and($request->getHeaderLine('Authorization'))->toBe('Basic ' . base64_encode('merchant.TEST123:super-secret-password'))
        ->and($request->getHeaderLine('Accept'))->toBe('application/json')
        ->and((string) $request->getBody())->toBe('');
});

it('URL-encodes identifiers in paths', function () {
    $h = new GatewayHarness([GatewayHarness::fixture(200, 'order_captured.json')]);

    $h->gateway->retrieveOrder('INV/2026#1 ?');

    expect($h->request()->getUri()->getPath())->toEndWith('/order/INV%2F2026%231%20%3F');
});

it('sends Check Gateway without the merchant path or authentication', function () {
    $h = new GatewayHarness([GatewayHarness::fixture(200, 'check_gateway.json')]);

    $status = $h->gateway->checkGateway();

    expect($status->isOperating())->toBeTrue()
        ->and((string) $h->request()->getUri())->toBe('https://test-mib.mtf.gateway.mastercard.com/api/rest/version/100/information')
        ->and($h->request()->hasHeader('Authorization'))->toBeFalse();
});

it('sends JSON bodies with a content type', function () {
    $h = new GatewayHarness([GatewayHarness::fixture(201, 'checkout_session.json')]);

    $h->gateway->initiateCheckout(checkoutRequest());

    expect($h->request()->getMethod())->toBe('POST')
        ->and($h->request()->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($h->requestBody()['order']['amount'])->toBe('10.00');
});

it('sends an empty JSON object, not an empty array, for an empty body', function () {
    $h = new GatewayHarness([GatewayHarness::fixture(200, 'payment_options.json')]);

    $h->gateway->paymentOptionsInquiry();

    expect((string) $h->request()->getBody())->toBe('{}');
});

it('validates before sending anything', function () {
    $h = new GatewayHarness([]);

    expect(fn () => $h->gateway->capture('ORDER-1', 'capture-1', Money::of('0', Currency::Mvr)))->toThrow(InvalidRequest::class)
        ->and($h->history)->toBe([]);
});

it('throws GatewayException with the gateway error for HTTP 4xx', function () {
    $h = new GatewayHarness([GatewayHarness::fixture(400, 'error_invalid_request.json')]);

    try {
        $h->gateway->retrieveOrder('ORDER-1');
        $this->fail('Expected GatewayException');
    } catch (GatewayException $e) {
        expect($e->httpStatus())->toBe(400)
            ->and($e->getMessage())->toContain('Amount must be greater than zero')
            ->and($e->cause())->toBe('INVALID_REQUEST')
            ->and($e->field())->toBe('order.amount')
            ->and($e->rawBody())->toContain('INVALID_REQUEST');
    }
});

it('uses the HTTP status as the message when there is no error body', function () {
    $h = new GatewayHarness([GatewayHarness::json(502, '<html>Bad gateway</html>')]);

    expect(fn () => $h->gateway->retrieveOrder('ORDER-1'))->toThrow(GatewayException::class, 'HTTP 502');
});

it('throws TransportException when there is no response', function () {
    $h = new GatewayHarness([connectError()]);

    expect(fn () => $h->gateway->retrieveOrder('ORDER-1'))->toThrow(TransportException::class, 'Connection timed out');
});

it('throws MalformedPayload for a non-JSON success body', function () {
    $h = new GatewayHarness([GatewayHarness::json(200, 'not json')]);

    expect(fn () => $h->gateway->retrieveOrder('ORDER-1'))->toThrow(MalformedPayload::class);
});

it('can send any operation through send()', function () {
    $h = new GatewayHarness([GatewayHarness::fixture(200, 'order_captured.json')]);

    expect($h->gateway->send(new RetrieveOrder('X'))->totalCaptured->toDecimal())->toBe('1250.50');
});

describe('retries', function () {
    it('are off by default', function () {
        $h = new GatewayHarness([GatewayHarness::json(503, ''), GatewayHarness::fixture(200, 'order_captured.json')]);

        expect(fn () => $h->gateway->retrieveOrder('ORDER-1'))->toThrow(GatewayException::class)
            ->and($h->history)->toHaveCount(1);
    });

    it('retry idempotent operations on 5xx and transport errors, then succeed', function () {
        $h = new GatewayHarness(
            [GatewayHarness::json(503, ''), connectError(), GatewayHarness::fixture(200, 'order_captured.json')],
            retry: new RetryPolicy(maxRetries: 2, delayMilliseconds: 250),
        );

        $order = $h->gateway->retrieveOrder('ORDER-1');

        expect($order->id)->toBe('ISIMS-R-IUM-FN-2026-123-1')
            ->and($h->history)->toHaveCount(3)
            ->and($h->sleeps)->toBe([250, 250]);
    });

    it('resend PUT transaction operations with the identical request', function () {
        $h = new GatewayHarness(
            [connectError(), GatewayHarness::fixture(200, 'transaction_capture_success.json')],
            retry: new RetryPolicy(1, 0),
        );

        $h->gateway->capture('ORDER-AUTH-1', 'capture-1', Money::of('40', Currency::Mvr));

        expect($h->history)->toHaveCount(2)
            ->and((string) $h->request(1)->getUri())->toBe((string) $h->request(0)->getUri())
            ->and((string) $h->request(1)->getBody())->toBe((string) $h->request(0)->getBody());
    });

    it('stop after maxRetries', function () {
        $h = new GatewayHarness([connectError(), connectError(), connectError()], retry: new RetryPolicy(2, 0));

        expect(fn () => $h->gateway->retrieveOrder('ORDER-1'))->toThrow(TransportException::class)
            ->and($h->history)->toHaveCount(3);
    });

    it('never retry Initiate Checkout', function () {
        $h = new GatewayHarness([connectError(), GatewayHarness::fixture(201, 'checkout_session.json')], retry: new RetryPolicy(3, 0));

        expect(fn () => $h->gateway->initiateCheckout(checkoutRequest()))->toThrow(TransportException::class)
            ->and($h->history)->toHaveCount(1);
    });

    it('never retry 4xx', function () {
        $h = new GatewayHarness([GatewayHarness::fixture(400, 'error_invalid_request.json')], retry: new RetryPolicy(3, 0));

        expect(fn () => $h->gateway->retrieveOrder('ORDER-1'))->toThrow(GatewayException::class)
            ->and($h->history)->toHaveCount(1);
    });

    it('offer the gateway-recommended policy of one retry after 60 seconds', function () {
        $policy = RetryPolicy::gatewayRecommended();

        expect($policy->maxRetries)->toBe(1)->and($policy->delayMilliseconds)->toBe(60_000);
    });
});

describe('logging', function () {
    it('logs requests and responses without secrets', function () {
        $h = new GatewayHarness([GatewayHarness::fixture(201, 'checkout_session.json')]);

        $h->gateway->initiateCheckout(checkoutRequest());

        $dump = $h->logger->dump();
        expect($h->logger->records)->toHaveCount(2)
            ->and($h->logger->records[0]['message'])->toBe('MIB request')
            ->and($h->logger->records[1]['context']['status'])->toBe(201)
            ->and($dump)->not->toContain('super-secret-password')
            ->and($dump)->not->toContain(base64_encode('merchant.TEST123:super-secret-password'))
            ->and($dump)->not->toContain('f8e1b1e3c2d54a7b')
            ->and($dump)->toContain('[REDACTED]')
            ->and($dump)->toContain('SESSION0002899787259G30902270H6');
    });

    it('logs failures and retries as warnings', function () {
        $h = new GatewayHarness([connectError(), GatewayHarness::fixture(200, 'order_captured.json')], retry: new RetryPolicy(1, 0));

        $h->gateway->retrieveOrder('ORDER-1');

        $levels = array_column($h->logger->records, 'level');
        expect($levels)->toContain('warning')->toContain('info');
    });
});
