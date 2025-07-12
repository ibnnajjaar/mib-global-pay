<?php

namespace IbnNajjaar\MIBGlobalPay\Requests\DataObjects;

use IbnNajjaar\MIBGlobalPay\Contracts\IsOrderData;

abstract class BaseOrderData implements IsOrderData
{
    private $order_id;
    private $amount;
    private $currency;

    public function __construct(
        string $order_id,
        float $amount
    )
    {
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

        $this->order_id = $order_id;
    }

    public function setOrderAmount($amount)
    {
        if ($amount === null || $amount === '') {
            throw new \InvalidArgumentException('Amount is required');
        }

        if (!is_numeric($amount)) {
            throw new \InvalidArgumentException('Amount must be numeric');
        }

        $float_amount = (float) $amount;
        if ($float_amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        $amount_str = rtrim(rtrim((string) $amount, '0'), '.');
        if (strpos($amount_str, '.') !== false) {
            $decimal_part = substr($amount_str, strpos($amount_str, '.') + 1);
            if (strlen($decimal_part) > 2) {
                throw new \InvalidArgumentException('Amount cannot have more than 2 decimal places');
            }
        }

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

    public function getInteractionOperation(): string
    {
        return 'PURCHASE';
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

    private function validateUrl(string $url, string $fieldName)
    {
        if (empty($url)) {
            throw new \InvalidArgumentException("$fieldName is required");
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException("$fieldName must be a valid URL");
        }
    }
}
