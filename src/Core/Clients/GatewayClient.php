<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Clients;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Responses\GatewayStatus;
use IbnNajjaar\MIBGlobalPay\Core\Responses\PaymentOptions;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Gateway\CheckGateway;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Gateway\PaymentOptionsInquiry;

/**
 * Gateway-level operations.
 */
final readonly class GatewayClient
{
    public function __construct(
        private Gateway $gateway,
    ) {
    }

    public function check(): GatewayStatus
    {
        return $this->gateway->send(new CheckGateway());
    }

    public function paymentOptions(?Currency $currency = null, ?Money $amount = null): PaymentOptions
    {
        return $this->gateway->send(new PaymentOptionsInquiry($currency, $amount));
    }
}
