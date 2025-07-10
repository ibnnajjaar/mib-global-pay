<?php

namespace IbnNajjaar\MIBGlobalPay\Support;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use IbnNajjaar\MIBGlobalPay\Support\Traits\SendsRequests;
use IbnNajjaar\MIBGlobalPay\Exceptions\MIBGlobalPayException;

abstract class Connector
{
    use SendsRequests;

    abstract public function resolveBaseUrl(): string;

    protected function defaultHeaders(): array
    {
        return [];
    }

    protected function defaultAuth(): array
    {
        return [];
    }

    protected function defaultQuery(): array
    {
        return [];
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
