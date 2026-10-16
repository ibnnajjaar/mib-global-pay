<?php

namespace IbnNajjaar\MIBGlobalPay\Tests;

use PHPUnit\Framework\TestCase;
use IbnNajjaar\MIBGlobalPay\Contracts\IsResponseData;
use IbnNajjaar\MIBGlobalPay\Requests\DataObjects\OrderData;
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\WebhookResponseData;
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\HostedCheckoutReturnData;

class WebhookResponseDataTest extends TestCase
{
    public function test_webhook_response_data_dto_is_valid()
    {
        $response_data = $this->getResponseData();
        $webhook_response_data = WebhookResponseData::fromArray($response_data, $this->getResponseHeaderData());
        $this->assertInstanceOf(IsResponseData::class, $webhook_response_data);
        $this->assertEquals(strtolower($response_data['order']['status'] ?? ''), $webhook_response_data->getOrderStatus());
        $this->assertEquals(strtolower($response_data['result'] ?? ''), $webhook_response_data->getResult());
        $this->assertEquals(strtolower($response_data['response']['gatewayCode'] ?? ''), $webhook_response_data->getGatewayCode());
        $this->assertEquals(strtolower($response_data['transaction']['type'] ?? ''), $webhook_response_data->getTransactionType());
        $this->assertTrue($webhook_response_data->paymentIsSuccessful());

        $response_data['transaction']['type'] = 'AUTHENTICATION';
        $authenticated_webhook_response_data = WebhookResponseData::fromArray($response_data, $this->getResponseHeaderData());
        $this->assertFalse($authenticated_webhook_response_data->paymentIsSuccessful());
    }

    public function test_empty_payload_produces_no_warnings()
    {
        $webhook = WebhookResponseData::fromArray([]);

        $this->assertNull($webhook->getOrderStatus());
        $this->assertNull($webhook->getResult());
        $this->assertNull($webhook->getNotificationSecret());
        $this->assertNull($webhook->getNotificationId());
        $this->assertNull($webhook->getNotificationAttempt());
        $this->assertFalse($webhook->paymentIsSuccessful());
        $this->assertFalse($webhook->isRefund());
    }

    public function test_getallheaders_style_headers_return_full_secret()
    {
        $webhook = WebhookResponseData::fromArray($this->getResponseData(), [
            'X-Notification-Secret' => 'notification-secret-value-on-gateway',
            'X-Notification-ID' => 'notification-id-1',
            'X-Notification-Attempt' => '3',
        ]);

        $this->assertSame('notification-secret-value-on-gateway', $webhook->getNotificationSecret());
        $this->assertSame('notification-id-1', $webhook->getNotificationId());
        $this->assertSame(3, $webhook->getNotificationAttempt());
    }

    public function test_laravel_style_headers()
    {
        $webhook = WebhookResponseData::fromArray($this->getResponseData(), $this->getResponseHeaderData());

        $this->assertSame('notification-secret-value-on-gateway', $webhook->getNotificationSecret());
        $this->assertSame('notification-id-value-on-gateway', $webhook->getNotificationId());
    }

    public function test_mixed_case_header_names()
    {
        $webhook = WebhookResponseData::fromArray([], [
            'x-NOTIFICATION-secret' => 'secret-1',
            'X-Notification-Id' => ['id-1'],
        ]);

        $this->assertSame('secret-1', $webhook->getNotificationSecret());
        $this->assertSame('id-1', $webhook->getNotificationId());
    }

    public function test_from_json()
    {
        $webhook = WebhookResponseData::fromJson(json_encode($this->getResponseData()), ['X-Notification-Secret' => 'abc']);

        $this->assertSame('ORD123', $webhook->getOrderReference());
        $this->assertSame('abc', $webhook->getNotificationSecret());
        $this->assertTrue($webhook->paymentIsSuccessful());

        $this->assertFalse(WebhookResponseData::fromJson('not json')->paymentIsSuccessful());
    }

    public function test_refund_webhook()
    {
        $data = $this->getResponseData();
        $data['order']['status'] = 'PARTIALLY_REFUNDED';
        $data['transaction'] = [
            'id' => 'refund-1',
            'type' => 'REFUND',
            'amount' => 25.00,
            'currency' => 'MVR',
            'targetTransactionId' => '1',
        ];

        $webhook = WebhookResponseData::fromArray($data);

        $this->assertTrue($webhook->isRefund());
        $this->assertFalse($webhook->isVoid());
        $this->assertFalse($webhook->paymentIsSuccessful());
        $this->assertSame('refund-1', $webhook->getTransactionId());
        $this->assertSame(25.0, $webhook->getTransactionAmount());
        $this->assertSame('MVR', $webhook->getTransactionCurrency());
        $this->assertSame('1', $webhook->getTargetTransactionId());
        $this->assertTrue($webhook->getTransaction()->isSuccessful());
    }

    public function test_void_webhook()
    {
        $data = $this->getResponseData();
        $data['transaction']['type'] = 'VOID_PAYMENT';

        $this->assertTrue(WebhookResponseData::fromArray($data)->isVoid());
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
