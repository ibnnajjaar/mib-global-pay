<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Support;

use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * New transaction IDs for Capture, Refund, Void and Update Authorization.
 * Persist the ID before sending, so a request without a response can be resent with the same ID.
 */
final class TransactionId
{
    /**
     * e.g. "refund-9f86d081884c7d65" (prefix + 16 hex characters).
     *
     * @throws InvalidRequest
     */
    public static function generate(string $prefix = 'txn'): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,20}$/', $prefix) !== 1) {
            throw new InvalidRequest('Transaction ID prefix must be 1-20 characters from A-Z, a-z, 0-9, "_" and "-"');
        }

        return $prefix . '-' . bin2hex(random_bytes(8));
    }
}
