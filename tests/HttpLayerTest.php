<?php

namespace IbnNajjaar\MIBGlobalPay\Tests;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Exception\ConnectException;
use IbnNajjaar\MIBGlobalPay\MIBGlobalPayConnector;
use IbnNajjaar\MIBGlobalPay\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Requests\DataObjects\OrderData;
use IbnNajjaar\MIBGlobalPay\Tests\Support\MockClientFactory;
use IbnNajjaar\MIBGlobalPay\Exceptions\MIBGlobalPayException;
use IbnNajjaar\MIBGlobalPay\Support\Response as GatewayResponse;
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\CheckoutSessionResponseData;

class HttpLayerTest extends TestCase
{
    private function connector(array $responses, &$history = null): MIBGlobalPayConnector
    {
        $connector = new MIBGlobalPayConnector('host', 'M', 'secret');
        $connector->setHttpClient(MockClientFactory::make($responses, $history));

        return $connector;
    }

    private function order(): OrderData
    {
        return OrderData::make('ORDER-1', 100.50, 'MVR');
    }

    public function test_create_session_goes_through_injected_client()
    {
        $connector = $this->connector([
            MockClientFactory::json(201, [
                'result' => 'SUCCESS',
                'session' => ['id' => 'SESSION0001'],
                'successIndicator' => 'abc123',
            ]),
        ], $history);

        $response = $connector->createTransaction($this->order());

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://host/api/rest/version/100/merchant/M/session', (string) $request->getUri());
        $this->assertSame('Basic ' . base64_encode('merchant.M:secret'), $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('INITIATE_CHECKOUT', $body['apiOperation']);

        $this->assertSame('SUCCESS', $response->getResult());
        $dto = $response->toDto();
        $this->assertInstanceOf(CheckoutSessionResponseData::class, $dto);
        $this->assertSame('SESSION0001', $dto->getSessionId());
    }

    public function test_timeout_is_sent_per_request()
    {
        $connector = $this->connector([MockClientFactory::json(200, [])], $history);
        $connector->setTimeout(5);
        $connector->getOrderDetails('ORDER-1');

        $this->assertSame(5, $history[0]['options']['timeout']);
    }

    public function test_gateway_error_is_surfaced()
    {
        $connector = $this->connector([
            MockClientFactory::json(400, [
                'result' => 'ERROR',
                'error' => [
                    'cause' => 'INVALID_REQUEST',
                    'explanation' => 'Value \'abc\' is invalid. Length is 3 characters, but must be at least 4',
                    'field' => 'order.id',
                    'validationType' => 'INVALID',
                    'supportCode' => '1234',
                ],
            ]),
        ]);

        try {
            $connector->createTransaction($this->order());
            $this->fail('Expected GatewayException');
        } catch (GatewayException $e) {
            $this->assertInstanceOf(MIBGlobalPayException::class, $e);
            $this->assertSame(400, $e->getCode());
            $this->assertSame(400, $e->getHttpStatus());
            $this->assertSame('INVALID_REQUEST', $e->getErrorCause());
            $this->assertStringContainsString('must be at least 4', $e->getErrorExplanation());
            $this->assertSame($e->getErrorExplanation(), $e->getMessage());
            $this->assertSame('order.id', $e->getErrorField());
            $this->assertSame('INVALID', $e->getValidationType());
            $this->assertSame('1234', $e->getSupportCode());
            $this->assertTrue($e->isClientError());
            $this->assertFalse($e->isServerError());
            $this->assertFalse($e->isAuthenticationError());
            $this->assertNotNull($e->getPrevious());
        }
    }

    public function test_authentication_error()
    {
        $connector = $this->connector([
            MockClientFactory::json(401, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST']]),
        ]);

        try {
            $connector->getOrderDetails('ORDER-1');
            $this->fail('Expected GatewayException');
        } catch (GatewayException $e) {
            $this->assertTrue($e->isAuthenticationError());
            $this->assertSame('INVALID_REQUEST', $e->getMessage());
        }
    }

    public function test_server_error_with_non_json_body()
    {
        $connector = $this->connector([new Response(500, [], '<html>oops</html>')]);

        try {
            $connector->getOrderDetails('ORDER-1');
            $this->fail('Expected GatewayException');
        } catch (GatewayException $e) {
            $this->assertSame('HTTP 500', $e->getMessage());
            $this->assertTrue($e->isServerError());
            $this->assertNull($e->getErrorCause());
            $this->assertSame('<html>oops</html>', $e->getRawBody());
        }
    }

    public function test_connection_error_throws_plain_exception()
    {
        $connector = $this->connector([
            new ConnectException('Connection refused', new Request('GET', 'https://host')),
        ]);

        try {
            $connector->getOrderDetails('ORDER-1');
            $this->fail('Expected MIBGlobalPayException');
        } catch (MIBGlobalPayException $e) {
            $this->assertNotInstanceOf(GatewayException::class, $e);
            $this->assertStringStartsWith('HTTP request failed: ', $e->getMessage());
        }
    }

    public function test_response_body_can_be_read_twice()
    {
        $response = new GatewayResponse(new Response(200, [], '{"result":"SUCCESS"}'), null);

        $this->assertSame('{"result":"SUCCESS"}', $response->getBody());
        $this->assertSame('{"result":"SUCCESS"}', $response->getBody());
        $this->assertSame(['result' => 'SUCCESS'], $response->toArray());
    }

    public function test_to_array_with_scalar_json_returns_empty_array()
    {
        $response = new GatewayResponse(new Response(200, [], '"hello"'), null);

        $this->assertSame([], $response->toArray());
        $this->assertNull($response->getResult());
    }

    public function test_to_dto_without_response_class_throws()
    {
        $response = new GatewayResponse(new Response(200, [], '{}'), null);

        $this->expectException(MIBGlobalPayException::class);
        $this->expectExceptionMessage('This request has no response DTO');
        $response->toDto();
    }

    public function test_response_throw_raises_gateway_exception()
    {
        $response = new GatewayResponse(
            MockClientFactory::json(404, ['error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Unable to find order']]),
            null
        );

        try {
            $response->throw();
            $this->fail('Expected GatewayException');
        } catch (GatewayException $e) {
            $this->assertSame('Unable to find order', $e->getMessage());
            $this->assertSame(404, $e->getCode());
        }
    }
}
