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
        $connector->setApiVersion(200);
        $this->assertEquals(200, $connector->getApiVersion());
    }
}
