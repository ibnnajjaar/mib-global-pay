<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Operations\Order;

use IbnNajjaar\MIBGlobalPay\Core\Requests\Validate;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * Transaction: Void. Reverses a previous transaction; "typically voids will only be
 * successful when processed not long after the original transaction". Refund after settlement.
 *
 * (Named VoidTransaction because "void" is a reserved word in PHP.)
 */
final readonly class VoidTransaction extends TransactionOperation
{
    public string $targetTransactionId;

    /**
     * @throws InvalidRequest
     */
    public function __construct(string $orderId, string $transactionId, string $targetTransactionId, ?string $reference = null)
    {
        parent::__construct($orderId, $transactionId, $reference);
        $this->targetTransactionId = Validate::identifier($targetTransactionId, 'Target transaction ID');
    }

    protected function apiOperation(): string
    {
        return 'VOID';
    }

    protected function transactionFields(GatewayConfig $config): array
    {
        return ['targetTransactionId' => $this->targetTransactionId];
    }
}
