<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Clients;

use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Requests\CheckoutRequest;
use IbnNajjaar\MIBGlobalPay\Core\Responses\CheckoutSession;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Checkout\InitiateCheckout;

/**
 * Hosted Checkout operations.
 */
final readonly class CheckoutClient
{
    public function __construct(
        private Gateway $gateway,
    ) {
    }

    public function initiate(CheckoutRequest $request): CheckoutSession
    {
        return $this->gateway->send(new InitiateCheckout($request));
    }
}
