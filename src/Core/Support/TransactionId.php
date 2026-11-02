<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Support;

use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * Transaction IDs for Capture, Refund, Void and Update Authorization: new random ones, or ones
 * derived from what they act on.
 * Persist a random ID before sending, so a request without a response can be resent with the same ID.
 */
final class TransactionId
{
    /** The gateway's maximum length for transaction.id. */
    private const int MAX_LENGTH = 40;

    /**
     * e.g. "refund-9f86d081884c7d65" (prefix + 16 hex characters).
     *
     * @throws InvalidRequest
     */
    public static function generate(string $prefix = 'txn'): string
    {
        self::assertPrefix($prefix);

        return $prefix . '-' . bin2hex(random_bytes(8));
    }

    /**
     * The same prefix and parts always give the same ID, so nothing has to be persisted to resend
     * with it. Always 40 characters: the prefix, "-" and the start of a SHA-256 of the parts.
     *
     * @throws InvalidRequest
     */
    public static function derive(string $prefix, string ...$parts): string
    {
        self::assertPrefix($prefix);

        if ($parts === [] || in_array('', $parts, true)) {
            throw new InvalidRequest('A derived transaction ID needs at least one part, and no empty parts');
        }

        // Order and transaction IDs cannot contain control characters, so the separator is unambiguous.
        $hash = hash('sha256', implode("\0", $parts));

        return $prefix . '-' . substr($hash, 0, self::MAX_LENGTH - strlen($prefix) - 1);
    }

    /**
     * @throws InvalidRequest
     */
    private static function assertPrefix(string $prefix): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,20}$/', $prefix) !== 1) {
            throw new InvalidRequest('Transaction ID prefix must be 1-20 characters from A-Z, a-z, 0-9, "_" and "-"');
        }
    }
}
