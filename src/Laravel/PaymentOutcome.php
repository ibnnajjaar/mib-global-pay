<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel;

use IbnNajjaar\MIBGlobalPay\Laravel\Events\Source;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableResolver;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\ResolvedAttempt;
use IbnNajjaar\MIBGlobalPay\Laravel\Outcome\OutcomeResult;
use IbnNajjaar\MIBGlobalPay\Laravel\Outcome\OutcomeProcessor;
use IbnNajjaar\MIBGlobalPay\Core\Verification\HostedCheckoutReturn;

/**
 * Runs the outcome pipeline for the return URL and for manual checks, so the host has one
 * code path for webhook, return URL and admin. The business-level dedupe makes the return URL
 * racing the webhook harmless.
 */
final readonly class PaymentOutcome
{
    public function __construct(
        private PayableResolver $resolver,
        private OutcomeProcessor $processor,
    ) {
    }

    /**
     * @param array<array-key, mixed> $query the return URL query ($request->query())
     *
     * @return array{indicatorMatched: bool, attempt: ?ResolvedAttempt, outcome: ?OutcomeResult}
     */
    public function resolveFromReturn(string $gatewayOrderId, array $query): array
    {
        $resolved = $this->resolver->resolveAttempt($gatewayOrderId);

        if ($resolved === null) {
            return ['indicatorMatched' => false, 'attempt' => null, 'outcome' => null];
        }

        $return = HostedCheckoutReturn::fromQuery($query);

        return [
            // The indicator is only a hint for the payer-facing page; the outcome always comes from verifyCapture().
            'indicatorMatched' => $return->matchesSuccessIndicator($resolved->attempt->successIndicator()),
            'attempt' => $resolved,
            'outcome' => $this->processor->process($resolved, Source::ReturnUrl),
        ];
    }

    public function check(ResolvedAttempt $resolved, Source $source = Source::Manual): OutcomeResult
    {
        return $this->processor->process($resolved, $source);
    }
}
