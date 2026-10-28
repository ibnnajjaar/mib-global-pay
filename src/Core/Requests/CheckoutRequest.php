<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Requests;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Support\RequestBody;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * Immutable builder for Initiate Checkout (checkoutMode WEBSITE).
 *
 * Every with*() returns a modified copy. Shape is validated on each call; the parts that
 * depend on the configuration (currency allow-list, HTTPS) are validated when sent.
 */
final class CheckoutRequest
{
    private const MAX_CUSTOM_FIELDS = 50;

    private InteractionOperation $operation = InteractionOperation::Purchase;

    private ?string $description = null;

    private ?string $reference = null;

    private ?string $notificationUrl = null;

    private bool $withoutWebhook = false;

    /** @var array<string, string> */
    private array $customFields = [];

    private ?string $cancelUrl = null;

    private ?string $timeoutUrl = null;

    private ?int $timeoutSeconds = null;

    private ?string $redirectMerchantUrl = null;

    private ?int $retryAttemptCount = null;

    private ?string $merchantUrl = null;

    private ?string $merchantEmail = null;

    private ?string $merchantPhone = null;

    private ?string $merchantLogoUrl = null;

    private ?string $merchantAddressLine1 = null;

    private ?string $locale = null;

    private ?string $accentColor = null;

    private ?string $theme = null;

    private ?CardSecurityCodeDisplay $cardSecurityCode = null;

    private ?ThreeDSecureAction $threeDSecure = null;

    private ?TransactionSource $transactionSource = null;

    private ?string $transactionReference = null;

    /** @var array<string, mixed> */
    private array $extraFields = [];

    private function __construct(
        public readonly string $orderId,
        public readonly Money $amount,
        public readonly string $merchantName,
        public readonly string $returnUrl,
    ) {
    }

    /**
     * @param string $orderId      a new, never reused gateway order ID (1-40 characters)
     * @param string $merchantName shown to the payer (1-40 characters)
     * @param string $returnUrl    where the payer returns; resultIndicator is appended
     *
     * @throws InvalidRequest
     */
    public static function create(string $orderId, Money $amount, string $merchantName, string $returnUrl): self
    {
        if (! $amount->isPositive()) {
            throw new InvalidRequest('Order amount must be greater than zero');
        }

        return new self(
            Validate::identifier($orderId, 'Order ID'),
            $amount,
            Validate::identifier($merchantName, 'Merchant name'),
            Validate::urlShape($returnUrl, 'Return URL') ?? throw new InvalidRequest('Return URL is required'),
        );
    }

    public function withOperation(InteractionOperation $operation): self
    {
        return $this->copy(fn (self $c) => $c->operation = $operation);
    }

    /** @throws InvalidRequest */
    public function withDescription(?string $description): self
    {
        $description = Validate::optionalText($description, 'Order description', 127);

        return $this->copy(fn (self $c) => $c->description = $description);
    }

    /**
     * order.reference: your own identifier, e.g. a receipt number. Any characters, 1-40.
     *
     * @throws InvalidRequest
     */
    public function withReference(?string $reference): self
    {
        $reference = $reference === null || $reference === '' ? null : Validate::identifier($reference, 'Order reference');

        return $this->copy(fn (self $c) => $c->reference = $reference);
    }

    /**
     * order.notificationUrl: overrides the webhook URL configured in Merchant Administration
     * for this order. Required unless withoutWebhook() is called.
     *
     * @throws InvalidRequest
     */
    public function withNotificationUrl(string $url): self
    {
        $url = Validate::urlShape($url, 'Notification URL') ?? throw new InvalidRequest('Notification URL must not be empty');

        return $this->copy(function (self $c) use ($url): void {
            $c->notificationUrl = $url;
            $c->withoutWebhook = false;
        });
    }

    /** Explicitly send no notification URL (the Merchant Administration URL, if any, still applies). */
    public function withoutWebhook(): self
    {
        return $this->copy(function (self $c): void {
            $c->notificationUrl = null;
            $c->withoutWebhook = true;
        });
    }

