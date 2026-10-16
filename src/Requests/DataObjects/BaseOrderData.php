<?php

namespace IbnNajjaar\MIBGlobalPay\Requests\DataObjects;

use IbnNajjaar\MIBGlobalPay\Support\Amount;
use IbnNajjaar\MIBGlobalPay\Contracts\IsOrderData;
use IbnNajjaar\MIBGlobalPay\Support\Constants\InteractionOperation;

abstract class BaseOrderData implements IsOrderData
{
    private $order_id;
    private $amount;
    private $currency = 'MVR';
    private $interaction_operation = InteractionOperation::PURCHASE;

    public function __construct(
        string $order_id,
        float $amount
    ) {
        $this->setOrderId($order_id);
        $this->setOrderAmount($amount);
    }

    /**
     * @param mixed $order_id
     * @return void
     * @throws \InvalidArgumentException
     */
    public function setOrderId($order_id)
    {
        if (empty($order_id)) {
            throw new \InvalidArgumentException('Order ID is required');
        }

        if (!is_string($order_id) && !is_numeric($order_id)) {
            throw new \InvalidArgumentException('Order ID must be a string or number');
        }

        if (strlen((string) $order_id) > 40) {
            throw new \InvalidArgumentException('Order ID cannot exceed 40 characters');
        }

        $this->order_id = $order_id;
    }

    public function setOrderAmount(?float $amount = null)
    {
        Amount::validate($amount);

        $this->amount = $amount;
    }

    /**
     * @param mixed $currency
     * @return self
     * @throws \InvalidArgumentException
     */
    public function setOrderCurrency($currency): self
    {
        if (empty($currency)) {
            $this->currency = 'MVR';
            return $this;
        }

        $valid_currencies = array('USD', 'MVR');
        if (!in_array(strtoupper($currency), $valid_currencies)) {
            throw new \InvalidArgumentException('Invalid currency code');
        }

        $this->currency = strtoupper($currency);
        return $this;
    }

    /**
     * The Hosted Checkout operation: PURCHASE (default), AUTHORIZE (capture later), VERIFY or NONE.
     *
     * @throws \InvalidArgumentException
     */
    public function setInteractionOperation(string $operation): self
    {
        $operation = strtoupper(trim($operation));

        if (!in_array($operation, InteractionOperation::all(), true)) {
            throw new \InvalidArgumentException('Interaction operation must be one of: ' . implode(', ', InteractionOperation::all()));
        }

        $this->interaction_operation = $operation;
        return $this;
    }

    public function getInteractionOperation(): string
    {
        return $this->interaction_operation;
    }

    public function getOrderAmount(): float
    {
        return (float) $this->amount;
    }

    public function getOrderCurrency(): string
    {
        return $this->currency;
    }

    public function getOrderId(): string
    {
        return $this->order_id;
    }
}
