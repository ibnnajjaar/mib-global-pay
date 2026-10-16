<?php

namespace IbnNajjaar\MIBGlobalPay\Requests;

/**
 * Capture funds from an authorization (checkout with interaction operation AUTHORIZE).
 */
class CaptureRequest extends TransactionOperationRequest
{
    private $amount;
    private $currency;

    /**
     * @param float|int|string $amount
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $order_id,
        string $transaction_id,
        $amount,
        string $currency,
        ?string $reference = null
    ) {
        parent::__construct($order_id, $transaction_id, $reference);

        $this->amount = self::formatAmount($amount);
        $this->currency = self::formatCurrency($currency);
    }

    protected function apiOperation(): string
    {
        return 'CAPTURE';
    }

    protected function transactionData(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
        ];
    }
}