    /**
     * order.custom.{key}: key 1-99 characters A-Z a-z 0-9, value 1-250 characters, at most 50 fields.
     *
     * @throws InvalidRequest
     */
    public function withCustomField(string $key, string $value): self
    {
        if (preg_match('/^[A-Za-z0-9]{1,99}$/', $key) !== 1) {
            throw new InvalidRequest('Custom field key must be 1-99 characters from A-Z, a-z and 0-9');
        }

        if ($value === '' || mb_strlen($value) > 250) {
            throw new InvalidRequest('Custom field value must be 1-250 characters');
        }

        if (! array_key_exists($key, $this->customFields) && count($this->customFields) >= self::MAX_CUSTOM_FIELDS) {
            throw new InvalidRequest('At most 50 custom fields are allowed');
        }

        return $this->copy(fn (self $c) => $c->customFields[$key] = $value);
    }

    /** @throws InvalidRequest */
    public function withCancelUrl(?string $url): self
    {
        $url = Validate::urlShape($url, 'Cancel URL');

        return $this->copy(fn (self $c) => $c->cancelUrl = $url);
    }

    /**
     * interaction.timeout (600-1800 seconds) and optionally interaction.timeoutUrl.
     *
     * @throws InvalidRequest
     */
    public function withTimeout(int $seconds, ?string $timeoutUrl = null): self
    {
        if ($seconds < 600 || $seconds > 1800) {
            throw new InvalidRequest('Checkout timeout must be between 600 and 1800 seconds');
        }

        $timeoutUrl = Validate::urlShape($timeoutUrl, 'Timeout URL');

        return $this->copy(function (self $c) use ($seconds, $timeoutUrl): void {
            $c->timeoutSeconds = $seconds;
            $c->timeoutUrl = $timeoutUrl;
        });
    }

    /**
     * interaction.redirectMerchantUrl with interaction.retryAttemptCount (1-3): where the payer
     * goes after the retry attempts are used up.
     *
     * @throws InvalidRequest
     */
    public function withRetryRedirect(string $redirectMerchantUrl, int $retryAttemptCount): self
    {
        if ($retryAttemptCount < 1 || $retryAttemptCount > 3) {
            throw new InvalidRequest('Retry attempt count must be between 1 and 3');
        }

        $url = Validate::urlShape($redirectMerchantUrl, 'Redirect merchant URL') ?? throw new InvalidRequest('Redirect merchant URL must not be empty');

        return $this->copy(function (self $c) use ($url, $retryAttemptCount): void {
            $c->redirectMerchantUrl = $url;
            $c->retryAttemptCount = $retryAttemptCount;
        });
    }

    /**
     * interaction.merchant.* details shown to the payer.
     *
     * @throws InvalidRequest
     */
    public function withMerchantDetails(
        ?string $url = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $logoUrl = null,
        ?string $addressLine1 = null,
    ): self {
        $url = Validate::urlShape($url, 'Merchant URL');
        $logoUrl = Validate::urlShape($logoUrl, 'Merchant logo URL');

        if ($email !== null && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidRequest('Merchant email must be a valid email address');
        }

        return $this->copy(function (self $c) use ($url, $email, $phone, $logoUrl, $addressLine1): void {
            $c->merchantUrl = $url;
            $c->merchantEmail = $email === '' ? null : $email;
            $c->merchantPhone = $phone === '' ? null : $phone;
            $c->merchantLogoUrl = $logoUrl;
            $c->merchantAddressLine1 = $addressLine1 === '' ? null : $addressLine1;
        });
    }

    /**
     * interaction.locale, e.g. "en_US" or "fr-CA".
     *
     * @throws InvalidRequest
     */
    public function withLocale(?string $locale): self
    {
        if ($locale !== null && $locale !== '' && preg_match('/^[A-Za-z]{2}([_-][A-Za-z]{2})?$/', $locale) !== 1) {
            throw new InvalidRequest('Locale must look like "en" or "en_US"');
        }

        return $this->copy(fn (self $c) => $c->locale = $locale === '' ? null : $locale);
    }

