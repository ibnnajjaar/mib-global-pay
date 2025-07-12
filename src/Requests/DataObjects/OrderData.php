<?php

namespace IbnNajjaar\MIBGlobalPay\Requests\DataObjects;

use IbnNajjaar\MIBGlobalPay\Contracts\IsOrderData;
use IbnNajjaar\MIBGlobalPay\Support\Traits\HasUrl;
use IbnNajjaar\MIBGlobalPay\Requests\DataObjects\BaseOrderData;

class OrderData extends BaseOrderData
{

    use HasUrl;

    private $description = null;

    private $merchant_address_line1 = null;
    private $merchant_email = null;
    private $merchant_logo = null;
    private $merchant_name = null;
    private $merchant_phone = null;
    private $merchant_url = null;
    private $redirect_merchant_url = null;
    private $retry_attempt_count = 1;
    private $return_url = null;
    private $webhook_url = null;
    private $cancel_url = null;

    public function __construct(
        $order_id,
        $amount
    ) {
        parent::__construct($order_id, $amount);
    }


    public static function make(string $order_id, float $amount): self
    {
        return new self($order_id, $amount);
    }

    public static function fromArray(array $data): self
    {
        return self::make(
            $data['order_id'] ?? null,
            $data['amount'] ?? null
        )
            ->setOrderDescription($data['description'] ?? null)
            ->setReturnUrl($data['return_url'] ?? null)
            ->setMerchantAddressLine1($data['merchant_address_line1'] ?? null)
            ->setMerchantEmail($data['merchant_email'] ?? null)
            ->setMerchantLogo($data['merchant_logo'] ?? null)
            ->setMerchantName($data['merchant_name'] ?? null)
            ->setMerchantPhone($data['merchant_phone'] ?? null)
            ->setMerchantUrl($data['merchant_url'] ?? null)
            ->setRedirectMerchantUrl($data['redirect_merchant_url'] ?? null)
            ->setRetryAttemptCount($data['retry_attempt_count'] ?? 1)
            ->setWebhookUrl($data['webhook_url'] ?? null)
            ->setCancelUrl($data['cancel_url'] ?? null);
    }

    public function getOrderDescription(): ?string
    {
        return $this->description;
    }

    public function getReturnUrl()
    {
        return $this->return_url;
    }

    public function getMerchantAddressLine1()
    {
        return $this->merchant_address_line1;
    }

    public function getMerchantEmail()
    {
        return $this->merchant_email;
    }

    public function getMerchantLogo()
    {
        return $this->merchant_logo;
    }

    public function getMerchantName()
    {
        return $this->merchant_name;
    }

    public function getMerchantPhone()
    {
        return $this->merchant_phone;
    }

    public function getMerchantUrl()
    {
        return $this->merchant_url;
    }

    public function getRedirectMerchantUrl()
    {
        return $this->redirect_merchant_url;
    }

    public function getRetryAttemptCount(): int
    {
        return $this->retry_attempt_count;
    }

    public function getWebHookUrl()
    {
        return $this->webhook_url;
    }

    public function getCancelUrl()
    {
        return $this->cancel_url;
    }

    // Setters with validation

    /**
     * @param mixed $description
     * @return void
     * @throws \InvalidArgumentException
     */
    public function setOrderDescription(?string $description = null): self
    {
        if (empty($description)) {
            $this->description = null;
            return $this;
        }

        if (strlen($description) > 255) {
            throw new \InvalidArgumentException('Description cannot exceed 255 characters');
        }

        $this->description = $description;
        return $this;
    }

    /**
     * @param mixed $return_url
     * @return void
     * @throws \InvalidArgumentException
     */
    public function setReturnUrl($return_url): self
    {
        $this->validateUrl($return_url, 'Return URL');
        $this->return_url = $return_url;
        return $this;
    }

    // Set webhook URL, cancel URL, and merchant details if needed
    public function setWebhookUrl($webhook_url): self
    {
        $this->validateUrl($webhook_url, 'Webhook URL');
        $this->webhook_url = $webhook_url;
        return $this;
    }

    public function setCancelUrl($cancel_url): self
    {
        $this->validateUrl($cancel_url, 'Cancel URL');
        $this->cancel_url = $cancel_url;
        return $this;
    }

    public function setMerchantAddressLine1(?string $merchant_address_line1): self
    {
        $this->merchant_address_line1 = $merchant_address_line1;
        return $this;
    }

    // setMerchantName, setMerchantEmail, setMerchantLogo, setMerchantPhone, setMerchantUrl, setRedirectMerchantUrl methods
    public function setMerchantName(?string $merchant_name): self
    {
        $this->merchant_name = $merchant_name;
        return $this;
    }

    public function setMerchantEmail(?string $merchant_email): self
    {
        $this->merchant_email = $merchant_email;
        return $this;
    }

    /**
     * The URL of your business logo for display to the payer during the payment interaction.
     * The URL must be secure (e.g. https://yoursite.com/images/logo.gif). You can resize the image.However, the height must not exceed 140 pixels else it will be cropped. For best results, use images in JPEG, PNG, or SVG formats with dimensions 140 width × 140 height (pixels).
     * Data must be an absolute URI conforming to the URI syntax published by IETF RFC 2396. The URI must be one of the following schemes : https
     *
     * @param string|null $merchant_logo
     * @return self
     * */
    public function setMerchantLogo(?string $merchant_logo): self
    {
        $this->merchant_logo = $merchant_logo;
        return $this;
    }

    public function setMerchantPhone(?string $merchant_phone): self
    {
        $this->merchant_phone = $merchant_phone;
        return $this;
    }

    /*
     *
     * */
    public function setMerchantUrl(?string $merchant_url): self
    {
        $this->merchant_url = $merchant_url;
        return $this;
    }

    /**
     * The URL to which you want to return the payer after unsuccessful payment when retry attempts in the session are exhausted.
     * The URL should be used with retryAttemptCount for redirection.
     * Data must be an absolute URI conforming to the URI syntax published by IETF RFC 2396.
     * The following schemes are forbidden: JavaScript
     *
     * @param string|null $redirect_merchant_url
     * @return self
     * */
    public function setRedirectMerchantUrl(?string $redirect_merchant_url): self
    {
        $this->redirect_merchant_url = $redirect_merchant_url;
        return $this;
    }

    /**
     * The number of retry attempts allowed per session.
     * This is the number of time payer can retry attempts for the unsuccessful payment before it navigates to the merchant portal.
     * JSON number data type, restricted to being positive or zero. In addition, the represented number may have no fractional part.
     * Min value: 1 Max value: 3
     *
     * @param int $retry_attempt_count
     * @return self
     */
    public function setRetryAttemptCount(int $retry_attempt_count): self
    {
        if ($retry_attempt_count < 1) {
            throw new \InvalidArgumentException('Retry attempt count cannot be less than 1');
        }

        if ($retry_attempt_count > 3) {
            throw new \InvalidArgumentException('Retry attempt count cannot be more than 3');
        }

        $this->retry_attempt_count = $retry_attempt_count;
        return $this;
    }

}
