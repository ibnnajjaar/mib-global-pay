<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel;

use Illuminate\Routing\Router;
use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use Illuminate\Contracts\Routing\UrlGenerator;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Laravel\Support\Settings;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptGuard;
use IbnNajjaar\MIBGlobalPay\Core\Reversal\ReversalHelper;
use IbnNajjaar\MIBGlobalPay\Core\Requests\CheckoutRequest;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\OrderIdGenerator;

/**
 * The object behind the MibGlobalPay facade: the configured Gateway plus Laravel helpers.
 * Unknown methods are forwarded to the Gateway (verifyCapture, retrieveOrder, refund, ...).
 *
 * @mixin \IbnNajjaar\MIBGlobalPay\Core\MibGateway
 */
final readonly class MibGlobalPay
{
    public const WEBHOOK_ROUTE_NAME = 'mib-global-pay.webhook';

    public function __construct(
        private Gateway $gateway,
        private Settings $settings,
        private OrderIdGenerator $orderIds,
        private Router $router,
        private UrlGenerator $urls,
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
    public function newCheckoutRequest(string $orderId, Money $amount, string $merchantName, string $returnUrl): CheckoutRequest
    {
        $request = CheckoutRequest::create($orderId, $amount, $merchantName, $returnUrl)
            ->withTimeout($this->settings->checkoutTimeoutSeconds());

        $url = $this->webhookUrl();

        return $url === null ? $request : $request->withNotificationUrl($url);
    }

    /** The absolute URL of the webhook route, if it is registered. */
    public function webhookUrl(): ?string
    {
        return $this->router->has(self::WEBHOOK_ROUTE_NAME) ? $this->urls->route(self::WEBHOOK_ROUTE_NAME) : null;
    }

    /** created_at + checkout timeout + grace: the attempts.expires_at value. */
    public function attemptExpiresAt(\DateTimeInterface $createdAt): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($createdAt)
            ->modify(sprintf('+%d seconds', $this->settings->checkoutTimeoutSeconds() + $this->settings->expiryGraceSeconds()));
    }

    public function attemptGuard(): AttemptGuard
    {
        return new AttemptGuard($this->gateway);
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
