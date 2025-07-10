<?php

namespace IbnNajjaar\MIBGlobalPay\Support;

use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Psr\Http\Message\ResponseInterface;
use IbnNajjaar\MIBGlobalPay\Exceptions\MIBGlobalPayException;

class Response
{
    /* @var ResponseInterface $response */
    protected $response;
    protected $decodedData = null;

    public function __construct(ResponseInterface $response)
    {
        $this->response = $response;
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    public function getHeaders(): array
    {
        return $this->response->getHeaders();
    }

    public function getBody(): string
    {
        return $this->response->getBody()->getContents();
    }

    public function json(): array
    {
        if ($this->decodedData === null) {
            $this->decodedData = json_decode($this->getBody(), true) ?? [];
        }

        return $this->decodedData;
    }

    public function isSuccessful(): bool
    {
        return $this->getStatusCode() >= 200 && $this->getStatusCode() < 300;
    }

    public function isFailed(): bool
    {
        return !$this->isSuccessful();
    }

    /**
     * @throws MIBGlobalPayException
     */
    public function throw(): self
    {
        if ($this->isFailed()) {
            $data = $this->json();
            $message = $data['error']['explanation'] ?? 'Request failed';
            throw new MIBGlobalPayException($message, $this->getStatusCode());
        }

        return $this;
    }
}
