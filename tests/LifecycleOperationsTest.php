<?php

namespace IbnNajjaar\MIBGlobalPay\Tests;

use PHPUnit\Framework\TestCase;
use IbnNajjaar\MIBGlobalPay\Support\Response;
use IbnNajjaar\MIBGlobalPay\MIBGlobalPayConnector;
use IbnNajjaar\MIBGlobalPay\Support\TransactionId;
use IbnNajjaar\MIBGlobalPay\Requests\GetTransactionRequest;
use IbnNajjaar\MIBGlobalPay\Tests\Support\MockClientFactory;
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\TransactionResponseData;

class LifecycleOperationsTest extends TestCase
{
    private const BASE = 'https://host/api/rest/version/100/merchant/M/';

    private $history;

    private function connector(array $response_body, int $status = 201): MIBGlobalPayConnector
    {
        $connector = new MIBGlobalPayConnector('host', 'M', 'secret');
        $connector->setHttpClient(MockClientFactory::make([MockClientFactory::json($status, $response_body)], $this->history));

        return $connector;
    }

    private function assertSent(string $method, string $path, ?array $body)
    {
        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];

        $this->assertSame($method, $request->getMethod());
        $this->assertSame(self::BASE . $path, (string) $request->getUri());
        $this->assertSame('Basic ' . base64_encode('merchant.M:secret'), $request->getHeaderLine('Authorization'));

