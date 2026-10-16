<?php

namespace IbnNajjaar\MIBGlobalPay\Requests\DataObjects;

use IbnNajjaar\MIBGlobalPay\Contracts\IsOrderData;
use IbnNajjaar\MIBGlobalPay\Support\Traits\HasUrl;
use IbnNajjaar\MIBGlobalPay\Requests\DataObjects\BaseOrderData;

class OrderData extends BaseOrderData
{
    use HasUrl;

    private $description = null;
    private $order_reference = null;

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

    private $custom_fields = [];
    private $three_d_secure_action = null;
    private $card_security_code_display = null;
    private $locale = null;
    private $transaction_source = null;
    private $transaction_reference = null;
    private $extra_fields = [];

    private const THREE_D_SECURE_ACTIONS = ['BYPASS', 'MANDATORY', 'USE_GATEWAY_RECOMMENDATION'];
    private const CARD_SECURITY_CODE_DISPLAY_MODES = ['MANDATORY', 'OPTIONAL'];
    private const TRANSACTION_SOURCES = [
        'CALL_CENTRE', 'CARD_PRESENT', 'INTERNET', 'MAIL_ORDER', 'MERCHANT', 'MOTO', 'TELEPHONE_ORDER', 'VOICE_RESPONSE',
    ];

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
        if (!isset($data['order_id']) || $data['order_id'] === '') {
            throw new \InvalidArgumentException('order_id is required');
        }

        if (!isset($data['amount']) || $data['amount'] === '') {
            throw new \InvalidArgumentException('amount is required');
        }

        $order = self::make(
            (string) $data['order_id'],
            $data['amount']
        )
            ->setOrderCurrency($data['currency'] ?? null)
            ->setOrderDescription($data['description'] ?? null)
            ->setOrderReference($data['order_reference'] ?? null)
            ->setReturnUrl($data['return_url'] ?? null)
            ->setMerchantAddressLine1($data['merchant_address_line1'] ?? null)
            ->setMerchantEmail($data['merchant_email'] ?? null)
            ->setMerchantLogoUrl($data['merchant_logo'] ?? null)
            ->setMerchantName($data['merchant_name'] ?? null)
            ->setMerchantPhone($data['merchant_phone'] ?? null)
            ->setMerchantUrl($data['merchant_url'] ?? null)
            ->setRedirectMerchantUrl($data['redirect_merchant_url'] ?? null)
            ->setRetryAttemptCount($data['retry_attempt_count'] ?? 1)
            ->setWebhookUrl($data['webhook_url'] ?? null)
            ->setCancelUrl($data['cancel_url'] ?? null)
            ->setThreeDSecureAction($data['three_d_secure_action'] ?? null)
            ->setCardSecurityCodeDisplay($data['card_security_code_display'] ?? null)
            ->setLocale($data['locale'] ?? null)
            ->setTransactionSource($data['transaction_source'] ?? null)
            ->setTransactionReference($data['transaction_reference'] ?? null)
            ->setExtraFields($data['extra_fields'] ?? []);

        if (isset($data['interaction_operation'])) {
            $order->setInteractionOperation($data['interaction_operation']);
        }

        foreach ($data['custom_fields'] ?? [] as $key => $value) {
            $order->setCustomField((string) $key, (string) $value);
        }

