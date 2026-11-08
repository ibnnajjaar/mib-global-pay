<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Checkout;

use Illuminate\Database\Eloquent\Builder;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Payable;
use IbnNajjaar\MIBGlobalPay\Laravel\Support\Settings;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptState;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableResolver;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\ResolvedAttempt;
use IbnNajjaar\MIBGlobalPay\Laravel\Models\CheckoutAttempt;

/**
 * The default PayableResolver: finds attempts in the package's own table.
 */
final readonly class EloquentPayableResolver implements PayableResolver
{
    public function __construct(
        private Settings $settings,
    ) {
    }

    public function resolveAttempt(string $gatewayOrderId): ?ResolvedAttempt
    {
        $attempt = CheckoutAttempt::query()->where('gateway_order_id', $gatewayOrderId)->first();

        return $attempt === null ? null : self::resolved($attempt);
    }

    public function awaitingGateway(): iterable
    {
        $states = [AttemptState::Created, AttemptState::Live, AttemptState::Authorized, AttemptState::Expired];

        $watchFrom = new \DateTimeImmutable()->modify(sprintf('-%d seconds', $this->settings->lateCaptureWatchSeconds()));

        $attempts = CheckoutAttempt::query()
            ->whereIn('state', $states)
            ->orWhere(fn (Builder $retired): Builder => $retired
                ->where('updated_at', '>=', $watchFrom)
                ->where(fn (Builder $query): Builder => $query
                    ->where('state', AttemptState::Superseded)
                    ->orWhere(fn (Builder $failed): Builder => $failed->where('state', AttemptState::Failed)->whereNotNull('confirmed_unpaid_at'))));

        foreach ($attempts->lazyById() as $attempt) {
            $resolved = self::resolved($attempt);

            if ($resolved !== null) {
                yield $resolved;
            }
        }
    }

    private static function resolved(CheckoutAttempt $attempt): ?ResolvedAttempt
    {
        $payable = $attempt->payable;

        return $payable instanceof Payable ? new ResolvedAttempt($payable, $attempt) : null;
    }
}
