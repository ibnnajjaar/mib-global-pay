<?php

namespace IbnNajjaar\MIBGlobalPay\Requests;

use IbnNajjaar\MIBGlobalPay\Support\Arr;
use IbnNajjaar\MIBGlobalPay\Support\Path;
use IbnNajjaar\MIBGlobalPay\Support\Amount;
use IbnNajjaar\MIBGlobalPay\Support\Request;
use IbnNajjaar\MIBGlobalPay\Support\TransactionId;
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\TransactionResponseData;

/**
 * Base for operations on order/{orderId}/transaction/{transactionId}.
 */
abstract class TransactionOperationRequest extends Request
{
    protected $method = 'PUT';

    protected $order_id;
    protected $transaction_id;
    protected $reference;

    public function __construct(string $order_id, string $transaction_id, ?string $reference = null)
    {
        TransactionId::validate($order_id, 'Order ID');
        TransactionId::validate($transaction_id);

        if ($reference !== null && strlen($reference) > 40) {
            throw new \InvalidArgumentException('Transaction reference cannot exceed 40 characters');
        }

        $this->order_id = $order_id;
        $this->transaction_id = $transaction_id;
        $this->reference = $reference === '' ? null : $reference;
    }

    public function resolveEndpoint(): string
    {
        return 'order/' . Path::segment($this->order_id) . '/transaction/' . Path::segment($this->transaction_id);
    }

    public function getResponseDataClass(): ?string
    {
        return TransactionResponseData::class;
    }

    abstract protected function apiOperation(): string;

    protected function transactionData(): array
    {
        return [];
    }

    protected function defaultData(): array
    {
        return Arr::filterNull([
            'apiOperation' => $this->apiOperation(),
            'transaction' => array_merge($this->transactionData(), ['reference' => $this->reference]),
        ]);
    }

    /**
     * @param float|int|string $amount
     * @throws \InvalidArgumentException
     */
    protected static function formatAmount($amount): string
    {
        return Amount::format($amount);
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected static function formatCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('Currency must be a 3-letter ISO 4217 code');
        }

        return $currency;
    }
}
