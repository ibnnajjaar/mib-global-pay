<?php

namespace IbnNajjaar\MIBGlobalPay\Requests;

use IbnNajjaar\MIBGlobalPay\Support\Request;

class GetTransactionRequest extends Request
{
    protected $method = 'GET';
    private $orderReference;

    public function __construct(string $orderReference)
    {
        $this->orderReference = $orderReference;
    }

    public function resolveEndpoint(): string
    {
        return "order/{$this->orderReference}";
    }
}
