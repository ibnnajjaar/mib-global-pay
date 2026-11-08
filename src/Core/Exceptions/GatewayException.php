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
    /** The documented values of error.cause, which are case sensitive. */
    private const array CAUSES = ['INVALID_REQUEST', 'REQUEST_REJECTED', 'SERVER_BUSY', 'SERVER_FAILED'];

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

    /**
     * Whether the raw body has the documented error format: a JSON object with result ERROR and an
     * error object whose cause is a documented value. Anything else, such as an HTML page from a
     * wrong host or a proxy, or some other JSON, says nothing about the request or the order.
     */
    public function isGatewayError(): bool
    {
        $body = json_decode($this->rawBody ?? '', true);

        if (! is_array($body) || ($body['result'] ?? null) !== 'ERROR') {
            return false;
        }

        $error = $body['error'] ?? null;

        return is_array($error) && in_array($error['cause'] ?? null, self::CAUSES, true);
    }

    /**
     * Whether the gateway refused the request because the merchant has not enabled the operation,
     * such as AUTHORIZE on a purchase-only merchant. The MIB gateway answers HTTP 400 with cause
     * INVALID_REQUEST, field interaction.operation and validationType INVALID (observed 2026-11-08).
     * This relies on those fields, not on the explanation, whose wording is not guaranteed.
     */
    public function isOperationNotEnabled(): bool
    {
        return $this->httpStatus === 400
            && $this->isGatewayError()
            && $this->cause() === 'INVALID_REQUEST'
            && $this->field() === 'interaction.operation'
            && $this->validationType() === 'INVALID';
    }

    private function errorString(string $key): ?string
    {
        $value = $this->error[$key] ?? null;

        return is_scalar($value) && ! is_bool($value) && $value !== '' ? (string) $value : null;
    }
}
