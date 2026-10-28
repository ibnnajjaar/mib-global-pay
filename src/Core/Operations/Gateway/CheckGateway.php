<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Operations\Gateway;

use IbnNajjaar\MIBGlobalPay\Core\Http\PathScope;
use IbnNajjaar\MIBGlobalPay\Core\Http\HttpMethod;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Operation;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Responses\GatewayStatus;

/**
 * Gateway: Check Gateway (GET /api/rest/version/{v}/information). No authentication.
 *
 * @implements Operation<GatewayStatus>
 */
final readonly class CheckGateway implements Operation
{
    public function method(): HttpMethod
    {
        return HttpMethod::Get;
    }

    public function scope(): PathScope
    {
        return PathScope::Gateway;
    }

    public function path(): string
    {
        return 'information';
    }

    public function body(GatewayConfig $config): ?array
    {
        return null;
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function parse(Payload $payload, GatewayConfig $config): GatewayStatus
    {
        return GatewayStatus::fromPayload($payload);
    }
}
