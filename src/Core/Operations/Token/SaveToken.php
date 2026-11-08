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
use IbnNajjaar\MIBGlobalPay\Core\Responses\TokenDetails;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * Tokenization: Create or Update Token, from the card details in a session. With no token, the gateway generates one.
 *
 * @implements Operation<TokenDetails>
 */
final readonly class SaveToken implements Operation
{
    public string $sessionId;

    public ?string $token;

    /**
     * @throws InvalidRequest
     */
    public function __construct(string $sessionId, ?string $token = null)
    {
        $this->sessionId = Validate::identifier($sessionId, 'Session ID');
        $this->token = $token === null ? null : Validate::identifier($token, 'Token');
    }

    public function method(): HttpMethod
    {
        return $this->token === null ? HttpMethod::Post : HttpMethod::Put;
    }

    public function scope(): PathScope
    {
        return PathScope::Merchant;
    }

    public function path(): string
    {
        return $this->token === null ? 'token' : 'token/' . RequestBody::segment($this->token);
    }

    public function body(GatewayConfig $config): array
    {
        return ['session' => ['id' => $this->sessionId], 'sourceOfFunds' => ['type' => 'CARD']];
    }

    public function isIdempotent(): bool
    {
        return $this->token !== null;
    }

    public function parse(Payload $payload, GatewayConfig $config): TokenDetails
    {
        return TokenDetails::fromPayload($payload);
    }
}
