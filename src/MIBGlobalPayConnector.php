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

    public function resolveBaseUrl(): string
    {
        return "https://{$this->merchant_portal_url}/api/rest/version/{$this->version}/merchant/{$this->merchant_id}/";
    }

    protected function defaultHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    protected function defaultAuth(): array
    {
        return ["merchant.{$this->merchant_id}", $this->api_password];
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): void
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

        // Clean up URL - remove protocol if present for validation
        $clean_url = preg_replace('/^https?:\/\//', '', $merchant_portal_url);

        if (!filter_var('https://' . $clean_url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid merchant portal URL.');
        }

        // Store clean URL without protocol
        $this->merchant_portal_url = $clean_url;
    }

    /**
     * @throws MIBGlobalPayException
     */
    public function createTransaction($orderData): Response
    {
        $order = $this->normalizeOrderData($orderData);
        return $this->send(new CreateTransactionRequest($order));
    }

    /**
     * Retrieve payment status by order reference
     * @throws MIBGlobalPayException
     */
    public function getTransactionStatus(string $orderReference): Response
    {
        return $this->send(new GetTransactionRequest($orderReference));
    }

    /**
     * Normalize order data to OrderData object
     */
    private function normalizeOrderData($orderData): OrderData
    {
        if (is_array($orderData)) {
            return OrderData::fromArray($orderData);
        } elseif ($orderData instanceof OrderData) {
            return $orderData;
        } else {
            throw new \InvalidArgumentException('Order data must be an array or OrderData instance');
        }
    }
}
