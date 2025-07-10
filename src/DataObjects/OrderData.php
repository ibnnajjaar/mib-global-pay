<?php

namespace IbnNajjaar\MIBGlobalPay\DataObjects;

class OrderData
{

    private $order_id;
    private $amount;
    private $currency;
    private $description;
    private $return_url;

    public function __construct($order_id, $amount, $currency, $description, $return_url)
    {
        $this->setOrderId($order_id);
        $this->setAmount($amount);
        $this->setCurrency($currency);
        $this->setDescription($description);
        $this->setReturnUrl($return_url);
    }

    /**
     * @param array $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['order_id'] ?? null,
            $data['amount'] ?? null,
            $data['currency'] ?? null,
            $data['description'] ?? null,
            $data['return_url'] ?? null
        );
    }

    // Getters
    public function getOrderId()
    {
        return $this->order_id;
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public function getCurrency()
    {
        return $this->currency;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function getReturnUrl()
    {
        return $this->return_url;
    }

    // Setters with validation

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

    /**
     * @param mixed $amount
     * @return void
     * @throws \InvalidArgumentException
     */
    public function setAmount($amount)
    {
        if ($amount === null || $amount === '') {
            throw new \InvalidArgumentException('Amount is required');
        }

        if (!is_numeric($amount)) {
            throw new \InvalidArgumentException('Amount must be numeric');
        }

        $floatAmount = (float) $amount;

        if ($floatAmount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        // Convert to string to check decimal places accurately
        $amountStr = rtrim(rtrim((string) $amount, '0'), '.');

        // Check for decimal places
        if (strpos($amountStr, '.') !== false) {
            $decimalPart = substr($amountStr, strpos($amountStr, '.') + 1);
            if (strlen($decimalPart) > 2) {
                throw new \InvalidArgumentException('Amount cannot have more than 2 decimal places');
            }
        }

        $this->amount = $amount;
    }

    /**
     * @param mixed $currency
     * @return void
     * @throws \InvalidArgumentException
     */
    public function setCurrency($currency)
    {
        if (empty($currency)) {
            throw new \InvalidArgumentException('Currency is required');
        }

        $validCurrencies = array('USD', 'MVR');
        if (!in_array(strtoupper($currency), $validCurrencies)) {
            throw new \InvalidArgumentException('Invalid currency code');
        }

        $this->currency = strtoupper($currency);
    }

    /**
     * @param mixed $description
     * @return void
     * @throws \InvalidArgumentException
     */
    public function setDescription($description)
    {
        if (empty($description)) {
            throw new \InvalidArgumentException('Description is required');
        }

        if (strlen($description) > 255) {
            throw new \InvalidArgumentException('Description cannot exceed 255 characters');
        }

        $this->description = $description;
    }

    /**
     * @param mixed $return_url
     * @return void
     * @throws \InvalidArgumentException
     */
    public function setReturnUrl($return_url)
    {
        if (empty($return_url)) {
            throw new \InvalidArgumentException('Return URL is required');
        }

        if (!filter_var($return_url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Return URL must be a valid URL');
        }

        $this->return_url = $return_url;
    }

    public function toArray(): array
    {
        return [
            'order_id' => $this->getOrderId(),
            'amount' => $this->getAmount(),
            'currency' => $this->getCurrency(),
            'description' => $this->getDescription(),
            'return_url' => $this->getReturnUrl(),
        ];
    }
}
