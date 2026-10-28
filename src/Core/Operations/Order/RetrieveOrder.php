<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Operations\Order;

use IbnNajjaar\MIBGlobalPay\Core\Http\PathScope;
use IbnNajjaar\MIBGlobalPay\Core\Http\HttpMethod;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Requests\Validate;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Operation;
use IbnNajjaar\MIBGlobalPay\Core\Support\RequestBody;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Responses\OrderResponse;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * Transaction: Retrieve Order (GET /order/{orderId}).
 *
 * @implements Operation<OrderResponse>
 */
final readonly class RetrieveOrder implements Operation
{
    public string $orderId;

    /**
     * @throws InvalidRequest
     */
    public function __construct(string $orderId)
    {
        $this->orderId = Validate::identifier($orderId, 'Order ID');
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
        return 'order/' . RequestBody::segment($this->orderId);
    }

    public function body(GatewayConfig $config): ?array
    {
        return null;
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function parse(Payload $payload, GatewayConfig $config): OrderResponse
    {
        return OrderResponse::fromPayload($payload);
    }
}
