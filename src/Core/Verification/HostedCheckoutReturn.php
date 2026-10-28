<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Verification;

/**
 * The query parameters on the return URL after Hosted Checkout.
 *
 * A matching indicator only means "go and call verifyCapture()". It is never proof of payment.
 */
final readonly class HostedCheckoutReturn
{
    private function __construct(
        public ?string $resultIndicator,
        public ?string $sessionVersion,
        public ?string $orderId,
    ) {
    }

    /**
     * @param array<array-key, mixed> $query e.g. $_GET or $request->query()
     */
    public static function fromQuery(array $query): self
    {
        return new self(
            self::stringValue($query['resultIndicator'] ?? null),
            self::stringValue($query['sessionVersion'] ?? null),
            self::stringValue($query['order'] ?? null),
        );
    }

    /**
     * Timing-safe comparison with the successIndicator stored at Initiate Checkout.
     */
    public function matchesSuccessIndicator(?string $storedSuccessIndicator): bool
    {
        return $this->resultIndicator !== null
            && $storedSuccessIndicator !== null
            && $storedSuccessIndicator !== ''
            && hash_equals($storedSuccessIndicator, $this->resultIndicator);
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
