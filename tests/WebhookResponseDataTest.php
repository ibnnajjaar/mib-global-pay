<?php

namespace IbnNajjaar\MIBGlobalPay\Tests;

use IbnNajjaar\MIBGlobalPay\Requests\DataObjects\OrderData;
use PHPUnit\Framework\TestCase;
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\WebhookResponseData;
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\HostedCheckoutReturnData;

class WebhookResponseDataTest extends TestCase
{

    public function test_webhook_response_data_dto_is_valid()
    {
        $response_data = $this->getResponseData();
        $webhook_response_data = WebhookResponseData::fromArray($response_data, $this->getResponseHeaderData());
        $this->assertEquals(strtolower($response_data['order']['status'] ?? ''), $webhook_response_data->getOrderStatus());
        $this->assertEquals(strtolower($response_data['result'] ?? ''), $webhook_response_data->getResult());
        $this->assertEquals(strtolower($response_data['response']['gatewayCode'] ?? ''), $webhook_response_data->getGatewayCode());
        $this->assertEquals(strtolower($response_data['transaction']['type'] ?? ''), $webhook_response_data->getTransactionType());
        $this->assertTrue($webhook_response_data->paymentIsSuccessful());

        $response_data['transaction']['type'] = 'AUTHENTICATION';
        $authenticated_webhook_response_data = WebhookResponseData::fromArray($response_data, $this->getResponseHeaderData());
        $this->assertFalse($authenticated_webhook_response_data->paymentIsSuccessful());
    }

    public function getResponseData(): array
    {
        return [
            'order' => [
                'amount' => 100.00,
                'currency' => 'MVR',
                'description' => 'Test Order',
                'id' => 'ORD123',
                'status' => 'CAPTURED',
                'totalCapturedAmount' => 100.00,
            ],
            'response' => [
                'gatewayCode' => 'APPROVED'
            ],
            'result' => 'SUCCESS',
            'transaction' => [
                'type' => 'PAYMENT',
            ]
        ];
    }

    public function getResponseHeaderData(): array
    {
        return [
            'x-notification-secret' => [
                'notification-secret-value-on-gateway'
            ],
            'x-notification-id' => [
                'notification-id-value-on-gateway'
            ]
        ];
    }

}
