<?php

namespace IbnNajjaar\MIBGlobalPay\Tests;

use IbnNajjaar\MIBGlobalPay\DataObjects\OrderData;
use PHPUnit\Framework\TestCase;

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
        $this->expectException(\InvalidArgumentException::class);
        new OrderData('ORD789', 'not-a-number', 'USD', 'desc', 'url');
    }

    public function test_unsupported_currency_throws_exception()
    {
        $this->expectException(\InvalidArgumentException::class);
        new OrderData('ORD789', 100, 'XYZ');
    }

    public function test_from_array_with_valid_data()
    {
        $data = [
            'order_id' => 'ORD456',
            'amount' => 200,
            'currency' => 'USD', // Changed from 'EUR' to 'USD'
            'description' => 'Another order',
            'return_url' => 'https://return2.url',
        ];
        $order = OrderData::fromArray($data);
        $this->assertEquals('ORD456', $order->getOrderId());
        $this->assertEquals(200, $order->getAmount());
        $this->assertEquals('USD', $order->getCurrency());
        $this->assertEquals('Another order', $order->getDescription());
        $this->assertEquals('https://return2.url', $order->getReturnUrl());
    }





    public function test_from_array_with_missing_fields_throws_exception()
    {
        $this->expectException(\InvalidArgumentException::class);
        OrderData::fromArray(['amount' => 100]);
    }
}
