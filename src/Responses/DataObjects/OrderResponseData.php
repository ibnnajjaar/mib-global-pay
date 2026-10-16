<?php

namespace IbnNajjaar\MIBGlobalPay\Responses\DataObjects;

use IbnNajjaar\MIBGlobalPay\Support\Arr;
use IbnNajjaar\MIBGlobalPay\Contracts\IsResponseData;
use IbnNajjaar\MIBGlobalPay\Support\Constants\TransactionType;

class OrderResponseData implements IsResponseData
{
    private $order_status;

    private $total_captured_amount;

    private $transactions;
    private $raw_response;

    public function __construct(?string $order_status, ?float $total_captured_amount, ?array $transactions, ?array $raw_response)
    {
        $this->order_status = $order_status;
        $this->total_captured_amount = $total_captured_amount;
        $this->transactions = $transactions;
        $this->raw_response = $raw_response;
    }

    public static function fromArray(array $response): IsResponseData
    {
        $transactions = Arr::get($response, 'transaction');
        $transactions_data = [];

        foreach (is_array($transactions) ? $transactions : [] as $transaction) {
            if (is_array($transaction)) {
                $transactions_data[] = TransactionResponseData::fromArray($transaction);
            }
        }

        return new self(
            Arr::lower(Arr::get($response, 'status')),
            Arr::float(Arr::get($response, 'totalCapturedAmount')),
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

    /**
     * @return TransactionResponseData[]|null
     */
    public function getTransactions(): ?array
    {
        return $this->transactions;
    }

    public function getRawResponse(): ?array
    {
        return $this->raw_response;
    }

    public function getOrderId(): ?string
    {
        return Arr::string($this->raw('id'));
    }

    public function getAmount(): ?float
    {
        return Arr::float($this->raw('amount'));
    }

    public function getCurrency(): ?string
    {
        return Arr::string($this->raw('currency'));
    }

    public function getTotalAuthorizedAmount(): ?float
    {
        return Arr::float($this->raw('totalAuthorizedAmount'));
    }

    public function getTotalRefundedAmount(): ?float
    {
        return Arr::float($this->raw('totalRefundedAmount'));
    }

    public function getReference(): ?string
    {
        return Arr::string($this->raw('reference'));
    }

    public function getCreationTime(): ?string
    {
        return Arr::string($this->raw('creationTime'));
    }

    /**
     * Amount captured and not yet refunded.
     */
    public function getRefundableAmount(): float
    {
        return max(0.0, round(($this->getTotalCapturedAmount() ?? 0.0) - ($this->getTotalRefundedAmount() ?? 0.0), 2));
    }

    /**
     * Amount authorized and not yet captured.
     */
    public function getCapturableAmount(): float
    {
        return max(0.0, round(($this->getTotalAuthorizedAmount() ?? 0.0) - ($this->getTotalCapturedAmount() ?? 0.0), 2));
    }

    /**
     * @return TransactionResponseData[]
     */
    public function getSuccessfulTransactions(): array
    {
        return array_values(array_filter($this->getTransactions() ?? [], function (TransactionResponseData $transaction) {
            return $transaction->isSuccessful();
        }));
    }

    public function findTransaction(string $transaction_id): ?TransactionResponseData
    {
        foreach ($this->getTransactions() ?? [] as $transaction) {
            if ($transaction->getTransactionId() === $transaction_id) {
                return $transaction;
            }
        }

        return null;
    }

    /**
     * The last transaction (in gateway order) whose type is one of the given types.
     *
     * @param string ...$types lowercase types, see TransactionType
     */
    public function getLatestTransactionOfType(string ...$types): ?TransactionResponseData
    {
        $types = array_map('strtolower', $types);
        $latest = null;

        foreach ($this->getTransactions() ?? [] as $transaction) {
            if (in_array($transaction->getType(), $types, true)) {
                $latest = $transaction;
            }
        }

        return $latest;
    }

    /**
     * True when money was captured through a successful, approved payment or capture.
     * Unlike paymentSuccessfullyCaptured(), this stays true after partial or full refunds.
     */
    public function isPaid(): bool
    {
        if (($this->getTotalCapturedAmount() ?? 0.0) <= 0) {
            return false;
        }

        foreach ($this->getTransactions() ?? [] as $transaction) {
            if (($transaction->isPayment() || $transaction->isCapture())
                && $transaction->isSuccessful()
                && $transaction->isApproved()
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @deprecated Use isPaid() — this returns false once any refund is made
     */
    public function paymentSuccessfullyCaptured(): bool
    {
        return $this->getOrderStatus() == 'captured'
            && $this->hasPaidTransaction();
    }

    public function hasPaidTransaction(): bool
    {
        foreach ($this->getTransactions() ?? [] as $transaction) {
            if ($transaction->getType() == TransactionType::PAYMENT
                && $transaction->getResult() == 'success'
                && $transaction->getGatewayCode() == 'approved'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return mixed
     */
    private function raw(string $path)
    {
        return Arr::get($this->raw_response ?? [], $path);
    }
}
