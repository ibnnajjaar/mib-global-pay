<?php

namespace IbnNajjaar\MIBGlobalPay;

use GuzzleHttp\Client as GuzzleClient;
class MIBGlobalPayConnector
{
    private $merchant_portal_url;
    private $merchant_id;
    private $api_password;
    private $http_client;

    private $transaction;

    private $version = 100;

    public function __construct(
        string $merchant_portal_url,
        string $merchant_id,
        string $api_password
    )
    {
        $this->validateCredentials($merchant_portal_url, $merchant_id, $api_password);

        $this->merchant_portal_url = $merchant_portal_url;
        $this->merchant_id = $merchant_id;
        $this->api_password = $api_password;
        $base_uri = "https://{$merchant_portal_url}/api/rest/version/{$this->getVersion()}/merchant/{$merchant_id}/";

        $this->http_client = new GuzzleClient([
            'base_uri' => $base_uri,
            'timeout' => 30,
            'auth' => ["merchant.{$merchant_id}", $api_password],
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);

        $this->transaction = new Transaction();
    }

    public static function fromEnv(): self
    {
        $merchant_portal_url = getenv('MIB_MERCHANT_URL');
        $merchant_id = getenv('MIB_MERCHANT_ID');
        $api_password = getenv('MIB_API_PASSWORD');
        return new self($merchant_portal_url, $merchant_id, $api_password);
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): void
    {
        $this->version = $version;
    }

    public function createTransaction(array $order_data): array
    {
        return $this->transaction->create($this->http_client, $order_data);
    }

    private function validateCredentials(
        string $merchant_portal_url,
        string $merchant_id,
        string $api_password
    ): void
    {
        if (empty($merchant_portal_url) || empty($merchant_id) || empty($api_password)) {
            throw new \InvalidArgumentException('Merchant portal URL, ID, and API password are required.');
        }

        if (!filter_var($merchant_portal_url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid merchant portal URL.');
        }
    }
}
