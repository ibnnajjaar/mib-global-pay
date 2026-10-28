<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Operations\Order;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Requests\Validate;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * Transaction: Refund, in full or in part, possibly several times. The gateway is the
 * source of truth for the refundable amount (OrderResponse::refundable()).
 */
final readonly class Refund extends TransactionOperation
{
    public ?string $targetTransactionId;

    /**
     * @param string|null $targetTransactionId the transaction to refund; the gateway picks one if omitted
     *
     * @throws InvalidRequest
     */
    public function __construct(
        string $orderId,
        string $transactionId,
        public Money $amount,
        ?string $targetTransactionId = null,
        ?string $reference = null,
    ) {
        parent::__construct($orderId, $transactionId, $reference);
        $this->targetTransactionId = $targetTransactionId === null ? null : Validate::identifier($targetTransactionId, 'Target transaction ID');
    }

    protected function apiOperation(): string
    {
        return 'REFUND';
    }

    protected function transactionFields(GatewayConfig $config): array
    {
        Validate::amount($this->amount, 'Refund amount', $config);

        return [
            'amount' => $this->amount->toDecimal(),
            'currency' => $this->amount->currency->value,
            'targetTransactionId' => $this->targetTransactionId,
        ];
    }
}
