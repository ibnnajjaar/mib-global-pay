<?php

namespace IbnNajjaar\MIBGlobalPay\Contracts;

interface IsOrderData
{

    public function getInteractionOperation(): string;
    public function getOrderAmount(): float;
    public function getOrderCurrency(): string;
    public function getOrderId(): string;
}
