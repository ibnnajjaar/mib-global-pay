<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Models;

use Illuminate\Support\Facades\URL;
use Illuminate\Database\Eloquent\Model;
use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Attempt;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptState;

/**
 * One checkout attempt, stored by the package for hosts using the HasMibPayments trait.
 *
 * @property int                       $attempt_number
 * @property string                    $gateway_order_id
 * @property string                    $operation
 * @property AttemptState              $state
 * @property int                       $amount_minor
 * @property string                    $currency
 * @property string|null               $session_id
 * @property string|null               $session_version
 * @property string|null               $success_indicator
 * @property string|null               $payment_link_id
 * @property string|null               $payment_link_url
 * @property string|null               $settled_transaction_id
 * @property int|null                  $settled_amount_minor
 * @property \DateTimeImmutable|null   $settled_at
 * @property \DateTimeImmutable|null   $reversed_at
 * @property \DateTimeImmutable|null   $confirmed_unpaid_at
 * @property string|null               $confirmed_unpaid_by
 * @property string|null               $confirmed_unpaid_reason
 * @property \DateTimeImmutable        $expires_at
 */
final class CheckoutAttempt extends Model implements Attempt
{
    /** The routes that show the payment page and receive the payer back. */
    public const string CHECKOUT_ROUTE = 'mib-global-pay.checkout';

    protected $table = 'mib_global_pay_attempts';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'attempt_number' => 'integer',
        'amount_minor' => 'integer',
        'settled_amount_minor' => 'integer',
        'state' => AttemptState::class,
        'settled_at' => 'immutable_datetime',
        'reversed_at' => 'immutable_datetime',
        'confirmed_unpaid_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
    ];

    /** @return MorphTo<Model, $this> */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Where to send the payer: the payment link, or a signed page that starts Checkout.js. Valid
     * until the attempt expires.
     */
    public function paymentUrl(): string
    {
        return $this->payment_link_url
            ?? URL::temporarySignedRoute(self::CHECKOUT_ROUTE, $this->expires_at, ['order' => $this->gateway_order_id]);
    }

    public function attemptNumber(): int
    {
        return $this->attempt_number;
    }

    public function gatewayOrderId(): string
    {
        return $this->gateway_order_id;
    }

    public function sessionId(): ?string
    {
        return $this->session_id;
    }

    public function successIndicator(): ?string
    {
        return $this->success_indicator;
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, Currency::fromCode($this->currency));
    }

    public function state(): AttemptState
    {
        return $this->state;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expires_at;
    }
}
