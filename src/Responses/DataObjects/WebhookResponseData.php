<?php

namespace IbnNajjaar\MIBGlobalPay\Responses\DataObjects;

use IbnNajjaar\MIBGlobalPay\Contracts\IsResponseData;

class WebhookResponseData implements IsResponseData
{
    /**
     * @var string|null
     */
    private $order_reference;

    /**
     * @var float|null
     */
    private $order_amount;

    /**
     * @var string|null
     */
    private $order_currency;

    /**
     * @var string|null
     */
    private $status;

    private $result;

    private $gateway_code;

    private $transaction_type;

    /**
     * @var string|null
     */
    private $notification_secret;

    /**
     * @var array|null
     */
    private $raw_response;

    public function __construct(
        ?string $order_reference = null,
        ?float $order_amount = null,
        ?string $status = null,
        ?string $order_currency = null,
        ?string $notification_secret = null,
        ?string $result = null,
        ?string $gateway_code = null,
        ?string $transaction_type = null,
        ?array $raw_response = null
    ) {
        $this->order_reference = $order_reference;
        $this->order_amount = $order_amount;
        $this->status = $status;
        $this->order_currency = $order_currency;
        $this->notification_secret = $notification_secret;
        $this->result = $result;
        $this->gateway_code = $gateway_code;
        $this->transaction_type = $transaction_type;
        $this->raw_response = $raw_response;
    }

    public static function fromArray(array $response, array $headers = []): self
    {
        return new self(
            $response['order']['id'] ?? null,
            isset($response['order']['amount']) ? (float) $response['order']['amount'] : null,
            $response['order']['status'] ? strtolower($response['order']['status']) : null,
            $response['order']['currency'] ?? null,
            $headers['x-notification-secret'][0] ?? null,
            $response['result'] ? strtolower($response['result']) : null,
            $response['response']['gatewayCode'] ? strtolower($response['response']['gatewayCode']) : null,
            $response['transaction']['type'] ? strtolower($response['transaction']['type']) : null,
            $response
        );
    }

    public function getOrderReference(): ?string
    {
        return $this->order_reference;
    }

    public function getOrderAmount(): ?float
    {
        return $this->order_amount;
    }

    public function getOrderCurrency(): ?string
    {
        return $this->order_currency;
    }

    public function getOrderStatus(): ?string
    {
        return strtolower($this->status);
    }

    public function getNotificationSecret(): ?string
    {
        return $this->notification_secret;
    }

    public function getResult(): ?string
    {
        return $this->result ? strtolower($this->result) : null;
    }

    public function getGatewayCode(): ?string
    {
        return $this->gateway_code ? strtolower($this->gateway_code) : null;
    }

    public function getTransactionType(): ?string
    {
        return $this->transaction_type ? strtolower($this->transaction_type) : null;
    }

    public function getRawResponse(): ?array
    {
        return $this->raw_response;
    }

    public function paymentIsSuccessful(): bool
    {
        return $this->getOrderStatus() === 'captured'
            && $this->getResult() === 'success'
            && $this->getGatewayCode() === 'approved'
            && $this->getTransactionType() === 'payment';
    }
}
