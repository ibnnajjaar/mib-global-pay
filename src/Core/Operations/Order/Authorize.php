<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Operations\Order;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Requests\Validate;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Requests\StoredPayment;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * Transaction: Authorize. Reserves funds on a saved token; capture them later.
 */
final readonly class Authorize extends StoredPaymentOperation
{
    /**
     * @throws InvalidRequest
     */
    public function __construct(string $orderId, string $transactionId, public Money $amount, StoredPayment $payment, ?string $reference = null)
    {
        parent::__construct($orderId, $transactionId, $payment, $reference);
    }

    protected function apiOperation(): string
    {
        return 'AUTHORIZE';
    }

    protected function orderFields(GatewayConfig $config): array
    {
        Validate::amount($this->amount, 'Order amount', $config);

        return ['amount' => $this->amount->toDecimal(), 'currency' => $this->amount->currency->value];
    }
}
