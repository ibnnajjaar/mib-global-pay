<?php

namespace IbnNajjaar\MIBGlobalPay;

use IbnNajjaar\MIBGlobalPay\Support\Response;
use IbnNajjaar\MIBGlobalPay\Support\Connector;
use IbnNajjaar\MIBGlobalPay\Requests\VoidRequest;
use IbnNajjaar\MIBGlobalPay\Requests\RefundRequest;
use IbnNajjaar\MIBGlobalPay\Requests\CaptureRequest;
use IbnNajjaar\MIBGlobalPay\Requests\GetTransactionRequest;
use IbnNajjaar\MIBGlobalPay\Exceptions\MIBGlobalPayException;
use IbnNajjaar\MIBGlobalPay\Requests\CreateTransactionRequest;
use IbnNajjaar\MIBGlobalPay\Requests\DataObjects\BaseOrderData;
use IbnNajjaar\MIBGlobalPay\Requests\RetrieveTransactionRequest;
use IbnNajjaar\MIBGlobalPay\Requests\UpdateAuthorizationRequest;

class MIBGlobalPayConnector extends Connector
{
    private $merchant_portal_url;
    private $merchant_id;
    private $api_password;
    private $version = 100;

    public function __construct(
        string $merchant_portal_url,
        string $merchant_id,
        string $api_password
    ) {
        $this->validateCredentials($merchant_portal_url, $merchant_id, $api_password);
        $this->merchant_portal_url = $merchant_portal_url;
        $this->merchant_id = $merchant_id;
        $this->api_password = $api_password;
    }

    public function setApiVersion(int $version): void
    {
        $this->version = $version;
    }

    private function validateCredentials(
        string $merchant_portal_url,
        string $merchant_id,
        string $api_password
    ): void {
        if (empty($merchant_portal_url) || empty($merchant_id) || empty($api_password)) {
            throw new \InvalidArgumentException('Merchant portal URL, ID, and API password are required.');
        }
        $clean_url = preg_replace('/^https?:\/\//', '', $merchant_portal_url);
        if (!filter_var('https://' . $clean_url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid merchant portal URL.');
        }
        $this->merchant_portal_url = $clean_url;
    }

    /**
     * @throws MIBGlobalPayException
     */
    public function createTransaction(BaseOrderData $order_data): Response
    {
        return $this->send(new CreateTransactionRequest($order_data));
    }

    /**
     * Retrieve payment status by order reference
     * @throws MIBGlobalPayException
     */
    public function getOrderDetails(string $order_reference): Response
    {
        return $this->send(new GetTransactionRequest($order_reference));
    }

    /**
     * Refund a captured or purchased amount, in full or in part.
     * $transaction_id is a new ID, unique within the order (see TransactionId::generate()).
     *
     * @param float|int|string $amount
     * @throws MIBGlobalPayException
     */
    public function refund(
        string $order_id,
        string $transaction_id,
        $amount,
        string $currency,
        ?string $target_transaction_id = null,
        ?string $reference = null
    ): Response {
        return $this->send(new RefundRequest($order_id, $transaction_id, $amount, $currency, $target_transaction_id, $reference));
    }

    /**
     * Cancel an authorization, capture or payment before settlement. After settlement, refund instead.
     *
     * @throws MIBGlobalPayException
     */
    public function void(
        string $order_id,
        string $transaction_id,
        string $target_transaction_id,
        ?string $reference = null
    ): Response {
        return $this->send(new VoidRequest($order_id, $transaction_id, $target_transaction_id, $reference));
    }

    /**
     * Capture funds from an authorization.
     *
     * @param float|int|string $amount
     * @throws MIBGlobalPayException
     */
    public function capture(
        string $order_id,
        string $transaction_id,
        $amount,
        string $currency,
        ?string $reference = null
    ): Response {
        return $this->send(new CaptureRequest($order_id, $transaction_id, $amount, $currency, $reference));
    }

    /**
     * Change the authorized amount, or extend the authorization when amount and currency are omitted.
     *
     * @param float|int|string|null $amount
     * @throws MIBGlobalPayException
     */
    public function updateAuthorization(
        string $order_id,
        string $transaction_id,
        $amount = null,
        ?string $currency = null,
        ?string $reference = null
    ): Response {
        return $this->send(new UpdateAuthorizationRequest($order_id, $transaction_id, $amount, $currency, $reference));
    }

    /**
     * Retrieve a single transaction of an order.
     *
     * @throws MIBGlobalPayException
     */
    public function getTransactionDetails(string $order_id, string $transaction_id): Response
    {
        return $this->send(new RetrieveTransactionRequest($order_id, $transaction_id));
    }

    protected function getApiPassword(): string
    {
        return $this->api_password;
    }

    protected function getMerchantId(): string
    {
        return $this->merchant_id;
    }

    public function getMerchantPortalUrl(): string
    {
        return $this->merchant_portal_url;
    }

    public function getApiVersion(): string
    {
        return (string)$this->version;
    }
}
