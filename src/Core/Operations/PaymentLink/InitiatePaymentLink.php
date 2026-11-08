<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Operations\PaymentLink;

use IbnNajjaar\MIBGlobalPay\Core\Http\PathScope;
use IbnNajjaar\MIBGlobalPay\Core\Http\HttpMethod;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Operation;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Responses\PaymentLink;
use IbnNajjaar\MIBGlobalPay\Core\Requests\CheckoutRequest;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * Hosted Checkout: Initiate Checkout with checkoutMode PAYMENT_LINK. Never retried.
 *
 * @implements Operation<PaymentLink>
 */
final readonly class InitiatePaymentLink implements Operation
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
        if (! $this->request->isPaymentLink()) {
            throw new InvalidRequest('Call withPaymentLink() on the request first');
        }

        return $this->request->toBody($config);
    }

    public function isIdempotent(): bool
    {
        return false;
    }

    public function parse(Payload $payload, GatewayConfig $config): PaymentLink
    {
        return PaymentLink::fromPayload($payload);
    }
}
