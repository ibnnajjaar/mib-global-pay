<?php

namespace IbnNajjaar\MIBGlobalPay\Requests;

use IbnNajjaar\MIBGlobalPay\Support\Request;
use IbnNajjaar\MIBGlobalPay\DataObjects\OrderData;

class CreateTransactionRequest extends Request
{
    protected $method = 'POST';

    /* @var OrderData $order */
    private $order;

    public function __construct(OrderData $order)
    {
        $this->order = $order;
    }

    public function resolveEndpoint(): string
    {
        return 'session';
    }

    protected function defaultData(): array
    {
        return [
            'apiOperation' => 'INITIATE_CHECKOUT',
            'order' => [
                'id' => $this->order->getOrderId(),
                'amount' => $this->order->getAmount(),
                'currency' => $this->order->getCurrency(),
                'description' => $this->order->getDescription(),
            ],
            'interaction' => [
                'returnUrl' => $this->order->getReturnUrl(),
            ],
        ];
    }
}
