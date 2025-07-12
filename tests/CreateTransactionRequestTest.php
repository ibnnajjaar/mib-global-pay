<?php

namespace IbnNajjaar\MIBGlobalPay\Tests;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use IbnNajjaar\MIBGlobalPay\MIBGlobalPayConnector;
use IbnNajjaar\MIBGlobalPay\Contracts\IsResponseData;
use IbnNajjaar\MIBGlobalPay\Requests\DataObjects\OrderData;
use IbnNajjaar\MIBGlobalPay\Requests\CreateTransactionRequest;
use IbnNajjaar\MIBGlobalPay\Requests\DataObjects\BaseOrderData;
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\CheckoutSessionResponseData;

class CreateTransactionRequestTest extends TestCase
{
    private function getMockOrderData(): BaseOrderData
    {
        return OrderData::make('order123', 100.50)
                        ->setOrderCurrency('USD')
                        ->setOrderDescription('Test Order')
                        ->setWebHookUrl('https://webhook.url')
                        ->setCancelUrl('https://cancel.url')
                        ->setMerchantAddressLine1('123 Main St')
                        ->setMerchantEmail('merchant@example.mv')
                        ->setMerchantLogoUrl('https://logo.url')
                        ->setMerchantName('Merchant Name')
                        ->setMerchantPhone('1234567890')
                        ->setMerchantUrl('https://merchant.url')
                        ->setRedirectMerchantUrl('https://redirect.url')
                        ->setRetryAttemptCount(3)
                        ->setReturnUrl('https://return.url');
    }

    public function test_initiate_transaction_endpoint()
    {
        $request = new CreateTransactionRequest($this->getMockOrderData());
        $this->assertSame('session', $request->resolveEndpoint());
    }

    public function test_default_initiate_transaction_request_data_is_valid()
    {
        $request = new CreateTransactionRequest($this->getMockOrderData());
        $data = $request->defaultData();

        $this->assertArrayHasKey('apiOperation', $data);
        $this->assertArrayHasKey('order', $data);
        $this->assertArrayHasKey('interaction', $data);
        $this->assertEquals($this->getExpectedRequestData(), $data);
    }

    public function test_initiate_transaction_http_method_is_post()
    {
        $request = new CreateTransactionRequest($this->getMockOrderData());
        $this->assertSame('POST', $request->getMethod());
    }

    public function test_user_can_send_extra_query_parameters_with_the_initiate_transaction_request()
    {
        $request = new CreateTransactionRequest($this->getMockOrderData());
        $request->setQuery(['extra_param' => 'value']);

        $this->assertArrayHasKey('extra_param', $request->getQuery());
        $this->assertEquals('value', $request->getQuery()['extra_param']);
    }

    public function test_user_can_send_extra_headers_with_the_initiate_transaction_request()
    {
        $request = new CreateTransactionRequest($this->getMockOrderData());
        $request->setHeaders(['X-Custom-Header' => 'CustomValue']);

        $this->assertArrayHasKey('X-Custom-Header', $request->getHeaders());
        $this->assertEquals('CustomValue', $request->getHeaders()['X-Custom-Header']);
    }

    public function test_initiate_transaction_response_data_class_is_valid()
    {
        $request = new CreateTransactionRequest($this->getMockOrderData());
        $this->assertSame(CheckoutSessionResponseData::class, $request->getResponseDataClass());
    }

    public function test_initiate_transaction_response_data_dto_is_valid()
    {
        $response_data = $this->getMockResponseData();
        $mock_response = new Response(200, [], json_encode($response_data));

        $connector = $this->getMockBuilder(MIBGlobalPayConnector::class)
            ->setConstructorArgs(['test-portal.com', 'merchant123', 'secret'])
            ->onlyMethods(['makeHttpRequest'])
            ->getMock();

        $connector->method('makeHttpRequest')->willReturn($mock_response);
        $response = $connector->createTransaction($this->getMockOrderData());

        $response_dto = $response->toDto();
        $this->assertInstanceOf(IsResponseData::class, $response_dto);
        $this->assertInstanceOf(CheckoutSessionResponseData::class, $response_dto);
        $this->assertEquals($response_dto->getSessionId(), $response_data['session']['id']);
        $this->assertEquals($response_dto->getSuccessIndicator(), $response_data['successIndicator']);
    }

    private function getExpectedRequestData(): array
    {
        return [
            'apiOperation' => 'INITIATE_CHECKOUT',
            'order'        => [
                'id'              => 'order123',
                'amount'          => 100.50,
                'currency'        => 'USD',
                'description'     => 'Test Order',
                'custom'          => null,
                'notificationUrl' => 'https://webhook.url',
            ],
            'interaction'  => [
                'operation'           => 'PURCHASE',
                'cancelUrl'           => 'https://cancel.url',
                'merchant'            => [
                    'address' => [
                        'line1' => '123 Main St',
                    ],
                    'email'   => 'merchant@example.mv',
                    'logo'    => 'https://logo.url',
                    'name'    => 'Merchant Name',
                    'phone'   => '1234567890',
                    'url'     => 'https://merchant.url',
                ],
                'redirectMerchantUrl' => 'https://redirect.url',
                'retryAttemptCount'   => 3,
                'returnUrl'           => 'https://return.url',
            ],
        ];
    }

    private function getMockResponseData(): array
    {
        return [
            'checkoutMode'     => 'WEBSITE',
            'merchant'         => 'TESTMERCHANT',
            'result'           => 'SUCCESS',
            'session'          => [
                'id'           => 'SESSION00000000000001',
                'updateStatus' => 'SUCCESS',
                'version'      => 'fafafafafaf1',
            ],
            'successIndicator' => '505050505050505',
        ];
    }
}
