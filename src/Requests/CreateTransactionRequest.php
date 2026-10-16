<?php

namespace IbnNajjaar\MIBGlobalPay\Requests;

use IbnNajjaar\MIBGlobalPay\Support\Arr;
use IbnNajjaar\MIBGlobalPay\Support\Amount;
use IbnNajjaar\MIBGlobalPay\Support\Request;
use IbnNajjaar\MIBGlobalPay\Requests\DataObjects\OrderData;
use IbnNajjaar\MIBGlobalPay\Requests\DataObjects\BaseOrderData;
use IbnNajjaar\MIBGlobalPay\Responses\DataObjects\CheckoutSessionResponseData;

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
        $order_data = $this->order_data;
        $is_order_data = $order_data instanceof OrderData;

        $data = [
            'apiOperation' => 'INITIATE_CHECKOUT',
            'order' => [
                'id' => $order_data->getOrderId(),
                'amount' => Amount::format($order_data->getOrderAmount()),
                'currency' => $order_data->getOrderCurrency(),
                'description' => $order_data->getOrderDescription(),
                'reference' => $order_data->getOrderReference(),
                'custom' => $is_order_data ? $order_data->getCustomFields() : null,
                'notificationUrl' => $order_data->getWebHookUrl(),
            ],
            'interaction' => [
                'operation' => $order_data->getInteractionOperation(),
                'cancelUrl' => $order_data->getCancelUrl(),
                'merchant' => [
                    'address' => [
                        'line1' => $order_data->getMerchantAddressLine1(),
                    ],
                    'email' => $order_data->getMerchantEmail(),
                    'logo' => $order_data->getMerchantLogoUrl(),
                    'name' => $order_data->getMerchantName(),
                    'phone' => $order_data->getMerchantPhone(),
                    'url' => $order_data->getMerchantUrl(),
                ],
                'redirectMerchantUrl' => $order_data->getRedirectMerchantUrl(),
                'retryAttemptCount' => $order_data->getRetryAttemptCount(),
                'returnUrl' => $order_data->getReturnUrl(),
                'locale' => $is_order_data ? $order_data->getLocale() : null,
                'action' => [
                    '3DSecure' => $is_order_data ? $order_data->getThreeDSecureAction() : null,
                ],
                'displayControl' => [
                    'cardSecurityCode' => $is_order_data ? $order_data->getCardSecurityCodeDisplay() : null,
                ],
            ],
            'transaction' => [
                'reference' => $is_order_data ? $order_data->getTransactionReference() : null,
                'source' => $is_order_data ? $order_data->getTransactionSource() : null,
            ],
        ];

        if ($is_order_data && $order_data->getExtraFields()) {
            $data = array_replace_recursive($data, $order_data->getExtraFields());
        }

        return Arr::filterNull($data);
    }

    public function getResponseDataClass(): ?string
    {
        return CheckoutSessionResponseData::class;
    }
}
