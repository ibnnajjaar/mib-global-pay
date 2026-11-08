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
    private const int MAX_CUSTOM_FIELDS = 50;

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

    private ?FieldDisplay $billingAddressDisplay = null;

    private ?FieldDisplay $customerEmailDisplay = null;

    private ?ShippingDisplay $shippingDisplay = null;

    private ?PaymentTermsDisplay $paymentTermsDisplay = null;

    private ?ThreeDSecureAction $threeDSecure = null;

    private ?TransactionSource $transactionSource = null;

    private ?string $transactionReference = null;

    private ?Contact $customer = null;

    private ?string $customerTaxRegistrationId = null;

    private ?Address $billingAddress = null;

    private ?Address $shippingAddress = null;

    private ?Contact $shippingContact = null;

    private ?SameAsBilling $shippingSameAsBilling = null;

    /** @var list<LineItem> */
    private array $lineItems = [];

    private ?string $statementDescriptorName = null;

    private ?string $statementDescriptorPhone = null;

    private ?Address $statementDescriptorAddress = null;

    /** @var array<string, mixed> */
    private array $orderDetails = [];

    /** @var array<string, mixed> */
    private array $airline = [];

    /** @var array<string, mixed> */
    private array $cruise = [];

    /** @var list<string> */
    private array $supportedPaymentPlans = [];

    private ?int $paymentPlanPayments = null;

    private ?int $paymentPlanDeferralPeriod = null;

    private bool $saveCard = false;

    /** @var list<string> */
    private array $tokens = [];

    private ?Agreement $agreement = null;

    private ?StoredOnFile $storedOnFile = null;

    private bool $paymentLink = false;

    private ?\DateTimeInterface $paymentLinkExpiresAt = null;

    private ?int $paymentLinkAttempts = null;

    private ?string $paymentLinkErrorUrl = null;

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

    /**
     * A VERIFY checkout, the only operation that may send a zero amount. It moves no money.
     *
     * @param string $orderId      a new, never reused gateway order ID (1-40 characters)
     * @param string $merchantName shown to the payer (1-40 characters)
     * @param string $returnUrl    where the payer returns; resultIndicator is appended
     *
     * @throws InvalidRequest
     */
    public static function forVerification(string $orderId, Money $amount, string $merchantName, string $returnUrl): self
    {
        if ($amount->isNegative()) {
            throw new InvalidRequest('Order amount must not be negative');
        }

        $request = new self(
            Validate::identifier($orderId, 'Order ID'),
            $amount,
            Validate::identifier($merchantName, 'Merchant name'),
            Validate::urlShape($returnUrl, 'Return URL') ?? throw new InvalidRequest('Return URL is required'),
        );
        $request->operation = InteractionOperation::Verify;

        return $request;
    }

    /**
     * interaction.operation. Defaults to PURCHASE. verifyCapture() reports the result of each one:
     * AUTHORIZE gives Authorized, VERIFY gives Verified, and NONE stays NotCaptured because no
     * transaction is created.
     */
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

    /**
     * interaction.displayControl.*: how the payment page shows these details. Replaces all four, so
     * leaving one out clears it and the gateway's default applies. A READ_ONLY field needs its data
     * in the request, which withExtraFields() can supply (billing, customer, shipping).
     */
    public function withDisplayControl(
        ?FieldDisplay $billingAddress = null,
        ?FieldDisplay $customerEmail = null,
        ?ShippingDisplay $shipping = null,
        ?PaymentTermsDisplay $paymentTerms = null,
    ): self {
        return $this->copy(function (self $c) use ($billingAddress, $customerEmail, $shipping, $paymentTerms): void {
            $c->billingAddressDisplay = $billingAddress;
            $c->customerEmailDisplay = $customerEmail;
            $c->shippingDisplay = $shipping;
            $c->paymentTermsDisplay = $paymentTerms;
        });
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
     * customer.*: the payer. customer.email also drives Click to Pay and payer email notifications.
     *
     * @throws InvalidRequest
     */
    public function withCustomer(Contact $customer, ?string $taxRegistrationId = null): self
    {
        return $this->copy(function (self $c) use ($customer, $taxRegistrationId): void {
            $c->customer = $customer;
            $c->customerTaxRegistrationId = $taxRegistrationId;
        });
    }

    /** billing.address.* */
    public function withBillingAddress(Address $address): self
    {
        return $this->copy(fn (self $c) => $c->billingAddress = $address);
    }

    /** shipping.address.* and shipping.contact.* */
    public function withShipping(?Address $address = null, ?Contact $contact = null, ?SameAsBilling $sameAsBilling = null): self
    {
        return $this->copy(function (self $c) use ($address, $contact, $sameAsBilling): void {
            $c->shippingAddress = $address;
            $c->shippingContact = $contact;
            $c->shippingSameAsBilling = $sameAsBilling;
        });
    }

    /**
     * order.item[n]: what is being bought, including approved healthcare purchases. The Hosted
     * Checkout guide describes this field, but the v100 Initiate Checkout reference does not list
     * it, so try it on the test gateway first.
     */
    public function withLineItems(LineItem ...$items): self
    {
        return $this->copy(fn (self $c) => $c->lineItems = array_values($items));
    }

    /** order.statementDescriptor.*: how the payment appears on the payer's statement. */
    public function withStatementDescriptor(?string $name = null, ?string $phone = null, ?Address $address = null): self
    {
        return $this->copy(function (self $c) use ($name, $phone, $address): void {
            $c->statementDescriptorName = $name;
            $c->statementDescriptorPhone = $phone;
            $c->statementDescriptorAddress = $address;
        });
    }

    /**
     * Level 2 and Level 3 order data, for better interchange rates on business, corporate and
     * purchase cards.
     *
     * @throws InvalidRequest
     */
    public function withOrderDetails(
        ?string $customerReference = null,
        ?string $invoiceNumber = null,
        ?Money $taxAmount = null,
        ?Money $shippingAndHandlingAmount = null,
        ?Money $dutyAmount = null,
        ?string $taxRegistrationId = null,
    ): self {
        return $this->copy(fn (self $c) => $c->orderDetails = [
            'customerReference' => $customerReference,
            'invoiceNumber' => $invoiceNumber,
            'taxAmount' => $taxAmount?->toDecimal(),
            'shippingAndHandlingAmount' => $shippingAndHandlingAmount?->toDecimal(),
            'dutyAmount' => $dutyAmount?->toDecimal(),
            'taxRegistrationId' => $taxRegistrationId,
        ]);
    }

    /**
     * airline.*: flights, passengers, tickets and itineraries, in the gateway's own field names.
     *
     * @param array<string, mixed> $airline
     */
    public function withAirlineData(array $airline): self
    {
        return $this->copy(fn (self $c) => $c->airline = $airline);
    }

    /**
     * cruise.*: cruise, ship, passengers and travel agent, in the gateway's own field names.
     *
     * @param array<string, mixed> $cruise
     */
    public function withCruiseData(array $cruise): self
    {
        return $this->copy(fn (self $c) => $c->cruise = $cruise);
    }

    /**
     * constraints.paymentPlans.*: narrows the installment plans offered for this transaction.
     *
     * @param list<string> $supported plan identifiers
     *
     * @throws InvalidRequest
     */
    public function withPaymentPlanConstraints(array $supported = [], ?int $numberOfPayments = null, ?int $deferralPeriod = null): self
    {
        if ($numberOfPayments !== null && ($numberOfPayments < 1 || $numberOfPayments > 99)) {
            throw new InvalidRequest('Number of payments must be between 1 and 99');
        }

        if ($deferralPeriod !== null && ($deferralPeriod < 0 || $deferralPeriod > 99)) {
            throw new InvalidRequest('Deferral period must be between 0 and 99');
        }

        foreach ($supported as $plan) {
            Validate::identifier($plan, 'Payment plan');
        }

        return $this->copy(function (self $c) use ($supported, $numberOfPayments, $deferralPeriod): void {
            $c->supportedPaymentPlans = $supported;
            $c->paymentPlanPayments = $numberOfPayments;
            $c->paymentPlanDeferralPeriod = $deferralPeriod;
        });
    }

    /**
     * interaction.saveCardForCredentialOnFile: shows the payer a consent checkbox to save the card.
     * If they agree, read the token from OrderResponse::token().
     */
    public function withSaveCard(bool $save = true): self
    {
        return $this->copy(fn (self $c) => $c->saveCard = $save);
    }

    /**
     * interaction.tokens[n]: saved cards to offer the payer.
     *
     * @throws InvalidRequest
     */
    public function withTokens(string ...$tokens): self
    {
        foreach ($tokens as $token) {
            if (preg_match('/^[0-9A-Za-z]{1,40}$/', $token) !== 1) {
                throw new InvalidRequest('Token must be 1-40 characters from 0-9, a-z and A-Z');
            }
        }

        return $this->copy(fn (self $c) => $c->tokens = array_values($tokens));
    }

    /** agreement.*: the payer's agreement to later payments on this card. */
    public function withAgreement(Agreement $agreement): self
    {
        return $this->copy(fn (self $c) => $c->agreement = $agreement);
    }

    /** sourceOfFunds.provided.card.storedOnFile */
    public function withStoredOnFile(StoredOnFile $storedOnFile): self
    {
        return $this->copy(fn (self $c) => $c->storedOnFile = $storedOnFile);
    }

    /**
     * Makes this a Payment Link request (checkoutMode PAYMENT_LINK), sent with the payment link
     * client. The gateway defaults to 3 months and 25 attempts. It needs AUTHORIZE or PURCHASE, an
     * order description and a merchant URL. On an error, the gateway sends the payer to $errorUrl
     * with errorCode and errorDescription.
     *
     * @throws InvalidRequest
     */
    public function withPaymentLink(?\DateTimeInterface $expiresAt = null, ?int $allowedAttempts = null, ?string $errorUrl = null): self
    {
        if ($allowedAttempts !== null && $allowedAttempts < 1) {
            throw new InvalidRequest('Payment link attempts must be at least 1');
        }

        $errorUrl = Validate::urlShape($errorUrl, 'Payment link error URL');

        return $this->copy(function (self $c) use ($expiresAt, $allowedAttempts, $errorUrl): void {
            $c->paymentLink = true;
            $c->paymentLinkExpiresAt = $expiresAt;
            $c->paymentLinkAttempts = $allowedAttempts;
            $c->paymentLinkErrorUrl = $errorUrl;
        });
    }

    public function isPaymentLink(): bool
    {
        return $this->paymentLink;
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

    public function operation(): InteractionOperation
    {
        return $this->operation;
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
        Validate::amount($this->amount, 'Order amount', $config, allowZero: $this->operation === InteractionOperation::Verify);

        if ($this->notificationUrl === null && ! $this->withoutWebhook) {
            throw new InvalidRequest('Set a notification URL with withNotificationUrl(), or call withoutWebhook() explicitly');
        }

        if ($this->paymentLink) {
            $this->validatePaymentLink();
        }

        $body = [
            'apiOperation' => 'INITIATE_CHECKOUT',
            'checkoutMode' => $this->paymentLink ? 'PAYMENT_LINK' : null,
            'paymentLink' => $this->paymentLinkFields($config),
            'interaction' => [
                'operation' => $this->operation->value,
                'merchant' => [
                    'name' => $this->merchantName,
                    'url' => Validate::url($this->merchantUrl, 'Merchant URL', $config),
                    'email' => $this->merchantEmail,
                    'phone' => $this->merchantPhone,
                    'logo' => Validate::httpsUrl($this->merchantLogoUrl, 'Merchant logo URL'),
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
                'displayControl' => [
                    'cardSecurityCode' => $this->cardSecurityCode?->value,
                    'billingAddress' => $this->billingAddressDisplay?->value,
                    'customerEmail' => $this->customerEmailDisplay?->value,
                    'shipping' => $this->shippingDisplay?->value,
                    'paymentTerms' => $this->paymentTermsDisplay?->value,
                ],
                'action' => ['3DSecure' => $this->threeDSecure?->value],
                'saveCardForCredentialOnFile' => $this->saveCard ? 'PAYER_INITIATED_PAYMENTS' : null,
                'tokens' => $this->tokens,
            ],
            'order' => [
                'id' => $this->orderId,
                'amount' => $this->amount->toDecimal(),
                'currency' => $this->amount->currency->value,
                'description' => $this->description,
                'reference' => $this->reference,
                'notificationUrl' => Validate::url($this->notificationUrl, 'Notification URL', $config),
                'custom' => $this->customFields,
                'item' => array_map(fn (LineItem $item): array => $item->toArray(), $this->lineItems),
                'statementDescriptor' => [
                    'name' => $this->statementDescriptorName,
                    'phone' => $this->statementDescriptorPhone,
                    'address' => $this->statementDescriptorAddress?->toArray(),
                ],
                ...$this->orderDetails,
            ],
            'customer' => [...($this->customer?->toArray() ?? []), 'taxRegistrationId' => $this->customerTaxRegistrationId],
            'billing' => ['address' => $this->billingAddress?->toArray()],
            'shipping' => [
                'address' => [...($this->shippingAddress?->toArray() ?? []), 'sameAsBilling' => $this->shippingSameAsBilling?->value],
                'contact' => $this->shippingContact?->toArray(),
            ],
            'airline' => $this->airline,
            'cruise' => $this->cruise,
            'constraints' => ['paymentPlans' => [
                'supported' => $this->supportedPaymentPlans,
                'numberOfPayments' => $this->paymentPlanPayments,
                'deferralPeriod' => $this->paymentPlanDeferralPeriod,
            ]],
            'agreement' => $this->agreement?->toArray(),
            'sourceOfFunds' => ['provided' => ['card' => ['storedOnFile' => $this->storedOnFile?->value]]],
            'transaction' => [
                'reference' => $this->transactionReference,
                'source' => $this->transactionSource?->value,
            ],
        ];

        /** @var array<string, mixed> */
        return RequestBody::withoutNulls(RequestBody::merge($body, $this->extraFields));
    }

    /** @throws InvalidRequest */
    private function validatePaymentLink(): void
    {
        if (! in_array($this->operation, [InteractionOperation::Authorize, InteractionOperation::Purchase], true)) {
            throw new InvalidRequest('A payment link needs the AUTHORIZE or PURCHASE operation');
        }

        if ($this->merchantUrl === null) {
            throw new InvalidRequest('A payment link needs a merchant URL: call withMerchantDetails(url: ...)');
        }

        if ($this->description === null) {
            throw new InvalidRequest('A payment link needs an order description: call withDescription()');
        }
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws InvalidRequest
     */
    private function paymentLinkFields(GatewayConfig $config): ?array
    {
        if (! $this->paymentLink) {
            return null;
        }

        $expires = $this->paymentLinkExpiresAt === null
            ? null
            : \DateTimeImmutable::createFromInterface($this->paymentLinkExpiresAt)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');

        return [
            'expiryDateTime' => $expires,
            'numberOfAllowedAttempts' => $this->paymentLinkAttempts,
            'errorUrl' => Validate::url($this->paymentLinkErrorUrl, 'Payment link error URL', $config),
        ];
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
