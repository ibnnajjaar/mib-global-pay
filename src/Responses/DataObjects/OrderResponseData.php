<?php

namespace IbnNajjaar\MIBGlobalPay\Responses\DataObjects;

use IbnNajjaar\MIBGlobalPay\Contracts\IsResponseData;

class OrderResponseData implements IsResponseData
{
    private $order_status;

    private $total_captured_amount;

    private $transactions;
    private $raw_response;

    public function __construct(?string $order_status, ?string $total_captured_amount, ?array $transactions, ?array $raw_response)
    {
        $this->order_status = $order_status;
        $this->total_captured_amount = $total_captured_amount;
        $this->transactions = $transactions;
        $this->raw_response = $raw_response;
    }

    public static function fromArray(array $response): IsResponseData
    {
        $transactions = $response['transaction'] ?? [];
        $transactions_data = [];

        foreach ($transactions as $transaction) {
            if (is_array($transaction)) {
                $transactions_data[] = TransactionResponseData::fromArray($transaction);
            }
        }

        return new self(
            $response['status'] ? strtolower($response['status']) : null,
            isset($response['totalCapturedAmount']) ? (float) $response['totalCapturedAmount'] : null,
            $transactions_data,
            $response
        );
    }

    public function getOrderStatus(): ?string
    {
        return $this->order_status ? strtolower($this->order_status) : null;
    }

    public function getTotalCapturedAmount(): ?float
    {
        return $this->total_captured_amount;
    }

    public function getTransactions(): ?array
    {
        return $this->transactions;
    }

    public function getRawResponse(): ?array
    {
        return $this->raw_response;
    }

    public function paymentSuccessfullyCaptured(): bool
    {
        return $this->getOrderStatus() == 'captured'
            && $this->hasPaidTransaction();
    }

    public function hasPaidTransaction(): bool
    {
        foreach ($this->getTransactions() as $transaction) {
            if ($transaction->getType() == 'payment'
                && $transaction->getResult() == 'success'
                && $transaction->getGatewayCode() == 'approved'
            ) {
                return true;
            }
        }

        return false;
    }
}
