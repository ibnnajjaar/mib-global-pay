<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Reversal;

use IbnNajjaar\MIBGlobalPay\Core\Support\TransactionId;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * The capture being reversed and the transaction IDs used to reverse it.
 */
final readonly class ReversalIds
{
    public function __construct(
        public string $target,
        public string $void,
        public string $refund,
    ) {
    }

    /**
     * The same order and target always give the same void and refund IDs, so anything that reverses
     * this capture again resends identical requests.
     *
     * @throws InvalidRequest
     */
    public static function derive(string $orderId, string $targetTransactionId): self
    {
        return new self(
            $targetTransactionId,
            TransactionId::derive('void', $orderId, $targetTransactionId),
            TransactionId::derive('refund', $orderId, $targetTransactionId),
        );
    }
}
