<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Testing;

/**
 * A webhook notification to post in a test: the raw JSON body and its headers, without the
 * secret (InteractsWithMibGlobalPay::postMibNotification() adds the configured one).
 */
final readonly class FakeNotification
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $body,
        public array $headers,
    ) {
    }

    public function id(): string
    {
        return $this->headers['X-Notification-ID'];
    }
}
