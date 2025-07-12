<?php

namespace IbnNajjaar\MIBGlobalPay\Tests;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use IbnNajjaar\MIBGlobalPay\MIBGlobalPayConnector;
use IbnNajjaar\MIBGlobalPay\Contracts\IsResponseData;
use IbnNajjaar\MIBGlobalPay\Requests\GetTransactionRequest;
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\OrderResponseData;
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\TransactionResponseData;

class GetTransactionRequestTest extends TestCase
{
    public function test_get_transaction_request_endpoint()
    {
        $order_reference = 'order123';
        $request = new GetTransactionRequest($order_reference);
        $this->assertSame("order/{$order_reference}", $request->resolveEndpoint());
    }

    public function test_get_transaction_request_method_is_get()
    {
        $request = new GetTransactionRequest('order123');
        $this->assertSame('GET', $request->getMethod());
    }

    public function test_get_transaction_response_data_class_is_valid()
    {
        $request = new GetTransactionRequest('order123');
        $this->assertSame(OrderResponseData::class, $request->getResponseDataClass());
    }

    public function test_initiate_transaction_response_data_dto_is_valid()
    {
        $response_data = $this->getMockResponseData();
        $mock_response = new Response(200, [], json_encode($response_data));

        $connector = $this->getMockBuilder(MIBGlobalPayConnector::class)
                          ->setConstructorArgs(['test-portal.com', 'merchant123', 'secret'])
                          ->onlyMethods(['makeHttpRequest'])
                          ->getMock();

        $connector->method('makeHttpRequest')->willReturn($mock_response);
        $response = $connector->getOrderDetails('order123');

        $response_dto = $response->toDto();
        $this->assertInstanceOf(IsResponseData::class, $response_dto);
        $this->assertInstanceOf(OrderResponseData::class, $response_dto);
        $this->assertEquals($response_dto->getOrderStatus(), strtolower($response_data['status']));
        $this->assertEquals($response_dto->getTotalCapturedAmount(), $response_data['totalCapturedAmount']);

        $transactions = $response_data['transaction'] ?? [];
        $transactions_data = [];

        foreach ($transactions as $transaction) {
            if (is_array($transaction)) {
                $transactions_data[] = TransactionResponseData::fromArray($transaction);
            }
        }

        $this->assertEquals($response_dto->getTransactions(), $transactions_data);

        $index  = 0;
        foreach ($response_dto->getTransactions() as $key => $transaction) {
            $this->assertInstanceOf(IsResponseData::class, $transaction);
            $this->assertInstanceOf(TransactionResponseData::class, $transaction);
            $this->assertNotEmpty($transaction->getRawResponse());
            $this->assertEquals($transaction->getType(), strtolower($response_data['transaction'][$index]['transaction']['type'] ?? ''));
            $this->assertEquals($transaction->getResult(), strtolower($response_data['transaction'][$index]['result'] ?? ''));
            $this->assertEquals($transaction->getGatewayCode(), strtolower($response_data['transaction'][$index]['response']['gatewayCode'] ?? ''));
            $index++;
        }

        $this->assertTrue($response_dto->paymentSuccessfullyCaptured());
    }

    public function getMockResponseData(): array
    {
        return [
            'id' => 'order123',
            'amount' => 1000.00,
            'currency' => 'MVR',
            'description' => 'Payment for Order #123',
            'result' => 'SUCCESS',
            'status' => 'CAPTURED',
            'totalCapturedAmount' => 1000.00,
            'transaction' => [
                [
                    'response' => [
                        'gatewayCode' => 'APPROVED',
                    ],
                    'result' => 'SUCCESS',
                    'transaction' => [
                        'amount' => 1000.00,
                        'currency' => 'MVR',
                        'type' => 'AUTHENTICATION'
                    ]
                ],
                [
                    'response' => [
                        'acquirerCode' => '00',
                        'gatewayCode' => 'APPROVED',
                    ],
                    'result' => 'SUCCESS',
                    'transaction' => [
                        'amount' => 1000.00,
                        'currency' => 'MVR',
                        'type' => 'PAYMENT'
                    ]
                ]
            ]
        ];
    }
}
