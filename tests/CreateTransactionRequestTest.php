<?php

namespace IbnNajjaar\MIBGlobalPay\Tests;


use PHPUnit\Framework\TestCase;
use IbnNajjaar\MIBGlobalPay\Requests\DataObjects\OrderData;
use IbnNajjaar\MIBGlobalPay\Requests\CreateTransactionRequest;
use IbnNajjaar\MIBGlobalPay\Requests\DataObjects\BaseOrderData;
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\CheckoutSessionData;

class CreateTransactionRequestTest extends TestCase
{
    private function getMockOrderData(): BaseOrderData
    {
        $mock = $this->getMockBuilder(OrderData::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mock->method('getOrderId')->willReturn('order123');
        $mock->method('getOrderAmount')->willReturn(100.50);
        $mock->method('getOrderCurrency')->willReturn('USD');
        $mock->method('getOrderDescription')->willReturn('Test Order');
        $mock->method('getWebHookUrl')->willReturn('https://webhook.url');
        $mock->method('getCancelUrl')->willReturn('https://cancel.url');
        $mock->method('getMerchantAddressLine1')->willReturn('123 Main St');
        $mock->method('getMerchantEmail')->willReturn('merchant@example.com');
        $mock->method('getMerchantLogo')->willReturn('https://logo.url');
        $mock->method('getMerchantName')->willReturn('Merchant Name');
        $mock->method('getMerchantPhone')->willReturn('1234567890');
        $mock->method('getMerchantUrl')->willReturn('https://merchant.url');
        $mock->method('getRedirectMerchantUrl')->willReturn('https://redirect.url');
        $mock->method('getRetryAttemptCount')->willReturn(3);
        $mock->method('getReturnUrl')->willReturn('https://return.url');
        return $mock;
    }

    public function testResolveEndpoint()
    {
        $request = new CreateTransactionRequest($this->getMockOrderData());
        $this->assertSame('session', $request->resolveEndpoint());
    }

    public function testDefaultDataStructure()
    {
        $request = new CreateTransactionRequest($this->getMockOrderData());
        $data = $request->defaultData();
        $this->assertArrayHasKey('apiOperation', $data);
        $this->assertSame('INITIATE_CHECKOUT', $data['apiOperation']);
        $this->assertArrayHasKey('order', $data);
        $this->assertArrayHasKey('interaction', $data);
        $this->assertSame('order123', $data['order']['id']);
        $this->assertSame(100.50, $data['order']['amount']);
        $this->assertSame('USD', $data['order']['currency']);
        $this->assertSame('Test Order', $data['order']['description']);
        $this->assertSame('https://webhook.url', $data['order']['notificationUrl']);
        $this->assertSame('PURCHASE', $data['interaction']['operation']);
        $this->assertSame('https://cancel.url', $data['interaction']['cancelUrl']);
        $this->assertSame('123 Main St', $data['interaction']['merchant']['address']['line1']);
        $this->assertSame('merchant@example.com', $data['interaction']['merchant']['email']);
        $this->assertSame('https://logo.url', $data['interaction']['merchant']['logo']);
        $this->assertSame('Merchant Name', $data['interaction']['merchant']['name']);
        $this->assertSame('1234567890', $data['interaction']['merchant']['phone']);
        $this->assertSame('https://merchant.url', $data['interaction']['merchant']['url']);
        $this->assertSame('https://redirect.url', $data['interaction']['redirectMerchantUrl']);
        $this->assertSame(3, $data['interaction']['retryAttemptCount']);
        $this->assertSame('https://return.url', $data['interaction']['returnUrl']);
    }

    public function testGetResponseDataClass()
    {
        $request = new CreateTransactionRequest($this->getMockOrderData());
        $this->assertSame(CheckoutSessionData::class, $request->getResponseDataClass());
    }
}

