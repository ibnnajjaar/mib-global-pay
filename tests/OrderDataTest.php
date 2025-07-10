<?php

namespace IbnNajjaar\MIBGlobalPay\Tests;

use IbnNajjaar\MIBGlobalPay\DataObjects\OrderData;
use PHPUnit\Framework\TestCase;

class OrderDataTest extends TestCase
{
    public function testValidConstruction()
    {
        $order = new OrderData('ORD123', 100.50, 'USD', 'Test order', 'https://return.url');
        $this->assertEquals('ORD123', $order->getOrderId());
        $this->assertEquals(100.50, $order->getAmount());
        $this->assertEquals('USD', $order->getCurrency());
        $this->assertEquals('Test order', $order->getDescription());
        $this->assertEquals('https://return.url', $order->getReturnUrl());
    }

    public function testFromArrayWithValidData()
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

    public function testMissingOrderIdThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        new OrderData('', 100, 'USD', 'desc', 'url');
    }

    public function testNonNumericAmountThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        new OrderData('ORD789', 'not-a-number', 'USD', 'desc', 'url');
    }

    public function testFromArrayWithMissingFieldsThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        OrderData::fromArray(['amount' => 100]);
    }
}
