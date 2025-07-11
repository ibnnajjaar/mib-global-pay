<?php

namespace IbnNajjaar\MIBGlobalPay\Support;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use IbnNajjaar\MIBGlobalPay\Support\Traits\SendsRequests;
use IbnNajjaar\MIBGlobalPay\Exceptions\MIBGlobalPayException;

abstract class Connector
{
    use SendsRequests;

    abstract protected function getApiPassword(): string;
    abstract protected function getMerchantId(): string;
    abstract protected function getMerchantPortalUrl(): string;
    abstract protected function getApiVersion(): string;

    public function resolveBaseUrl(): string
    {
        return "https://{$this->getMerchantPortalUrl()}/api/rest/version/{$this->getApiVersion()}/merchant/{$this->getMerchantId()}/";
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
        return [
            'merchant.' . $this->getMerchantId(),
            $this->getApiPassword(),
        ];
    }

    protected function getHttpClient(): GuzzleClient
    {
        return new GuzzleClient([
            'base_uri' => $this->resolveBaseUrl(),
            'timeout' => 30,
            'auth' => $this->defaultAuth(),
            'headers' => $this->defaultHeaders(),
        ]);
    }
}
