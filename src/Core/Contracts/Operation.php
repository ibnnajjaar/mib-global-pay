<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Contracts;

use IbnNajjaar\MIBGlobalPay\Core\Http\PathScope;
use IbnNajjaar\MIBGlobalPay\Core\Http\HttpMethod;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;

/**
 * One gateway API operation. Adding a feature means adding an Operation and its response DTO.
 *
 * @template-covariant TResponse of object
 */
interface Operation
{
    public function method(): HttpMethod;

    public function scope(): PathScope;

    /** Path relative to the scope's base URL, with every identifier already URL-encoded. */
    public function path(): string;

    /**
     * The JSON body, or null for no body. Validates the request against the configuration
     * (currency allow-list, HTTPS URLs, ...).
     *
     * @return array<string, mixed>|null
     *
     * @throws InvalidRequest
     */
    public function body(GatewayConfig $config): ?array;

    /**
     * Whether an identical resend is safe, so the opt-in retry policy may retry it.
     * GET operations and PUT operations with a fixed transaction ID are idempotent;
     * Initiate Checkout is not.
     */
    public function isIdempotent(): bool;

    /**
     * @return TResponse
     *
     * @throws MalformedPayload
     */
    public function parse(Payload $payload, GatewayConfig $config): object;
}
