<?php

namespace IbnNajjaar\MIBGlobalPay\DataObjects;

use IbnNajjaar\MIBGlobalPay\Support\IsResponseData;

class WebhookData implements IsResponseData
{
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
    private $order_reference;

    /*
     * @var string|null
     * */
    private $status;

    private $notification_host;
    private $notification_secret;

    private $raw_response;

    public function __construct(
        ?string $order_reference,
        ?float $order_amount,
        ?string $status,
        ?string $order_currency,
        ?string $notification_secret,
        ?string $notification_host,
        ?array $raw_response = null
    )
    {
        $this->order_reference = $order_reference;
        $this->order_amount = $order_amount;
        $this->status = $status;
        $this->order_currency = $order_currency;
        $this->notification_secret = $notification_secret;
        $this->notification_host = $notification_host;
        $this->raw_response = $raw_response;
    }

    /**
     * @param array $response
     * @return self
     * @throws \InvalidArgumentException
     */
    public static function fromArray(
        array $response,
        array $headers = []
    ): IsResponseData
    {
        return new self(
            $response['order']['id'] ?? null,
            $response['order']['amount'] ?? null,
            $response['order']['status'] ?? null,
            $response['order']['currency'] ?? null,
            $headers['x-notification-secret'][0] ?? null,
            $headers['host'][0] ?? null,
            $response
        );
    }

    public function getOrderReference(): ?string
    {
        return $this->order_reference;
    }

    public function getOrderAmount(): ?float
    {
        if ($this->order_amount === null) {
            return null;
        }

        return (float) $this->order_amount;
    }

    public function getOrderCurrency(): ?string
    {
        return $this->order_currency;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getNotificationSecret(): ?string
    {
        return $this->notification_secret;
    }

    public function getNotificationHost(): ?string
    {
        return $this->notification_host;
    }

    /**
     * @return array|null
     */
    public function getRawResponse(): ?array
    {
        return $this->raw_response;
    }
}
