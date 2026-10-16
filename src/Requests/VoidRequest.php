<?php

namespace IbnNajjaar\MIBGlobalPay\Requests;

use IbnNajjaar\MIBGlobalPay\Support\TransactionId;

/**
 * Cancel an authorization, capture or payment before it is settled.
 * After settlement the gateway rejects the void; refund instead.
 */
class VoidRequest extends TransactionOperationRequest
{
    private $target_transaction_id;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $order_id,
        string $transaction_id,
        string $target_transaction_id,
        ?string $reference = null
    ) {
        parent::__construct($order_id, $transaction_id, $reference);
        TransactionId::validate($target_transaction_id, 'Target transaction ID');

        $this->target_transaction_id = $target_transaction_id;
    }

    protected function apiOperation(): string
    {
        return 'VOID';
    }

    protected function transactionData(): array
    {
        return [
            'targetTransactionId' => $this->target_transaction_id,
        ];
    }
}
