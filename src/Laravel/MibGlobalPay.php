<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel;

use Illuminate\Database\Eloquent\Model;
use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Attempt;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Payable;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\Source;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Laravel\Support\Settings;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptGuard;
use IbnNajjaar\MIBGlobalPay\Core\Reversal\ReversalHelper;
use IbnNajjaar\MIBGlobalPay\Laravel\Support\WebhookRoute;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\ResolvedAttempt;
use IbnNajjaar\MIBGlobalPay\Core\Requests\CheckoutRequest;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Laravel\Models\CheckoutAttempt;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\OrderIdGenerator;
use IbnNajjaar\MIBGlobalPay\Laravel\Checkout\CheckoutStarter;
use IbnNajjaar\MIBGlobalPay\Laravel\Outcome\OutcomeProcessor;
use IbnNajjaar\MIBGlobalPay\Laravel\Checkout\AttemptNotUnpaid;
use IbnNajjaar\MIBGlobalPay\Core\Requests\InteractionOperation;
use IbnNajjaar\MIBGlobalPay\Laravel\Checkout\CheckoutNotAllowed;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationResult;

/**
 * The object behind the MibGlobalPay facade: the configured Gateway plus Laravel helpers.
 * Unknown methods are forwarded to the Gateway (verifyCapture, retrieveOrder, refund, ...).
 *
 * @mixin \IbnNajjaar\MIBGlobalPay\Core\MibGateway
 */
final readonly class MibGlobalPay
{
    public const string WEBHOOK_ROUTE_NAME = 'mib-global-pay.webhook';

    public function __construct(
        private Gateway $gateway,
        private Settings $settings,
        private OrderIdGenerator $orderIds,
        private UrlGenerator $urls,
        private WebhookRoute $webhookRoute,
        private Container $container,
    ) {
    }

    public function gateway(): Gateway
    {
        return $this->gateway;
    }

    /** The configured order-ID prefix (MIB_ORDER_PREFIX). */
    public function orderPrefix(): string
    {
        return $this->gateway->config()->orderPrefix;
    }

    /** {prefix}-{sanitised reference}-{attempt}; see GatewayOrderId. */
    public function attemptOrderId(string $payableReference, int $attemptNumber): string
    {
        return $this->orderIds->forAttempt($this->orderPrefix(), $payableReference, $attemptNumber);
    }

    /** A random order ID, for when the readable one collides with an existing attempt. */
    public function randomOrderId(): string
    {
        return $this->orderIds->random($this->orderPrefix());
    }

    /**
     * A CheckoutRequest with the configured checkout timeout and, when the package's webhook
     * route exists, its URL as order.notificationUrl. Hosts may override both.
     *
     * @throws InvalidRequest
     */
    public function newCheckoutRequest(string $orderId, Money $amount, string $merchantName, string $returnUrl, bool $verification = false): CheckoutRequest
    {
        $request = $verification
            ? CheckoutRequest::forVerification($orderId, $amount, $merchantName, $returnUrl)
            : CheckoutRequest::create($orderId, $amount, $merchantName, $returnUrl);
        $request = $request->withTimeout($this->settings->checkoutTimeoutSeconds());

        $url = $this->webhookUrl();

        return $url === null ? $request : $request->withNotificationUrl($url);
    }

    /**
     * Starts a payment for a model using the HasMibPayments trait. Send the payer to
     * $attempt->paymentUrl(); the package handles the rest and dispatches the usual events.
     *
     * @param (\Closure(CheckoutRequest): CheckoutRequest)|null $customize adds anything else to the request
     *
     * @throws CheckoutNotAllowed
     */
    public function startCheckout(Payable&Model $payable, InteractionOperation $operation = InteractionOperation::Purchase, ?\Closure $customize = null): CheckoutAttempt
    {
        return $this->container->make(CheckoutStarter::class)->checkout($payable, $operation, $customize);
    }

    /**
     * Creates a shareable payment link for a model using the HasMibPayments trait. Share
     * $attempt->paymentUrl(). It expires in 3 months and allows 25 attempts unless you pass other
     * values.
     *
     * @param (\Closure(CheckoutRequest): CheckoutRequest)|null $customize adds anything else to the request
     *
     * @throws CheckoutNotAllowed
     */
    public function startPaymentLink(
        Payable&Model $payable,
        ?\DateTimeInterface $expiresAt = null,
        ?int $allowedAttempts = null,
        InteractionOperation $operation = InteractionOperation::Purchase,
        ?\Closure $customize = null,
    ): CheckoutAttempt {
        return $this->container->make(CheckoutStarter::class)->paymentLink($payable, $expiresAt, $allowedAttempts, $operation, $customize);
    }

    /**
     * A person's decision that an unresolved attempt was not charged, which lets a new attempt
     * start. The gateway is asked once more first, and mib:reconcile keeps watching for a late charge.
     *
     * @throws AttemptNotUnpaid when the gateway reports money held or captured, or another result
     */
    public function confirmUnpaid(CheckoutAttempt $attempt, string $confirmedBy, ?string $reason = null): void
    {
        $this->container->make(CheckoutStarter::class)->confirmUnpaid($attempt, $confirmedBy, $reason);
    }

    /**
     * The absolute URL of the webhook route, if it is registered. Works whatever name prefix or
     * domain a host group gives the route registered with Route::mibGlobalPayWebhook(), with or
     * without route:cache. With several such routes, the last one registered wins.
     */
    public function webhookUrl(): ?string
    {
        $route = $this->webhookRoute->route();

        return $route === null ? null : $this->urls->toRoute($route, [], true);
    }

    /** created_at + checkout timeout + grace: the attempts.expires_at value. */
    public function attemptExpiresAt(\DateTimeInterface $createdAt): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($createdAt)
            ->modify(sprintf('+%d seconds', $this->settings->checkoutTimeoutSeconds() + $this->settings->expiryGraceSeconds()));
    }

    /**
     * AttemptGuard wired to the outcome pipeline: when it finds money captured on an earlier
     * attempt, the pipeline runs for it (Source::AttemptGuard), so PaymentCaptured or
     * CaptureMismatch is dispatched as usual.
     */
    public function attemptGuard(): AttemptGuard
    {
        return new AttemptGuard(
            $this->gateway,
            onMoneyCaptured: function (Payable $payable, Attempt $attempt, VerificationResult $verification): void {
                $this->container->make(OutcomeProcessor::class)
                    ->processVerified(new ResolvedAttempt($payable, $attempt), $verification, Source::AttemptGuard);
            },
        );
    }

    public function reversal(): ReversalHelper
    {
        return new ReversalHelper($this->gateway);
    }

    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->gateway->{$method}(...$arguments);
    }
}
