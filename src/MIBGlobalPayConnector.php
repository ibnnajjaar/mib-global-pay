<?php

namespace IbnNajjaar\MIBGlobalPay;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use IbnNajjaar\MIBGlobalPay\Support\Connector;
use IbnNajjaar\MIBGlobalPay\Support\Response;
use IbnNajjaar\MIBGlobalPay\Support\Request;
use IbnNajjaar\MIBGlobalPay\DataObjects\OrderData;
use IbnNajjaar\MIBGlobalPay\Requests\GetTransactionRequest;
use IbnNajjaar\MIBGlobalPay\Exceptions\MIBGlobalPayException;
use IbnNajjaar\MIBGlobalPay\Requests\CreateTransactionRequest;

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
    public function createTransaction($order_data): Response
    {
        $order = $this->normalizeOrderData($order_data);
        return $this->send(new CreateTransactionRequest($order));
    }

    /**
     * Retrieve payment status by order reference
     * @throws MIBGlobalPayException
     */
    public function getTransactionStatus(string $order_reference): Response
    {
        return $this->send(new GetTransactionRequest($order_reference));
    }

    private function normalizeOrderData($order_data): OrderData
    {
        if (is_array($order_data)) {
            return OrderData::fromArray($order_data);
        } elseif ($order_data instanceof OrderData) {
            return $order_data;
        } else {
            throw new \InvalidArgumentException('Order data must be an array or OrderData instance');
        }
    }

    protected function getApiPassword(): string
    {
        return $this->api_password;
    }

    protected function getMerchantId(): string
    {
        return $this->merchant_id;
    }

    protected function getMerchantPortalUrl(): string
    {
       return $this->merchant_portal_url;
    }

    protected function getApiVersion(): string
    {
        return (string)$this->version;
    }
}
