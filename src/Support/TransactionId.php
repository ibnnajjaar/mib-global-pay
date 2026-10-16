<?php

namespace IbnNajjaar\MIBGlobalPay\Support;

final class TransactionId
{
    /**
     * Generate a transaction ID such as "txn-1a2b3c4d5e6f7a8b".
     * Persist it before sending the request, so a timed-out request can be retried with the same ID.
     *
     * @throws \InvalidArgumentException
     */
    public static function generate(string $prefix = 'txn'): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/', $prefix)) {
            throw new \InvalidArgumentException('Transaction ID prefix must be 1-20 characters from A-Z, a-z, 0-9, "_" and "-"');
        }

        return $prefix . '-' . bin2hex(random_bytes(8));
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function validate(string $id, string $field_name = 'Transaction ID'): void
    {
        if ($id === '') {
            throw new \InvalidArgumentException("$field_name is required");
        }

        if (strlen($id) > 40) {
            throw new \InvalidArgumentException("$field_name cannot exceed 40 characters");
        }
    }
}
