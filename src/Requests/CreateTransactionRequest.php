<?php

namespace IbnNajjaar\MIBGlobalPay\Requests;

use IbnNajjaar\MIBGlobalPay\Support\Request;
use IbnNajjaar\MIBGlobalPay\Contracts\IsOrderData;
use IbnNajjaar\MIBGlobalPay\Requests\DataObjects\OrderData;
use IbnNajjaar\MIBGlobalPay\Requests\DataObjects\BaseOrderData;
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\CheckoutSessionData;

class CreateTransactionRequest extends Request
{
    protected $method = 'POST';

    /* @var BaseOrderData $order_data */
    private $order_data;

    public function __construct(BaseOrderData $order_data)
    {
        $this->order_data = $order_data;
    }

    public function resolveEndpoint(): string
    {
        return 'session';
    }

    public function defaultData(): array
    {
        return [
            'apiOperation' => 'INITIATE_CHECKOUT',
            'order' => [
                'id' => $this->order_data->getOrderId(),
                'amount' => $this->order_data->getOrderAmount(),
                'currency' => $this->order_data->getOrderCurrency(),
                'description' => $this->order_data->getOrderDescription(),
                'custom' => null,
                'notificationUrl' => $this->order_data->getWebHookUrl(),
            ],
            'interaction' => [
                'operation' => 'PURCHASE',
                'cancelUrl' => $this->order_data->getCancelUrl(),
                'merchant' => [
                    'address' => [
                        'line1' => $this->order_data->getMerchantAddressLine1(),
                    ],
                    'email' => $this->order_data->getMerchantEmail(),
                    'logo' => $this->order_data->getMerchantLogo(),
                    'name' => $this->order_data->getMerchantName(),
                    'phone' => $this->order_data->getMerchantPhone(),
                    'url' => $this->order_data->getMerchantUrl(),
                ],
                'redirectMerchantUrl' => $this->order_data->getRedirectMerchantUrl(),
                'retryAttemptCount' => $this->order_data->getRetryAttemptCount(),
                'returnUrl' => $this->order_data->getReturnUrl(),
            ],
        ];
    }

    public function getResponseDataClass(): ?string
    {
        return CheckoutSessionData::class;
    }
}
