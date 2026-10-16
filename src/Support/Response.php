<?php

namespace IbnNajjaar\MIBGlobalPay\Support;

use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use IbnNajjaar\MIBGlobalPay\Contracts\IsResponseData;
use IbnNajjaar\MIBGlobalPay\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Exceptions\MIBGlobalPayException;

class Response
{
    /* @var ResponseInterface $response */
    protected $response;
    protected $response_data_class = null;
    protected $decoded_data = null;
    protected $body = null;

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
        if ($this->body === null) {
            $this->body = (string) $this->response->getBody();
        }

        return $this->body;
    }

    public function toArray(): array
    {
        if ($this->decoded_data === null) {
            $decoded = json_decode($this->getBody(), true);
            $this->decoded_data = is_array($decoded) ? $decoded : [];
        }

        return $this->decoded_data;
    }

    /**
     * The top-level "result" field (SUCCESS, FAILURE, PENDING, UNKNOWN, ERROR), if present.
     */
    public function getResult(): ?string
    {
        $result = $this->toArray()['result'] ?? null;

        return is_scalar($result) ? (string) $result : null;
    }

    /**
     * Convert response to CheckoutSessionData DTO
     *
     * @return IsResponseData
     * @throws MIBGlobalPayException
     */
    public function toDto(): IsResponseData
    {
        $this->throw();

        if (!$this->response_data_class) {
            throw new MIBGlobalPayException('This request has no response DTO');
        }

        return call_user_func([$this->response_data_class, 'fromArray'], $this->toArray());
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
     * @throws GatewayException
     */
    public function throw(): self
    {
        if ($this->isFailed()) {
            throw GatewayException::fromResponse($this->response, $this->getBody());
        }

        return $this;
    }
}
