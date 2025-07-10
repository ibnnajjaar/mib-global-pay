<?php

namespace IbnNajjaar\MIBGlobalPay\Requests;

use IbnNajjaar\MIBGlobalPay\Support\Request;

class GetTransactionRequest extends Request
{
    protected $method = 'GET';
    private $order_reference;

    public function __construct(string $order_reference)
    {
        $this->order_reference = $order_reference;
    }

    public function resolveEndpoint(): string
    {
        return "order/{$this->order_reference}";
    }
}
