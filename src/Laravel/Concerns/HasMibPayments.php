<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;
use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Attempt;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Settlement;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptState;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableState;
use IbnNajjaar\MIBGlobalPay\Laravel\Models\CheckoutAttempt;

/**
 * Makes an Eloquent model payable. Implement Payable on the model, add this trait, and write
 * payableReference() and expectedAmount(). The package stores the attempts and works out the state
 * from them. Override payableState() if your model has its own rules, such as cancelled invoices.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasMibPayments
{
    /** @return MorphMany<CheckoutAttempt, $this> */
    public function mibAttempts(): MorphMany
    {
        return $this->morphMany(CheckoutAttempt::class, 'payable');
    }

    /** @return iterable<Attempt> newest first */
    public function attempts(): iterable
    {
        return $this->mibAttempts()->orderByDesc('attempt_number')->get();
    }

    public function settledBy(): ?Settlement
    {
        $attempt = $this->mibAttempts()->whereNotNull('settled_at')->whereNull('reversed_at')->first();

        if ($attempt === null || $attempt->settled_transaction_id === null || $attempt->settled_at === null) {
            return null;
        }

        return new Settlement(
            $attempt->gateway_order_id,
            $attempt->settled_transaction_id,
            Money::ofMinor($attempt->settled_amount_minor ?? $attempt->amount_minor, $attempt->amount()->currency),
            $attempt->settled_at,
        );
    }

    public function payableState(): PayableState
    {
        $attempts = $this->mibAttempts()->get();

        if ($attempts->contains(fn (CheckoutAttempt $a): bool => $a->settled_at !== null && $a->reversed_at === null)) {
            return PayableState::Paid;
        }

        if ($attempts->contains(fn (CheckoutAttempt $a): bool => $a->reversed_at !== null)) {
            return PayableState::Cancelled;
        }

        return $attempts->contains(fn (CheckoutAttempt $a): bool => in_array($a->state, [AttemptState::Created, AttemptState::Live, AttemptState::Authorized], true))
            ? PayableState::Frozen
            : PayableState::Open;
    }

    /**
     * Runs $callback in a transaction holding a row lock on this model. Nesting is safe.
     *
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     */
    public function lock(Closure $callback): mixed
    {
        return DB::connection($this->getConnectionName())->transaction(function () use ($callback): mixed {
            static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            return $callback();
        });
    }
}
