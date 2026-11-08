<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Checkout;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Routing\UrlGenerator;
use IbnNajjaar\MIBGlobalPay\Laravel\MibGlobalPay;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Payable;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Laravel\Support\Settings;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptState;
use IbnNajjaar\MIBGlobalPay\Core\Requests\CheckoutRequest;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Laravel\Models\CheckoutAttempt;
use IbnNajjaar\MIBGlobalPay\Core\Requests\InteractionOperation;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationStatus;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\ConfigurationException;

/**
 * Starts a payment for a payable from one call: runs AttemptGuard, retires old attempts, creates
 * the new attempt, calls the gateway and stores the result, all inside Payable::lock().
 */
final readonly class CheckoutStarter
{
    public const string RETURN_ROUTE = 'mib-global-pay.return';

    public const string LINK_ERROR_ROUTE = 'mib-global-pay.link-error';

    public function __construct(
        private MibGlobalPay $mib,
        private Gateway $gateway,
        private Settings $settings,
        private UrlGenerator $urls,
    ) {
    }

    /**
     * @param (Closure(CheckoutRequest): CheckoutRequest)|null $customize adds anything else to the request
     *
     * @throws CheckoutNotAllowed
     */
    public function checkout(Payable&Model $payable, InteractionOperation $operation = InteractionOperation::Purchase, ?Closure $customize = null): CheckoutAttempt
    {
        return self::locked($payable, function () use ($payable, $operation, $customize): CheckoutAttempt|CheckoutNotAllowed {
            $next = $this->prepare($payable, $operation);

            if (! is_int($next)) {
                return $next;
            }

            $attempt = $this->create($payable, $next, $operation, $this->mib->attemptExpiresAt(new \DateTimeImmutable()));
            $session = $this->gateway->checkout()->initiate($this->request($payable, $attempt, $operation, $customize));

            $attempt->update([
                'state' => AttemptState::Live,
                'session_id' => $session->sessionId,
                'session_version' => $session->sessionVersion,
                'success_indicator' => $session->successIndicator,
            ]);

            return $attempt;
        });
    }

    /**
     * A shareable payment link: $attempt->paymentUrl() is the link. The gateway defaults to 3 months
     * and 25 attempts.
     *
     * @param (Closure(CheckoutRequest): CheckoutRequest)|null $customize adds anything else to the request
     *
     * @throws CheckoutNotAllowed
     */
    public function paymentLink(
        Payable&Model $payable,
        ?\DateTimeInterface $expiresAt = null,
        ?int $allowedAttempts = null,
        InteractionOperation $operation = InteractionOperation::Purchase,
        ?Closure $customize = null,
    ): CheckoutAttempt {
        return self::locked($payable, function () use ($payable, $expiresAt, $allowedAttempts, $operation, $customize): CheckoutAttempt|CheckoutNotAllowed {
            $next = $this->prepare($payable, $operation, reusable: false);

            if (! is_int($next)) {
                return $next;
            }

            $expires = \DateTimeImmutable::createFromInterface($expiresAt ?? new \DateTimeImmutable('+3 months'));
            $attempt = $this->create($payable, $next, $operation, $expires->modify(sprintf('+%d seconds', $this->settings->expiryGraceSeconds())));

            $request = $this->request($payable, $attempt, $operation, $customize)
                ->withPaymentLink($expiresAt, $allowedAttempts, $this->routeUrl(self::LINK_ERROR_ROUTE));

            $link = $this->gateway->paymentLinks()->create($request);

            $attempt->update([
                'state' => AttemptState::Live,
                'payment_link_id' => $link->id,
                'payment_link_url' => $link->url,
                'success_indicator' => $link->successIndicator,
            ]);

            return $attempt;
        });
    }

    /**
     * A person's decision that an unresolved attempt was not charged, usually after asking the
     * acquirer. The gateway is asked once more first. The attempt becomes Failed, which lets a new
     * attempt start, and mib:reconcile keeps watching it for a late charge.
     *
     * @throws AttemptNotUnpaid when the gateway reports money held or captured, or another result
     */
    public function confirmUnpaid(CheckoutAttempt $attempt, string $confirmedBy, ?string $reason = null): void
    {
        $verification = $this->gateway->verifyCapture($attempt->gateway_order_id, $attempt->amount());

        if (! in_array($verification->status, [VerificationStatus::Pending, VerificationStatus::NotCaptured, VerificationStatus::NotFound], true)) {
            throw new AttemptNotUnpaid($verification);
        }

        if ($attempt->state->canTransitionTo(AttemptState::Failed)) {
            $attempt->update([
                'state' => AttemptState::Failed,
                'confirmed_unpaid_at' => new \DateTimeImmutable(),
                'confirmed_unpaid_by' => $confirmedBy,
                'confirmed_unpaid_reason' => $reason,
            ]);
        }
    }

    /**
     * A refusal is thrown only after the lock has committed. The guard may have just run the outcome
     * pipeline for money it found, and throwing inside the lock would roll that back.
     *
     * @param Closure(): (CheckoutAttempt|CheckoutNotAllowed) $callback
     *
     * @throws CheckoutNotAllowed
     */
    private static function locked(Payable $payable, Closure $callback): CheckoutAttempt
    {
        $result = $payable->lock($callback);

        return $result instanceof CheckoutNotAllowed ? throw $result : $result;
    }

    /**
     * Runs the guard and retires old attempts. Gives the attempt to reuse, the next attempt number,
     * or the refusal.
     */
    private function prepare(Payable $payable, InteractionOperation $operation, bool $reusable = true): CheckoutAttempt|CheckoutNotAllowed|int
    {
        $guard = $this->mib->attemptGuard()->canStartNewAttempt($payable);

        if (! $guard->allowed) {
            $attempt = $guard->reusableAttempt;

            if ($reusable && $attempt instanceof CheckoutAttempt && $attempt->session_id !== null && $attempt->operation === $operation->value) {
                return $attempt;
            }

            return new CheckoutNotAllowed($guard);
        }

        foreach ($guard->attemptsToSupersede as $old) {
            if ($old instanceof CheckoutAttempt && $old->state->canTransitionTo(AttemptState::Superseded)) {
                $old->update(['state' => AttemptState::Superseded]);
            }
        }

        return $guard->nextAttemptNumber;
    }

    private function create(Payable&Model $payable, int $number, InteractionOperation $operation, \DateTimeInterface $expiresAt): CheckoutAttempt
    {
        $amount = $payable->expectedAmount();

        $attempt = new CheckoutAttempt([
            'attempt_number' => $number,
            'gateway_order_id' => $this->mib->attemptOrderId($payable->payableReference(), $number),
            'operation' => $operation->value,
            'state' => AttemptState::Created,
            'amount_minor' => $amount->minor,
            'currency' => $amount->currency->value,
            'expires_at' => $expiresAt,
        ]);
        $attempt->payable()->associate($payable);
        $attempt->save();

        return $attempt;
    }

    /**
     * @param (Closure(CheckoutRequest): CheckoutRequest)|null $customize
     *
     * @throws InvalidRequest
     * @throws ConfigurationException
     */
    private function request(Payable $payable, CheckoutAttempt $attempt, InteractionOperation $operation, ?Closure $customize): CheckoutRequest
    {
        $merchant = $this->settings->merchantName();
        $returnUrl = $this->routeUrl(self::RETURN_ROUTE, ['order' => $attempt->gateway_order_id])
            ?? throw new ConfigurationException('Turn on the checkout routes (MIB_CHECKOUT_ROUTES) to use startCheckout()');

        $request = $operation === InteractionOperation::Verify
            ? $this->mib->newCheckoutRequest($attempt->gateway_order_id, $payable->expectedAmount(), $merchant, $returnUrl, verification: true)
            : $this->mib->newCheckoutRequest($attempt->gateway_order_id, $payable->expectedAmount(), $merchant, $returnUrl)->withOperation($operation);

        $request = $request
            ->withReference($payable->payableReference())
            ->withDescription($payable->payableReference())
            ->withMerchantDetails(url: $this->settings->merchantUrl(), logoUrl: $this->settings->merchantLogoUrl());

        return $customize === null ? $request : $customize($request);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function routeUrl(string $name, array $parameters = []): ?string
    {
        return $this->settings->checkoutRoutes() ? $this->urls->route($name, $parameters) : null;
    }
}