    /**
     * interaction.style: accent color "#RRGGBB" and/or a theme name.
     *
     * @throws InvalidRequest
     */
    public function withStyle(?string $accentColor = null, ?string $theme = null): self
    {
        if ($accentColor !== null && preg_match('/^#[0-9A-Fa-f]{6}$/', $accentColor) !== 1) {
            throw new InvalidRequest('Accent color must look like "#1A2B3C"');
        }

        if ($theme !== null && preg_match('/^[0-9A-Za-z_-]{1,50}$/', $theme) !== 1) {
            throw new InvalidRequest('Theme must be 1-50 characters from 0-9, a-z, A-Z, "-" and "_"');
        }

        return $this->copy(function (self $c) use ($accentColor, $theme): void {
            $c->accentColor = $accentColor;
            $c->theme = $theme;
        });
    }

    public function withCardSecurityCode(CardSecurityCodeDisplay $display): self
    {
        return $this->copy(fn (self $c) => $c->cardSecurityCode = $display);
    }

    public function withThreeDSecure(ThreeDSecureAction $action): self
    {
        return $this->copy(fn (self $c) => $c->threeDSecure = $action);
    }

    public function withTransactionSource(TransactionSource $source): self
    {
        return $this->copy(fn (self $c) => $c->transactionSource = $source);
    }

    /** @throws InvalidRequest */
    public function withTransactionReference(?string $reference): self
    {
        $reference = $reference === null || $reference === '' ? null : Validate::identifier($reference, 'Transaction reference');

        return $this->copy(fn (self $c) => $c->transactionReference = $reference);
    }

    /**
     * Escape hatch for Initiate Checkout fields not modelled yet. Deep-merged last, so it
     * overrides the builder's fields; a null value removes a field.
     *
     * @param array<string, mixed> $fields
     */
    public function withExtraFields(array $fields): self
    {
        return $this->copy(fn (self $c) => $c->extraFields = $fields);
    }

    public function reference(): ?string
    {
        return $this->reference;
    }

    public function notificationUrl(): ?string
    {
        return $this->notificationUrl;
    }

    /**
     * The Initiate Checkout body, validated against the configuration.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidRequest
     */
    public function toBody(GatewayConfig $config): array
    {
        Validate::amount($this->amount, 'Order amount', $config);

        if ($this->notificationUrl === null && ! $this->withoutWebhook) {
            throw new InvalidRequest('Set a notification URL with withNotificationUrl(), or call withoutWebhook() explicitly');
        }

        $body = [
            'apiOperation' => 'INITIATE_CHECKOUT',
            'interaction' => [
                'operation' => $this->operation->value,
                'merchant' => [
                    'name' => $this->merchantName,
                    'url' => Validate::url($this->merchantUrl, 'Merchant URL', $config),
                    'email' => $this->merchantEmail,
                    'phone' => $this->merchantPhone,
                    'logo' => Validate::url($this->merchantLogoUrl, 'Merchant logo URL', $config),
                    'address' => ['line1' => $this->merchantAddressLine1],
                ],
                'returnUrl' => Validate::url($this->returnUrl, 'Return URL', $config),
                'cancelUrl' => Validate::url($this->cancelUrl, 'Cancel URL', $config),
                'timeout' => $this->timeoutSeconds,
                'timeoutUrl' => Validate::url($this->timeoutUrl, 'Timeout URL', $config),
                'redirectMerchantUrl' => Validate::url($this->redirectMerchantUrl, 'Redirect merchant URL', $config),
                'retryAttemptCount' => $this->retryAttemptCount,
                'locale' => $this->locale,
                'style' => ['accentColor' => $this->accentColor, 'theme' => $this->theme],
                'displayControl' => ['cardSecurityCode' => $this->cardSecurityCode?->value],
                'action' => ['3DSecure' => $this->threeDSecure?->value],
            ],
            'order' => [
                'id' => $this->orderId,
                'amount' => $this->amount->toDecimal(),
                'currency' => $this->amount->currency->value,
                'description' => $this->description,
                'reference' => $this->reference,
                'notificationUrl' => Validate::url($this->notificationUrl, 'Notification URL', $config),
                'custom' => $this->customFields,
            ],
            'transaction' => [
                'reference' => $this->transactionReference,
                'source' => $this->transactionSource?->value,
            ],
        ];

        /** @var array<string, mixed> */
        return RequestBody::withoutNulls(RequestBody::merge($body, $this->extraFields));
    }

    /**
     * @param \Closure(self): mixed $change
     */
    private function copy(\Closure $change): self
    {
        $copy = clone $this;
        $change($copy);

        return $copy;
    }
}
