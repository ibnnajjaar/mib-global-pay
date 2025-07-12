<?php

namespace IbnNajjaar\MIBGlobalPay\Tests;

use PHPUnit\Framework\TestCase;
use IbnNajjaar\MIBGlobalPay\Requests\DataObjects\OrderData;

class OrderDataTest extends TestCase
{
    public function test_it_can_form_order_data_with_only_required_data()
    {
        $order_data = new OrderData('ORD123', 100);
        $this->assertEquals('ORD123', $order_data->getOrderId());
        $this->assertEquals(100, $order_data->getOrderAmount());
        $this->assertEquals('MVR', $order_data->getOrderCurrency());
        $this->assertNull($order_data->getOrderDescription());
        $this->assertNull($order_data->getReturnUrl());
        $this->assertNull($order_data->getMerchantAddressLine1());
        $this->assertNull($order_data->getMerchantEmail());
        $this->assertNull($order_data->getMerchantLogo());
        $this->assertNull($order_data->getMerchantName());
        $this->assertNull($order_data->getMerchantPhone());
        $this->assertNull($order_data->getMerchantUrl());
        $this->assertNull($order_data->getRedirectMerchantUrl());
        $this->assertEquals(1, $order_data->getRetryAttemptCount());
        $this->assertNull($order_data->getWebHookUrl());
        $this->assertNull($order_data->getCancelUrl());
    }

    public function test_missing_order_id_throws_exception()
    {
        $this->expectException(\InvalidArgumentException::class);
        new OrderData('', 100, 'USD', 'desc', 'url');
    }

    public function test_non_numeric_amount_throws_exception()
    {
        $this->expectException(\TypeError::class);
        new OrderData('ORD789', 'not-a-number');
    }

    public function test_unsupported_currency_throws_exception()
    {
        $this->expectException(\InvalidArgumentException::class);
        OrderData::make('ORD789', 100)->setOrderCurrency('XYZ');
    }

    public function test_order_data_implements_is_order_data_interface()
    {
        $order_data = new OrderData('ORD123', 100);
        $this->assertInstanceOf(\IbnNajjaar\MIBGlobalPay\Contracts\IsOrderData::class, $order_data);
    }

    public function test_from_array_with_valid_data()
    {
        $data = [
            'order_id' => 'ORD456',
            'amount' => 200,
            'currency' => 'MVR',
            'description' => 'Another order',
            'return_url' => 'https://return2.url',
            'merchant_address_line1' => '123 Merchant St',
            'merchant_email' => 'info@example.mv',
            'merchant_logo' => 'https://example.mv/logo.png',
            'merchant_name' => 'Example Merchant',
            'merchant_phone' => '+9601234567',
            'merchant_url' => 'https://example.mv',
            'redirect_merchant_url' => 'https://redirect.example.mv',
            'retry_attempt_count' => 2,
            'webhook_url' => 'https://webhook.example.mv',
            'cancel_url' => 'https://cancel.example.mv',
        ];
        $order = OrderData::fromArray($data);
        $this->assertEquals('ORD456', $order->getOrderId());
        $this->assertEquals(200, $order->getOrderAmount());
        $this->assertEquals('MVR', $order->getOrderCurrency());
        $this->assertEquals('Another order', $order->getOrderDescription());
        $this->assertEquals('https://return2.url', $order->getReturnUrl());
        $this->assertEquals('123 Merchant St', $order->getMerchantAddressLine1());
        $this->assertEquals('info@example.mv', $order->getMerchantEmail());
        $this->assertEquals('https://example.mv/logo.png', $order->getMerchantLogo());
        $this->assertEquals('Example Merchant', $order->getMerchantName());
        $this->assertEquals('+9601234567', $order->getMerchantPhone());
        $this->assertEquals('https://example.mv', $order->getMerchantUrl());
        $this->assertEquals('https://redirect.example.mv', $order->getRedirectMerchantUrl());
        $this->assertEquals(2, $order->getRetryAttemptCount());
        $this->assertEquals('https://webhook.example.mv', $order->getWebHookUrl());
        $this->assertEquals('https://cancel.example.mv', $order->getCancelUrl());
    }
}
