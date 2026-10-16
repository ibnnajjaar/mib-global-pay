<?php

namespace IbnNajjaar\MIBGlobalPay\Requests;

use IbnNajjaar\MIBGlobalPay\Support\Path;
use IbnNajjaar\MIBGlobalPay\Support\Request;
use IbnNajjaar\MIBGlobalPay\Support\TransactionId;
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\TransactionResponseData;

class RetrieveTransactionRequest extends Request
{
    protected $method = 'GET';

    private $order_id;
    private $transaction_id;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(string $order_id, string $transaction_id)
    {
        TransactionId::validate($order_id, 'Order ID');
        TransactionId::validate($transaction_id);

        $this->order_id = $order_id;
        $this->transaction_id = $transaction_id;
    }

    public function resolveEndpoint(): string
    {
        return 'order/' . Path::segment($this->order_id) . '/transaction/' . Path::segment($this->transaction_id);
    }

    public function getResponseDataClass(): ?string
    {
        return TransactionResponseData::class;
    }
}
