<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Operations\Session;

use IbnNajjaar\MIBGlobalPay\Core\Http\PathScope;
use IbnNajjaar\MIBGlobalPay\Core\Http\HttpMethod;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Requests\Validate;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Operation;
use IbnNajjaar\MIBGlobalPay\Core\Support\RequestBody;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Responses\SessionDetails;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * Session: Retrieve Session (GET /session/{sessionId}).
 *
 * @implements Operation<SessionDetails>
 */
final readonly class RetrieveSession implements Operation
{
    public string $sessionId;

    /**
     * @throws InvalidRequest
     */
    public function __construct(string $sessionId)
    {
        $this->sessionId = Validate::identifier($sessionId, 'Session ID', 35);
    }

    public function method(): HttpMethod
    {
        return HttpMethod::Get;
    }

    public function scope(): PathScope
    {
        return PathScope::Merchant;
    }

    public function path(): string
    {
        return 'session/' . RequestBody::segment($this->sessionId);
    }

    public function body(GatewayConfig $config): ?array
    {
        return null;
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function parse(Payload $payload, GatewayConfig $config): SessionDetails
    {
        return SessionDetails::fromPayload($payload);
    }
}
