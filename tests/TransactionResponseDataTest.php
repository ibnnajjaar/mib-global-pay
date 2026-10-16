<?php

namespace IbnNajjaar\MIBGlobalPay\Tests;

use PHPUnit\Framework\TestCase;
use IbnNajjaar\MIBGlobalPay\Tests\Support\MockClientFactory;
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\TransactionResponseData;

class TransactionResponseDataTest extends TestCase
{
    public function test_getters_read_the_raw_response()
    {
        /** @var TransactionResponseData $transaction */
        $transaction = TransactionResponseData::fromArray(MockClientFactory::fixture('transaction_refund_success.json'));

        $this->assertSame('success', $transaction->getResult());
        $this->assertSame('approved', $transaction->getGatewayCode());
        $this->assertSame('refund', $transaction->getType());
        $this->assertSame('refund-1', $transaction->getTransactionId());
        $this->assertSame(25.0, $transaction->getAmount());
        $this->assertSame('MVR', $transaction->getCurrency());
        $this->assertSame('1', $transaction->getTargetTransactionId());
        $this->assertSame('RMA-77', $transaction->getReference());
        $this->assertSame('627412345679', $transaction->getReceipt());
        $this->assertSame('654321', $transaction->getAuthorizationCode());
        $this->assertSame('00', $transaction->getAcquirerCode());
        $this->assertSame('2026-10-02T10:00:00.000Z', $transaction->getTimeOfRecord());
        $this->assertSame('ORDER-1001', $transaction->getOrderId());
        $this->assertSame('partially_refunded', $transaction->getOrderStatus());
    }

    public function test_result_helpers()
    {
        $this->assertTrue($this->make('SUCCESS')->isSuccessful());
        $this->assertTrue($this->make('PENDING')->isPending());
        $this->assertTrue($this->make('FAILURE')->isFailure());
        $this->assertTrue($this->make('ERROR')->isFailure());
        $this->assertTrue($this->make('UNKNOWN')->isUnknown());
        $this->assertFalse($this->make('SUCCESS')->isFailure());
        $this->assertFalse($this->make(null)->isSuccessful());
    }

    public function test_approved_matches_all_approved_codes()
    {
        $this->assertTrue($this->make('SUCCESS', 'APPROVED')->isApproved());
        $this->assertTrue($this->make('SUCCESS', 'APPROVED_PENDING_SETTLEMENT')->isApproved());
        $this->assertTrue($this->make('SUCCESS', 'APPROVED_AUTO')->isApproved());
        $this->assertFalse($this->make('FAILURE', 'DECLINED')->isApproved());
        $this->assertFalse($this->make('SUCCESS', null)->isApproved());
    }

    public function test_type_helpers()
    {
        $this->assertTrue($this->make('SUCCESS', 'APPROVED', 'PAYMENT')->isPayment());
        $this->assertTrue($this->make('SUCCESS', 'APPROVED', 'REFUND')->isRefund());
        $this->assertTrue($this->make('SUCCESS', 'APPROVED', 'CAPTURE')->isCapture());
        $this->assertTrue($this->make('SUCCESS', 'APPROVED', 'AUTHORIZATION')->isAuthorization());
        $this->assertTrue($this->make('SUCCESS', 'APPROVED', 'VOID_AUTHORIZATION')->isVoid());
        $this->assertTrue($this->make('SUCCESS', 'APPROVED', 'VOID_PAYMENT')->isVoid());
        $this->assertFalse($this->make('SUCCESS', 'APPROVED', 'PAYMENT')->isVoid());
        $this->assertFalse($this->make('SUCCESS', 'APPROVED', 'PAYMENT')->isRefund());
    }

    public function test_sparse_payload_has_no_warnings()
    {
        /** @var TransactionResponseData $transaction */
        $transaction = TransactionResponseData::fromArray([]);

        $this->assertNull($transaction->getResult());
        $this->assertNull($transaction->getGatewayCode());
        $this->assertNull($transaction->getType());
        $this->assertNull($transaction->getTransactionId());
        $this->assertNull($transaction->getAmount());
        $this->assertNull($transaction->getOrderStatus());
        $this->assertFalse($transaction->isApproved());
        $this->assertFalse($transaction->isVoid());
    }

    private function make(?string $result, ?string $gateway_code = null, ?string $type = null): TransactionResponseData
    {
        return TransactionResponseData::fromArray(array_filter([
            'result' => $result,
            'response' => $gateway_code ? ['gatewayCode' => $gateway_code] : null,
            'transaction' => $type ? ['type' => $type] : null,
        ]));
    }
}
