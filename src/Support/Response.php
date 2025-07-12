<?php

namespace IbnNajjaar\MIBGlobalPay\Support;

use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Psr\Http\Message\ResponseInterface;
use IbnNajjaar\MIBGlobalPay\Contracts\IsResponseData;
use IbnNajjaar\MIBGlobalPay\Exceptions\MIBGlobalPayException;

class Response
{
    /* @var ResponseInterface $response */
    protected $response;
    protected $response_data_class = null;
    protected $decoded_data = null;

    /**
     * @throws MIBGlobalPayException
     */
    public function __construct(ResponseInterface $response, ?string $response_data_class)
    {
        if ($response_data_class && !class_exists($response_data_class)) {
            throw new MIBGlobalPayException("Response data class {$response_data_class} does not exist.");
        }

        if ($response_data_class && !in_array(IsResponseData::class, class_implements($response_data_class))) {
            throw new MIBGlobalPayException("Response data class {$response_data_class} must implement " . IsResponseData::class);
        }

        $this->response = $response;
        $this->response_data_class = $response_data_class;
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

    public function toArray(): array
    {
        if ($this->decoded_data === null) {
            $this->decoded_data = json_decode($this->getBody(), true) ?? [];
        }

        return $this->decoded_data;
    }

    /**
     * Convert response to CheckoutSessionData DTO
     *
     * @return IsResponseData
     * @throws MIBGlobalPayException
     */
    public function toDto(): IsResponseData
    {
        if ($this->isSuccessful()) {
            return call_user_func([$this->response_data_class, 'fromArray'], $this->toArray());
        }

        $this->throw(); // Ensure response is successful
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
            $data = $this->toArray();
            $message = $data['error']['explanation'] ?? 'Request failed';
            throw new MIBGlobalPayException($message, $this->getStatusCode());
        }

        return $this;
    }
}
