<?php

namespace IbnNajjaar\MIBGlobalPay\Support;

use LogicException;
use Psr\Http\Message\ResponseInterface;

abstract class Request
{
    protected $method;
    protected $data = [];
    protected $headers = [];
    protected $query = [];

    public function getMethod(): string
    {
        if (!isset($this->method) || !in_array($this->method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'])) {
            throw new LogicException('Your request is missing a HTTP method. You must add a method property like [protected string $method = "GET"]');
        }
        return $this->method;
    }

    abstract public function resolveEndpoint(): string;

    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function getData(): array
    {
        return array_merge($this->defaultData(), $this->data);
    }

    protected function defaultData(): array
    {
        return [];
    }

    public function setHeaders(array $headers): self
    {
        $this->headers = $headers;
        return $this;
    }

    public function getHeaders(): array
    {
        return array_merge($this->defaultHeaders(), $this->headers);
    }

    protected function defaultHeaders(): array
    {
        return [];
    }

    public function setQuery(array $query): self
    {
        $this->query = $query;
        return $this;
    }

    public function getQuery(): array
    {
        return array_merge($this->defaultQuery(), $this->query);
    }

    protected function defaultQuery(): array
    {
        return [];
    }

    abstract public function getResponseDataClass(): ?string;
}