        $sent = (string) $request->getBody();
        if ($body === null) {
            $this->assertSame('', $sent);
        } else {
            $this->assertSame($body, json_decode($sent, true));
        }
    }

    private function transaction(Response $response): TransactionResponseData
    {
        $dto = $response->toDto();
        $this->assertInstanceOf(TransactionResponseData::class, $dto);

        return $dto;
    }

    public function test_refund()
    {
        $response = $this->connector(MockClientFactory::fixture('transaction_refund_success.json'))
            ->refund('INV/2024#1', 'refund-1', 25, 'mvr', '1', 'RMA-77');

        $this->assertSent('PUT', 'order/INV%2F2024%231/transaction/refund-1', [
            'apiOperation' => 'REFUND',
            'transaction' => [
                'amount' => '25.00',
                'currency' => 'MVR',
                'targetTransactionId' => '1',
                'reference' => 'RMA-77',
            ],
        ]);

        $transaction = $this->transaction($response);
        $this->assertSame('refund-1', $transaction->getTransactionId());
        $this->assertSame(25.0, $transaction->getAmount());
        $this->assertTrue($transaction->isSuccessful());
        $this->assertTrue($transaction->isRefund());
    }

    public function test_refund_without_optional_fields()
    {
        $this->connector(MockClientFactory::fixture('transaction_refund_success.json'))
            ->refund('ORDER-1', 'refund-1', '10.5', 'MVR');

        $this->assertSent('PUT', 'order/ORDER-1/transaction/refund-1', [
            'apiOperation' => 'REFUND',
            'transaction' => ['amount' => '10.50', 'currency' => 'MVR'],
        ]);
    }

    public function test_declined_refund_does_not_throw()
    {
        $response = $this->connector(MockClientFactory::fixture('transaction_refund_declined.json'), 201)
            ->refund('ORDER-1001', 'refund-2', 25, 'MVR');

        $this->assertSame('FAILURE', $response->getResult());
        $transaction = $this->transaction($response);
        $this->assertTrue($transaction->isFailure());
        $this->assertFalse($transaction->isApproved());
        $this->assertSame('declined', $transaction->getGatewayCode());
    }

    public function test_void()
    {
        $response = $this->connector([
            'result' => 'SUCCESS',
            'response' => ['gatewayCode' => 'APPROVED'],
            'order' => ['id' => 'ORDER-1', 'status' => 'CANCELLED'],
            'transaction' => ['id' => 'void-1', 'type' => 'VOID_AUTHORIZATION', 'targetTransactionId' => 'auth-1', 'amount' => 50, 'currency' => 'MVR'],
        ])->void('ORDER-1', 'void-1', 'auth-1');

        $this->assertSent('PUT', 'order/ORDER-1/transaction/void-1', [
            'apiOperation' => 'VOID',
            'transaction' => ['targetTransactionId' => 'auth-1'],
        ]);

        $transaction = $this->transaction($response);
        $this->assertTrue($transaction->isVoid());
        $this->assertSame('cancelled', $transaction->getOrderStatus());
        $this->assertSame('auth-1', $transaction->getTargetTransactionId());
    }

    public function test_capture()
    {
        $response = $this->connector([
            'result' => 'SUCCESS',
            'response' => ['gatewayCode' => 'APPROVED'],
            'order' => ['id' => 'ORDER-1', 'status' => 'PARTIALLY_CAPTURED'],
            'transaction' => ['id' => 'capture-1', 'type' => 'CAPTURE', 'amount' => 40, 'currency' => 'USD'],
        ])->capture('ORDER-1', 'capture-1', 40.00, 'USD', 'ship-1');

        $this->assertSent('PUT', 'order/ORDER-1/transaction/capture-1', [
            'apiOperation' => 'CAPTURE',
            'transaction' => ['amount' => '40.00', 'currency' => 'USD', 'reference' => 'ship-1'],
        ]);

        $transaction = $this->transaction($response);
        $this->assertTrue($transaction->isCapture());
        $this->assertSame(40.0, $transaction->getAmount());
    }

    public function test_update_authorization()
    {
        $this->connector(['result' => 'SUCCESS', 'transaction' => ['type' => 'AUTHORIZATION_UPDATE']])
            ->updateAuthorization('ORDER-1', 'update-1', 120, 'MVR');

        $this->assertSent('PUT', 'order/ORDER-1/transaction/update-1', [
            'apiOperation' => 'UPDATE_AUTHORIZATION',
            'transaction' => ['amount' => '120.00', 'currency' => 'MVR'],
        ]);
    }

    public function test_update_authorization_without_amount()
    {
        $this->connector(['result' => 'SUCCESS'])->updateAuthorization('ORDER-1', 'update-1');

        $this->assertSent('PUT', 'order/ORDER-1/transaction/update-1', [
            'apiOperation' => 'UPDATE_AUTHORIZATION',
        ]);
    }

    public function test_retrieve_transaction()
    {
        $response = $this->connector(MockClientFactory::fixture('transaction_refund_success.json'), 200)
            ->getTransactionDetails('INV/2024#1', 'ref 1');

        $this->assertSent('GET', 'order/INV%2F2024%231/transaction/ref%201', null);
        $this->assertSame('refund-1', $this->transaction($response)->getTransactionId());
    }

    public function test_get_order_details_encodes_order_id()
    {
        $this->connector(MockClientFactory::fixture('order_partially_refunded.json'), 200)->getOrderDetails('INV/2024#1');
        $this->assertSent('GET', 'order/INV%2F2024%231', null);

        $this->assertSame('order/ORDER-123', (new GetTransactionRequest('ORDER-123'))->resolveEndpoint());
        $this->assertSame('order/a%3Fb%20c', (new GetTransactionRequest('a?b c'))->resolveEndpoint());
    }

    /**
     * @dataProvider invalidOperations
     */
    public function test_validation_errors(callable $operation)
    {
        $connector = $this->connector([]);

        try {
            $operation($connector);
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertCount(0, $this->history, 'No request should be sent');
        }
    }

    public function invalidOperations(): array
    {
        $long = str_repeat('a', 41);

        return [
            'transaction id too long' => [function (MIBGlobalPayConnector $c) use ($long) {
                $c->refund('ORDER-1', $long, 10, 'MVR');
            }],
            'empty transaction id' => [function (MIBGlobalPayConnector $c) {
                $c->refund('ORDER-1', '', 10, 'MVR');
            }],
            'empty void target' => [function (MIBGlobalPayConnector $c) {
                $c->void('ORDER-1', 'void-1', '');
            }],
            'invalid currency' => [function (MIBGlobalPayConnector $c) {
                $c->refund('ORDER-1', 'refund-1', 10, 'MV');
            }],
            'zero amount' => [function (MIBGlobalPayConnector $c) {
                $c->capture('ORDER-1', 'capture-1', 0, 'MVR');
            }],
            'negative amount' => [function (MIBGlobalPayConnector $c) {
                $c->refund('ORDER-1', 'refund-1', -5, 'MVR');
            }],
            'three decimals' => [function (MIBGlobalPayConnector $c) {
                $c->refund('ORDER-1', 'refund-1', '10.123', 'MVR');
            }],
            'amount without currency' => [function (MIBGlobalPayConnector $c) {
                $c->updateAuthorization('ORDER-1', 'update-1', 10);
            }],
            'currency without amount' => [function (MIBGlobalPayConnector $c) {
                $c->updateAuthorization('ORDER-1', 'update-1', null, 'MVR');
            }],
            'reference too long' => [function (MIBGlobalPayConnector $c) use ($long) {
                $c->void('ORDER-1', 'void-1', 'auth-1', $long);
            }],
        ];
    }

    public function test_transaction_id_generate()
    {
        $id = TransactionId::generate();
        $this->assertRegExpCompat('/^txn-[0-9a-f]{16}$/', $id);
        $this->assertNotSame($id, TransactionId::generate());

        $id = TransactionId::generate(str_repeat('r', 20));
        $this->assertSame(37, strlen($id));
        TransactionId::validate($id);
    }

    public function test_transaction_id_generate_rejects_invalid_prefix()
    {
        $this->expectException(\InvalidArgumentException::class);
        TransactionId::generate('bad prefix');
    }

    private function assertRegExpCompat(string $pattern, string $value)
    {
        $this->assertSame(1, preg_match($pattern, $value), "'$value' does not match $pattern");
    }
}
