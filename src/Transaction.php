<?php

namespace IbnNajjaar\MIBGlobalPay;

use GuzzleHttp\Client as GuzzleClient;
use IbnNajjaar\MIBGlobalPay\DataObjects\OrderData;

class Transaction
{
    public function create(GuzzleClient $http_client, array $order_data): array
    {
        $order = OrderData::fromArray($order_data);
        $payload = [
            'apiOperation' => 'CREATE_CHECKOUT_SESSION',
            'order' => [
                'id' => $order->getOrderId(),
                'amount' => $order->getAmount(),
                'currency' => $order->getCurrency(),
                'description' => $order->getDescription(),
            ],
            'interaction' => [
                'returnUrl' => $order->getReturnUrl(),
            ],
        ];
        $response = $http_client->post('session', [
            'json' => $payload
        ]);
        return json_decode($response->getBody()->getContents(), true);
    }
}
