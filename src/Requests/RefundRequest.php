<?php

namespace IbnNajjaar\MIBGlobalPay\Requests;

use IbnNajjaar\MIBGlobalPay\Support\TransactionId;

/**
 * Refund a captured or purchased amount, in full or in part.
 */
class RefundRequest extends TransactionOperationRequest
{
    private $amount;
    private $currency;
    private $target_transaction_id;

    /**
     * @param float|int|string $amount
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $order_id,
        string $transaction_id,
        $amount,
        string $currency,
        ?string $target_transaction_id = null,
        ?string $reference = null
    ) {
        parent::__construct($order_id, $transaction_id, $reference);

        if ($target_transaction_id !== null) {
            TransactionId::validate($target_transaction_id, 'Target transaction ID');
        }

        $this->amount = self::formatAmount($amount);
        $this->currency = self::formatCurrency($currency);
        $this->target_transaction_id = $target_transaction_id;
    }

    protected function apiOperation(): string
    {
        return 'REFUND';
    }

    protected function transactionData(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'targetTransactionId' => $this->target_transaction_id,
        ];
    }
}
