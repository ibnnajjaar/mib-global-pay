<?php

namespace IbnNajjaar\MIBGlobalPay\Tests;

use IbnNajjaar\MIBGlobalPay\MIBGlobalPayConnector;
use IbnNajjaar\MIBGlobalPay\DataObjects\OrderData;
use IbnNajjaar\MIBGlobalPay\Exceptions\MIBGlobalPayException;
use PHPUnit\Framework\TestCase;

class MIBGlobalPayConnectorTest extends TestCase
{
    public function test_constructor_and_base_url()
    {
        $connector = new MIBGlobalPayConnector('test-portal.com', 'merchant123', 'secret');
        $this->assertEquals('https://test-portal.com/api/rest/version/100/merchant/merchant123/', $connector->resolveBaseUrl());
    }

    public function test_invalid_credentials_throws_exception()
    {
        $this->expectException(\InvalidArgumentException::class);
        new MIBGlobalPayConnector('', '', '');
    }

    public function test_set_and_get_version()
    {
        $connector = new MIBGlobalPayConnector('test-portal.com', 'merchant123', 'secret');
        $connector->setVersion(200);
        $this->assertEquals(200, $connector->getVersion());
    }

    public function test_create_transaction_throws_exception_on_invalid_order()
    {
        $connector = new MIBGlobalPayConnector('test-portal.com', 'merchant123', 'secret');
        $this->expectException(\InvalidArgumentException::class);
        $connector->createTransaction(['invalid' => 'data']);
    }

    public function test_get_transaction_status_throws_exception_on_invalid_reference()
    {
        $connector = $this->getMockBuilder(MIBGlobalPayConnector::class)
            ->setConstructorArgs(['test-portal.com', 'merchant123', 'secret'])
            ->onlyMethods(['send'])
            ->getMock();
        $connector->expects($this->once())
            ->method('send')
            ->willThrowException(new MIBGlobalPayException('Not found'));
        $this->expectException(MIBGlobalPayException::class);
        $connector->getTransactionStatus('invalid-ref');
    }
}
