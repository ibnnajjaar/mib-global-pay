<?php

namespace IbnNajjaar\MIBGlobalPay\Tests;

use PHPUnit\Framework\TestCase;
use IbnNajjaar\MIBGlobalPay\Support\Constants\OrderStatus;
use IbnNajjaar\MIBGlobalPay\Tests\Support\MockClientFactory;
use IbnNajjaar\MIBGlobalPay\Support\Constants\TransactionType;
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\OrderResponseData;
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\TransactionResponseData;

class OrderResponseDataTest extends TestCase
{
    private function order(): OrderResponseData
    {
        return OrderResponseData::fromArray(MockClientFactory::fixture('order_partially_refunded.json'));
    }

    public function test_partially_refunded_order_is_still_paid()
    {
        $order = $this->order();

        $this->assertSame(OrderStatus::PARTIALLY_REFUNDED, $order->getOrderStatus());
        $this->assertTrue($order->isPaid());
        // Documents the old behaviour: the status is no longer "captured" after a refund
        $this->assertFalse($order->paymentSuccessfullyCaptured());
        $this->assertTrue($order->hasPaidTransaction());
    }

    public function test_order_getters()
    {
        $order = $this->order();

        $this->assertSame('ORDER-1001', $order->getOrderId());
        $this->assertSame(100.0, $order->getAmount());
        $this->assertSame('MVR', $order->getCurrency());
        $this->assertSame(100.0, $order->getTotalAuthorizedAmount());
        $this->assertSame(100.0, $order->getTotalCapturedAmount());
        $this->assertSame(30.0, $order->getTotalRefundedAmount());
        $this->assertSame('INV-1001', $order->getReference());
        $this->assertSame('2026-10-01T08:15:30.000Z', $order->getCreationTime());
        $this->assertSame(70.0, $order->getRefundableAmount());
        $this->assertSame(0.0, $order->getCapturableAmount());
    }

    public function test_transactions()
    {
        $order = $this->order();

        $this->assertCount(3, $order->getTransactions());
        $this->assertCount(3, $order->getSuccessfulTransactions());

        $refund = $order->findTransaction('refund-1');
        $this->assertInstanceOf(TransactionResponseData::class, $refund);
        $this->assertTrue($refund->isRefund());
        $this->assertSame(30.0, $refund->getAmount());
        $this->assertNull($order->findTransaction('missing'));

        $authentication = $order->findTransaction('auth-3ds-1');
        $this->assertNull($authentication->getGatewayCode());

        $this->assertSame('1', $order->getLatestTransactionOfType(TransactionType::PAYMENT, TransactionType::CAPTURE)->getTransactionId());
        $this->assertSame('refund-1', $order->getLatestTransactionOfType('REFUND')->getTransactionId());
        $this->assertNull($order->getLatestTransactionOfType(TransactionType::VOID_PAYMENT));
    }

    public function test_captured_order_without_refund()
    {
        $data = MockClientFactory::fixture('order_partially_refunded.json');
        $data['status'] = 'CAPTURED';
        unset($data['totalRefundedAmount']);
        array_pop($data['transaction']);

        $order = OrderResponseData::fromArray($data);
        $this->assertTrue($order->isPaid());
        $this->assertTrue($order->paymentSuccessfullyCaptured());
        $this->assertSame(100.0, $order->getRefundableAmount());
    }

    public function test_authorized_order_is_not_paid()
    {
        $order = OrderResponseData::fromArray([
            'status' => 'AUTHORIZED',
            'totalAuthorizedAmount' => 100,
            'totalCapturedAmount' => 0,
            'transaction' => [[
                'result' => 'SUCCESS',
                'response' => ['gatewayCode' => 'APPROVED'],
                'transaction' => ['id' => '1', 'type' => 'AUTHORIZATION', 'amount' => 100],
            ]],
        ]);

        $this->assertFalse($order->isPaid());
        $this->assertSame(100.0, $order->getCapturableAmount());
        $this->assertSame(0.0, $order->getRefundableAmount());
    }

    public function test_empty_order_has_no_warnings()
    {
        $order = OrderResponseData::fromArray([]);

        $this->assertNull($order->getOrderStatus());
        $this->assertSame([], $order->getTransactions());
        $this->assertFalse($order->isPaid());
        $this->assertFalse($order->paymentSuccessfullyCaptured());
        $this->assertSame(0.0, $order->getRefundableAmount());

        $order = new OrderResponseData(null, null, null, null);
        $this->assertFalse($order->hasPaidTransaction());
        $this->assertSame([], $order->getSuccessfulTransactions());
    }
}