        return $order;
    }

    public function getOrderDescription(): ?string
    {
        return $this->description;
    }

    public function getOrderReference(): ?string
    {
        return $this->order_reference;
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

    public function getMerchantLogoUrl()
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

        if (strlen($description) > 127) {
            throw new \InvalidArgumentException('Description cannot exceed 127 characters');
        }

        $this->description = $description;
        return $this;
    }

    /**
     * @param string|null $order_reference
     * @return self
     * @throws \InvalidArgumentException
     */
    public function setOrderReference(?string $order_reference = null): self
    {
        if (empty($order_reference)) {
            $this->order_reference = null;
            return $this;
        }

        if (strlen($order_reference) > 40) {
            throw new \InvalidArgumentException('Order reference cannot exceed 40 characters');
        }

        $this->order_reference = $order_reference;
        return $this;
    }


    /**
     * @param mixed $return_url
     * @return void
     * @throws \InvalidArgumentException
     */
    public function setReturnUrl($return_url): self
    {
        $this->trimUrl($return_url);
        $this->validateUrl($return_url, 'Return URL');
        $this->return_url = $return_url;
        return $this;
    }

    // Set webhook URL, cancel URL, and merchant details if needed
    public function setWebhookUrl($webhook_url): self
    {
        $this->trimUrl($webhook_url);
        $this->validateUrl($webhook_url, 'Webhook URL');
        $this->webhook_url = $webhook_url;
        return $this;
    }

    public function setCancelUrl($cancel_url): self
    {
        $this->trimUrl($cancel_url);
        $this->validateUrl($cancel_url, 'Cancel URL');
        $this->cancel_url = $cancel_url;
        return $this;
    }

    public function setMerchantAddressLine1(?string $merchant_address_line1): self
    {
        $this->merchant_address_line1 = $merchant_address_line1;
        return $this;
    }

    /**
     * The name of your business shown to the payer (max 40 characters).
     * The gateway API marks interaction.merchant.name as required; set it unless
     * your merchant profile provides a default.
     *
     * @throws \InvalidArgumentException
     */
    public function setMerchantName(?string $merchant_name): self
    {
        if ($merchant_name !== null && strlen($merchant_name) > 40) {
            throw new \InvalidArgumentException('Merchant name cannot exceed 40 characters');
        }

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
    public function setMerchantLogoUrl(?string $merchant_logo): self
    {
        $this->trimUrl($merchant_logo);
        $this->validateUrl($merchant_logo, 'Merchant Logo URL');
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
        $this->trimUrl($merchant_url);
        $this->validateUrl($merchant_url, 'Merchant URL');
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
        $this->trimUrl($redirect_merchant_url);
        $this->validateUrl($redirect_merchant_url, 'Redirect Merchant URL');
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

    /**
     * Sent as order.custom.{key}. Up to 50 fields.
     *
     * @throws \InvalidArgumentException
     */
    public function setCustomField(string $key, string $value): self
    {
        if (!preg_match('/^[A-Za-z0-9]{1,99}$/', $key)) {
            throw new \InvalidArgumentException('Custom field key must be 1-99 alphanumeric characters');
        }

        if ($value === '' || strlen($value) > 250) {
            throw new \InvalidArgumentException('Custom field value must be 1-250 characters');
        }

        if (!array_key_exists($key, $this->custom_fields) && count($this->custom_fields) >= 50) {
            throw new \InvalidArgumentException('Cannot set more than 50 custom fields');
        }

        $this->custom_fields[$key] = $value;
        return $this;
    }

    public function getCustomFields(): array
    {
        return $this->custom_fields;
    }

    /**
     * Sent as interaction.action.3DSecure: BYPASS, MANDATORY or USE_GATEWAY_RECOMMENDATION.
     *
     * @throws \InvalidArgumentException
     */
    public function setThreeDSecureAction(?string $action): self
    {
        $this->three_d_secure_action = $this->enumValue($action, self::THREE_D_SECURE_ACTIONS, '3-D Secure action');
        return $this;
    }

    public function getThreeDSecureAction(): ?string
    {
        return $this->three_d_secure_action;
    }

    /**
     * Sent as interaction.displayControl.cardSecurityCode: OPTIONAL or MANDATORY.
     *
     * @throws \InvalidArgumentException
     */
    public function setCardSecurityCodeDisplay(?string $mode): self
    {
        $this->card_security_code_display = $this->enumValue($mode, self::CARD_SECURITY_CODE_DISPLAY_MODES, 'Card security code display');
        return $this;
    }

    public function getCardSecurityCodeDisplay(): ?string
    {
        return $this->card_security_code_display;
    }

    /**
     * Sent as interaction.locale, e.g. "en_US" or "fr-CA" (2-5 characters).
     *
     * @throws \InvalidArgumentException
     */
    public function setLocale(?string $locale): self
    {
        if ($locale === null || $locale === '') {
            $this->locale = null;
            return $this;
        }

        if (!preg_match('/^[A-Za-z]{2}([_-][A-Za-z]{2})?$/', $locale)) {
            throw new \InvalidArgumentException('Locale must be a language identifier like "en" or "en_US"');
        }

        $this->locale = $locale;
        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * Sent as transaction.source, e.g. INTERNET or MOTO. Requires permission on your merchant profile.
     *
     * @throws \InvalidArgumentException
     */
    public function setTransactionSource(?string $source): self
    {
        $this->transaction_source = $this->enumValue($source, self::TRANSACTION_SOURCES, 'Transaction source');
        return $this;
    }

    public function getTransactionSource(): ?string
    {
        return $this->transaction_source;
    }

    /**
     * Sent as transaction.reference (max 40 characters).
     *
     * @throws \InvalidArgumentException
     */
    public function setTransactionReference(?string $reference): self
    {
        if ($reference === null || $reference === '') {
            $this->transaction_reference = null;
            return $this;
        }

        if (strlen($reference) > 40) {
            throw new \InvalidArgumentException('Transaction reference cannot exceed 40 characters');
        }

        $this->transaction_reference = $reference;
        return $this;
    }

    public function getTransactionReference(): ?string
    {
        return $this->transaction_reference;
    }

    /**
     * Escape hatch for Initiate Checkout fields the SDK does not model yet.
     * Deep-merged last into the request payload, so it overrides SDK fields
     * (a null value removes a field). Use at your own risk.
     */
    public function setExtraFields(array $fields): self
    {
        $this->extra_fields = $fields;
        return $this;
    }

    public function getExtraFields(): array
    {
        return $this->extra_fields;
    }

    private function enumValue(?string $value, array $allowed, string $field_name): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = strtoupper(trim($value));
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException("$field_name must be one of: " . implode(', ', $allowed));
        }

        return $value;
    }
}
