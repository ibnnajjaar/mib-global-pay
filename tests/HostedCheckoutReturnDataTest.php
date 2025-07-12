<?php

namespace IbnNajjaar\MIBGlobalPay\Tests;

use PHPUnit\Framework\TestCase;
use IbnNajjaar\MIBGlobalPay\Contracts\IsResponseData;
use IbnNajjaar\MIBGlobalPay\Requests\DataObjects\OrderData;
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\HostedCheckoutReturnData;

class HostedCheckoutReturnDataTest extends TestCase
{
    public function test_return_url_response_data_dto_is_valid()
    {
        $hosted_checkout_data = HostedCheckoutReturnData::fromArray($this->getResponseData());
        $this->assertEquals($this->getResponseData()['resultIndicator'], $hosted_checkout_data->getResultIndicator());
        ;
    }

    public function getResponseData(): array
    {
        return [
            'resultIndicator' => '32452352352432',
            'sessionVersion' => '1a0f9970309',
        ];
    }
}
