<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Http;

use IbnNajjaar\MIBGlobalPay\Core\Exceptions\ConfigurationException;

/**
 * Opt-in retries for idempotent operations only (see Operation::isIdempotent()).
 *
 * Retried: transport errors (no response) and HTTP 5xx. Never retried: 4xx, declines,
 * and non-idempotent operations such as Initiate Checkout.
 *
 * MIB's guidance for a request without a response is to wait 60 seconds and resend
 * the identical request ("Making a Server API Request", FAQ), which is what
 * RetryPolicy::gatewayRecommended() does.
 */
final readonly class RetryPolicy
{
    /**
     * @throws ConfigurationException
     */
    public function __construct(
        public int $maxRetries,
        public int $delayMilliseconds,
    ) {
        if ($maxRetries < 1 || $maxRetries > 10) {
            throw new ConfigurationException('maxRetries must be between 1 and 10');
        }

        if ($delayMilliseconds < 0) {
            throw new ConfigurationException('delayMilliseconds must not be negative');
        }
    }

    /** One retry after 60 seconds, as the gateway documentation recommends. */
    public static function gatewayRecommended(): self
    {
        return new self(maxRetries: 1, delayMilliseconds: 60_000);
    }
}
