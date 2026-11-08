<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Clients;

use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Responses\PaymentLink;
use IbnNajjaar\MIBGlobalPay\Core\Requests\CheckoutRequest;
use IbnNajjaar\MIBGlobalPay\Core\Responses\OperationResult;
use IbnNajjaar\MIBGlobalPay\Core\Operations\PaymentLink\DeletePaymentLink;
use IbnNajjaar\MIBGlobalPay\Core\Operations\PaymentLink\InitiatePaymentLink;

/**
 * Payment links.
 */
final readonly class PaymentLinkClient
{
    public function __construct(
        private Gateway $gateway,
    ) {
    }

    /** $request must have withPaymentLink() called on it. */
    public function create(CheckoutRequest $request): PaymentLink
    {
        return $this->gateway->send(new InitiatePaymentLink($request));
    }

    public function delete(string $linkId): OperationResult
    {
        return $this->gateway->send(new DeletePaymentLink($linkId));
    }
}
