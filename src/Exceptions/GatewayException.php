<?php

namespace IbnNajjaar\MIBGlobalPay\Exceptions;

use Psr\Http\Message\ResponseInterface;

/**
 * Thrown when the gateway responds with an HTTP 4xx/5xx status.
 * The exception code is the HTTP status.
 */
class GatewayException extends MIBGlobalPayException
{
    private $http_status;
    private $error;
    private $raw_body;

    public function __construct(
        string $message,
        int $http_status,
        ?array $error = null,
        ?string $raw_body = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $http_status, $previous);
        $this->http_status = $http_status;
        $this->error = $error;
        $this->raw_body = $raw_body;
    }

    public static function fromResponse(ResponseInterface $response, ?string $body = null, ?\Throwable $previous = null): self
    {
        $status = $response->getStatusCode();
        if ($body === null) {
            $body = (string) $response->getBody();
        }

        $decoded = json_decode($body, true);
        $error = is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])
            ? $decoded['error']
            : null;

        $message = $error['explanation'] ?? $error['cause'] ?? ('HTTP ' . $status);

        return new self((string) $message, $status, $error, $body, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->http_status;
    }

    public function getErrorData(): ?array
    {
        return $this->error;
    }

    public function getErrorCause(): ?string
    {
        return $this->errorValue('cause');
    }

    public function getErrorExplanation(): ?string
    {
        return $this->errorValue('explanation');
    }

    public function getErrorField(): ?string
    {
        return $this->errorValue('field');
    }

    public function getValidationType(): ?string
    {
        return $this->errorValue('validationType');
    }

    public function getSupportCode(): ?string
    {
        return $this->errorValue('supportCode');
    }

    public function getRawBody(): ?string
    {
        return $this->raw_body;
    }

    public function isClientError(): bool
    {
        return $this->http_status >= 400 && $this->http_status < 500;
    }

    public function isServerError(): bool
    {
        return $this->http_status >= 500 && $this->http_status < 600;
    }

    public function isAuthenticationError(): bool
    {
        return $this->http_status === 401;
    }

    private function errorValue(string $key): ?string
    {
        if (!is_array($this->error) || !isset($this->error[$key]) || !is_scalar($this->error[$key])) {
            return null;
        }

        return (string) $this->error[$key];
    }
}
