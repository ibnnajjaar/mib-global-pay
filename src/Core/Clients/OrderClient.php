<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Clients;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\Pay;
use IbnNajjaar\MIBGlobalPay\Core\Requests\StoredPayment;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\Refund;
use IbnNajjaar\MIBGlobalPay\Core\Responses\OrderResponse;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\Capture;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\Referral;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\Authorize;
use IbnNajjaar\MIBGlobalPay\Core\Responses\TransactionResponse;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\RetrieveOrder;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\VerifyAccount;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\VoidTransaction;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\RetrieveTransaction;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\UpdateAuthorization;

/**
 * Order and transaction operations. $transactionId is a NEW ID, unique within the order;
 * persist it before calling so a request without a response can be resent with the same ID.
 */
final readonly class OrderClient
{
    public function __construct(
        private Gateway $gateway,
    ) {
    }

    public function retrieve(string $orderId): OrderResponse
    {
        return $this->gateway->send(new RetrieveOrder($orderId));
    }

    public function retrieveTransaction(string $orderId, string $transactionId): TransactionResponse
    {
        return $this->gateway->send(new RetrieveTransaction($orderId, $transactionId));
    }

    /**
     * @param array<string, mixed> $extraFields updated order data, such as a new shipping address
     */
    public function capture(string $orderId, string $transactionId, Money $amount, ?string $reference = null, array $extraFields = []): TransactionResponse
    {
        return $this->gateway->send(new Capture($orderId, $transactionId, $amount, $reference, $extraFields));
    }

    public function pay(string $orderId, string $transactionId, Money $amount, StoredPayment $payment, ?string $reference = null): TransactionResponse
    {
        return $this->gateway->send(new Pay($orderId, $transactionId, $amount, $payment, $reference));
    }

    public function authorize(string $orderId, string $transactionId, Money $amount, StoredPayment $payment, ?string $reference = null): TransactionResponse
    {
        return $this->gateway->send(new Authorize($orderId, $transactionId, $amount, $payment, $reference));
    }

    public function verify(string $orderId, string $transactionId, Currency $currency, StoredPayment $payment, ?string $reference = null): TransactionResponse
    {
        return $this->gateway->send(new VerifyAccount($orderId, $transactionId, $currency, $payment, $reference));
    }

    public function referral(string $orderId, string $transactionId, string $authorizationCode, ?string $reference = null): TransactionResponse
    {
        return $this->gateway->send(new Referral($orderId, $transactionId, $authorizationCode, $reference));
    }

    public function refund(
        string $orderId,
        string $transactionId,
        Money $amount,
        ?string $targetTransactionId = null,
        ?string $reference = null,
    ): TransactionResponse {
        return $this->gateway->send(new Refund($orderId, $transactionId, $amount, $targetTransactionId, $reference));
    }

    public function void(string $orderId, string $transactionId, string $targetTransactionId, ?string $reference = null): TransactionResponse
    {
        return $this->gateway->send(new VoidTransaction($orderId, $transactionId, $targetTransactionId, $reference));
    }

    public function updateAuthorization(string $orderId, string $transactionId, ?Money $amount = null, ?string $reference = null): TransactionResponse
    {
        return $this->gateway->send(new UpdateAuthorization($orderId, $transactionId, $amount, $reference));
    }
}
