<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Operations\Order;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Requests\Validate;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * Transaction: Capture. Captures funds from an authorization, in full or in part.
 */
final readonly class Capture extends TransactionOperation
{
    /**
     * @throws InvalidRequest
     */
    public function __construct(string $orderId, string $transactionId, public Money $amount, ?string $reference = null)
    {
        parent::__construct($orderId, $transactionId, $reference);
    }

    protected function apiOperation(): string
    {
        return 'CAPTURE';
    }

    protected function transactionFields(GatewayConfig $config): array
    {
        Validate::amount($this->amount, 'Capture amount', $config);

        return ['amount' => $this->amount->toDecimal(), 'currency' => $this->amount->currency->value];
    }
}
