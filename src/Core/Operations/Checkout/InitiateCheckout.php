<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Operations\Checkout;

use IbnNajjaar\MIBGlobalPay\Core\Http\PathScope;
use IbnNajjaar\MIBGlobalPay\Core\Http\HttpMethod;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Operation;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Requests\CheckoutRequest;
use IbnNajjaar\MIBGlobalPay\Core\Responses\CheckoutSession;

/**
 * Hosted Checkout: Initiate Checkout (POST /session, API v63+). Never retried.
 *
 * @implements Operation<CheckoutSession>
 */
final readonly class InitiateCheckout implements Operation
{
    public function __construct(
        public CheckoutRequest $request,
    ) {
    }

    public function method(): HttpMethod
    {
        return HttpMethod::Post;
    }

    public function scope(): PathScope
    {
        return PathScope::Merchant;
    }

    public function path(): string
    {
        return 'session';
    }

    public function body(GatewayConfig $config): array
    {
        return $this->request->toBody($config);
    }

    public function isIdempotent(): bool
    {
        return false;
    }

    public function parse(Payload $payload, GatewayConfig $config): CheckoutSession
    {
        return CheckoutSession::fromPayload($payload);
    }
}
