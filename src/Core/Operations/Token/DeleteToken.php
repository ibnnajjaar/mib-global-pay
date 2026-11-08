<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Operations\Token;

use IbnNajjaar\MIBGlobalPay\Core\Http\PathScope;
use IbnNajjaar\MIBGlobalPay\Core\Http\HttpMethod;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Requests\Validate;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Operation;
use IbnNajjaar\MIBGlobalPay\Core\Support\RequestBody;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Core\Responses\OperationResult;

/**
 * Tokenization: Delete Token (DELETE /token/{tokenId}).
 *
 * @implements Operation<OperationResult>
 */
final readonly class DeleteToken implements Operation
{
    public string $token;

    /**
     * @throws InvalidRequest
     */
    public function __construct(string $token)
    {
        $this->token = Validate::identifier($token, 'Token');
    }

    public function method(): HttpMethod
    {
        return HttpMethod::Delete;
    }

    public function scope(): PathScope
    {
        return PathScope::Merchant;
    }

    public function path(): string
    {
        return 'token/' . RequestBody::segment($this->token);
    }

    public function body(GatewayConfig $config): ?array
    {
        return null;
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function parse(Payload $payload, GatewayConfig $config): OperationResult
    {
        return OperationResult::fromPayload($payload);
    }
}
