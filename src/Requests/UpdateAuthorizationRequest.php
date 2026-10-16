<?php

namespace IbnNajjaar\MIBGlobalPay\Requests;

/**
 * Change the authorized amount, or extend the authorization's validity when no amount is given.
 */
class UpdateAuthorizationRequest extends TransactionOperationRequest
{
    private $amount = null;
    private $currency = null;

    /**
     * @param float|int|string|null $amount
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $order_id,
        string $transaction_id,
        $amount = null,
        ?string $currency = null,
        ?string $reference = null
    ) {
        parent::__construct($order_id, $transaction_id, $reference);

        if (($amount === null) !== ($currency === null)) {
            throw new \InvalidArgumentException('Amount and currency must both be provided or both be omitted');
        }

        if ($amount !== null) {
            $this->amount = self::formatAmount($amount);
            $this->currency = self::formatCurrency($currency);
        }
    }

    protected function apiOperation(): string
    {
        return 'UPDATE_AUTHORIZATION';
    }

    protected function transactionData(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
        ];
    }
}
