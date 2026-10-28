<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Operations\Gateway;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Http\PathScope;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Http\HttpMethod;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Operation;
use IbnNajjaar\MIBGlobalPay\Core\Support\RequestBody;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Responses\PaymentOptions;

/**
 * Gateway: Payment Options Inquiry (POST /paymentOptionsInquiry). With no input, all
 * options for the merchant profile are returned. A POST, so it is not retried.
 *
 * @implements Operation<PaymentOptions>
 */
final readonly class PaymentOptionsInquiry implements Operation
{
    public function __construct(
        public ?Currency $currency = null,
        public ?Money $amount = null,
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
        return 'paymentOptionsInquiry';
    }

    public function body(GatewayConfig $config): array
    {
        /** @var array<string, mixed> */
        return RequestBody::withoutNulls([
            'order' => [
                'currency' => $this->amount !== null ? $this->amount->currency->value : $this->currency?->value,
                'amount' => $this->amount?->toDecimal(),
            ],
        ]);
    }

    public function isIdempotent(): bool
    {
        return false;
    }

    public function parse(Payload $payload, GatewayConfig $config): PaymentOptions
    {
        return PaymentOptions::fromPayload($payload);
    }
}
