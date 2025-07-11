<?php

namespace IbnNajjaar\MIBGlobalPay\DataObjects;

use IbnNajjaar\MIBGlobalPay\Support\IsResponseData;

class WebhookData implements IsResponseData
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
        ?array $raw_response = null
    ) {
        $this->order_reference = $order_reference;
        $this->order_amount = $order_amount;
        $this->status = $status;
        $this->order_currency = $order_currency;
        $this->notification_secret = $notification_secret;
        $this->raw_response = $raw_response;
    }

    public static function fromArray(array $response, array $headers = []): IsResponseData
    {
        return new self(
            $response['order']['id'] ?? null,
            isset($response['order']['amount']) ? (float) $response['order']['amount'] : null,
            $response['order']['status'] ?? null,
            $response['order']['currency'] ?? null,
            $headers['x-notification-secret'][0] ?? null,
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

    public function getStatus(): ?string
    {
        return strtolower($this->status);
    }

    public function getNotificationSecret(): ?string
    {
        return $this->notification_secret;
    }

    public function getRawResponse(): ?array
    {
        return $this->raw_response;
    }

    public function paymentIsSuccessful(): bool
    {
        return $this->getStatus() === 'captured';
    }
}
