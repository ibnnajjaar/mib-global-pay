<?php

namespace IbnNajjaar\MIBGlobalPay\Support;

use GuzzleHttp\Client as GuzzleClient;
use IbnNajjaar\MIBGlobalPay\Support\Traits\SendsRequests;

abstract class Connector
{
    use SendsRequests;

    /* @var GuzzleClient|null $http_client */
    protected $http_client = null;
    protected $timeout = 30;

    abstract protected function getApiPassword(): string;
    abstract protected function getMerchantId(): string;
    abstract protected function getMerchantPortalUrl(): string;
    abstract protected function getApiVersion(): string;

    public function resolveBaseUrl(): string
    {
        return "https://{$this->getMerchantPortalUrl()}/api/rest/version/{$this->getApiVersion()}/merchant/{$this->getMerchantId()}/";
    }

    /**
     * Use a custom Guzzle client, e.g. one with extra middleware or a mock handler.
     * Base URL, authentication, headers and timeout are applied per request.
     */
    public function setHttpClient(GuzzleClient $client): self
    {
        $this->http_client = $client;
        return $this;
    }

    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
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
        if ($this->http_client !== null) {
            return $this->http_client;
        }

        return new GuzzleClient();
    }
}
