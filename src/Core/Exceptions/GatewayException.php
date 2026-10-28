<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Exceptions;

/**
 * The gateway answered with HTTP 4xx/5xx. The exception code is the HTTP status.
 *
 * A declined operation is NOT a GatewayException: the gateway answers it with
 * HTTP 2xx and result FAILURE.
 */
final class GatewayException extends \RuntimeException implements MibGlobalPayException
{
    /**
     * @param array<string, mixed> $error the decoded "error" object of the response, if any
     */
    public function __construct(
        string $message,
        private readonly int $httpStatus,
        private readonly array $error = [],
        private readonly ?string $rawBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string, mixed> */
    public function error(): array
    {
        return $this->error;
    }

    public function cause(): ?string
    {
        return $this->errorString('cause');
    }

    public function explanation(): ?string
    {
        return $this->errorString('explanation');
    }

    public function field(): ?string
    {
        return $this->errorString('field');
    }

    public function validationType(): ?string
    {
        return $this->errorString('validationType');
    }

    public function supportCode(): ?string
    {
        return $this->errorString('supportCode');
    }

    public function rawBody(): ?string
    {
        return $this->rawBody;
    }

    public function isClientError(): bool
    {
        return $this->httpStatus >= 400 && $this->httpStatus < 500;
    }

    public function isServerError(): bool
    {
        return $this->httpStatus >= 500 && $this->httpStatus < 600;
    }

    public function isAuthenticationError(): bool
    {
        return $this->httpStatus === 401;
    }

    private function errorString(string $key): ?string
    {
        $value = $this->error[$key] ?? null;

        return is_scalar($value) && ! is_bool($value) && $value !== '' ? (string) $value : null;
    }
}
