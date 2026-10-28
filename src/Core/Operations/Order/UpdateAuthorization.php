<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Operations\Order;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Requests\Validate;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * Transaction: Update Authorization. Changes the authorized amount, or only extends the
 * authorization's validity when no amount is given.
 */
final readonly class UpdateAuthorization extends TransactionOperation
{
    /**
     * @throws InvalidRequest
     */
    public function __construct(string $orderId, string $transactionId, public ?Money $amount = null, ?string $reference = null)
    {
        parent::__construct($orderId, $transactionId, $reference);
    }

    protected function apiOperation(): string
    {
        return 'UPDATE_AUTHORIZATION';
    }

    protected function transactionFields(GatewayConfig $config): array
    {
        if ($this->amount === null) {
            return [];
        }

        Validate::amount($this->amount, 'Authorization amount', $config);

        return ['amount' => $this->amount->toDecimal(), 'currency' => $this->amount->currency->value];
    }
}
