<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Support;

use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\OrderIdGenerator;

/**
 * Default attempt order IDs: {prefix}-{sanitised reference}-{attempt}, e.g. receipt
 * "(R)IUM/FN/2026/123", attempt 2 -> "ISIMS-R-IUM-FN-2026-123-2".
 *
 * Sanitising turns every character outside [A-Za-z0-9._-] (brackets included) into "-",
 * collapses runs of "-" and trims "-" from both ends. Only if the result is empty or the
 * whole ID is over 40 characters does it fall back to {prefix}-{16 base32 characters}.
 * The unsanitised reference belongs in order.reference.
 *
 * Uniqueness is the host's job (UNIQUE gateway_order_id); on a collision call random().
 */
final class GatewayOrderId implements OrderIdGenerator
{
    public const MAX_LENGTH = 40;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * @throws InvalidRequest
     */
    public function forAttempt(string $prefix, string $payableReference, int $attemptNumber): string
    {
        self::assertPrefix($prefix);

        if ($attemptNumber < 1) {
            throw new InvalidRequest('Attempt number must be at least 1');
        }

        $reference = self::sanitise($payableReference);
        $id = "{$prefix}-{$reference}-{$attemptNumber}";

        if ($reference === '' || strlen($id) > self::MAX_LENGTH) {
            return $this->random($prefix);
        }

        return $id;
    }

    /**
     * @throws InvalidRequest
     */
    public function random(string $prefix): string
    {
        self::assertPrefix($prefix);

        $bytes = random_bytes(10); // 80 bits -> 16 base32 characters
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32[(int) bindec($chunk)];
        }

        return "{$prefix}-{$encoded}";
    }

    public static function sanitise(string $reference): string
    {
        $replaced = (string) preg_replace('/[^A-Za-z0-9._-]/', '-', $reference);
        $collapsed = (string) preg_replace('/-{2,}/', '-', $replaced);

        return trim($collapsed, '-');
    }

    /**
     * @throws InvalidRequest
     */
    private static function assertPrefix(string $prefix): void
    {
        if (preg_match('/^[A-Za-z0-9]{1,10}$/', $prefix) !== 1) {
            throw new InvalidRequest('Order prefix must be 1-10 characters from A-Z, a-z and 0-9');
        }
    }
}
