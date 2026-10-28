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
use IbnNajjaar\MIBGlobalPay\Core\Requests\SessionUpdate;
use IbnNajjaar\MIBGlobalPay\Core\Responses\SessionDetails;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * Session: Update Session (PUT /session/{sessionId}). Not retried.
 *
 * @experimental unconfirmed for Hosted Checkout sessions (DESIGN-v2.md §12 Q4)
 *
 * @implements Operation<SessionDetails>
 */
final readonly class UpdateSession implements Operation
{
    public string $sessionId;

    /**
     * @throws InvalidRequest
     */
    public function __construct(string $sessionId, public SessionUpdate $update)
    {
        $this->sessionId = Validate::identifier($sessionId, 'Session ID', 35);
    }

    public function method(): HttpMethod
    {
        return HttpMethod::Put;
    }

    public function scope(): PathScope
    {
        return PathScope::Merchant;
    }

    public function path(): string
    {
        return 'session/' . RequestBody::segment($this->sessionId);
    }

    public function body(GatewayConfig $config): array
    {
        return $this->update->toBody($config);
    }

    public function isIdempotent(): bool
    {
        return false;
    }

    public function parse(Payload $payload, GatewayConfig $config): SessionDetails
    {
        return SessionDetails::fromPayload($payload);
    }
}
