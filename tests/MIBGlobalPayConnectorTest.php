<?php

namespace IbnNajjaar\MIBGlobalPay\Tests;

use IbnNajjaar\MIBGlobalPay\MIBGlobalPayConnector;
use IbnNajjaar\MIBGlobalPay\DataObjects\OrderData;
use IbnNajjaar\MIBGlobalPay\Exceptions\MIBGlobalPayException;
use PHPUnit\Framework\TestCase;

class MIBGlobalPayConnectorTest extends TestCase
{
    public function testConstructorAndBaseUrl()
    {
        $connector = new MIBGlobalPayConnector('test-portal.com', 'merchant123', 'secret');
        $this->assertEquals('https://test-portal.com/api/rest/version/100/merchant/merchant123/', $connector->resolveBaseUrl());
    }

    public function testInvalidCredentialsThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        new MIBGlobalPayConnector('', '', '');
    }

    public function testSetAndGetVersion()
    {
        $connector = new MIBGlobalPayConnector('test-portal.com', 'merchant123', 'secret');
        $connector->setVersion(200);
        $this->assertEquals(200, $connector->getVersion());
    }

    public function testCreateTransactionThrowsExceptionOnInvalidOrder()
    {
        $connector = new MIBGlobalPayConnector('test-portal.com', 'merchant123', 'secret');
        $this->expectException(\InvalidArgumentException::class);
        $connector->createTransaction(['invalid' => 'data']);
    }

    public function testGetTransactionStatusThrowsExceptionOnInvalidReference()
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
