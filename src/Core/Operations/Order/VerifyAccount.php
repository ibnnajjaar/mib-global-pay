<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Operations\Order;

use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Requests\StoredPayment;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * Transaction: Verify. Checks a saved token's account details without moving money.
 */
final readonly class VerifyAccount extends StoredPaymentOperation
{
    /**
     * @throws InvalidRequest
     */
    public function __construct(string $orderId, string $transactionId, public Currency $currency, StoredPayment $payment, ?string $reference = null)
    {
        parent::__construct($orderId, $transactionId, $payment, $reference);
    }

    protected function apiOperation(): string
    {
        return 'VERIFY';
    }

    protected function orderFields(GatewayConfig $config): array
    {
        if (! $config->allowsCurrency($this->currency)) {
            throw new InvalidRequest("Order currency {$this->currency->value} is not in the configured currency allow-list");
        }

        return ['currency' => $this->currency->value];
    }
}
