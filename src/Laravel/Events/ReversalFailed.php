<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Events;

use IbnNajjaar\MIBGlobalPay\Core\Reversal\ReversalIds;
use IbnNajjaar\MIBGlobalPay\Core\Responses\TransactionResponse;

/**
 * ReverseCapture got a definite failure on the refund, so the money was not returned and a person
 * must act. It is not retried: the MIB docs say a failed operation needs a new ID, and choosing to
 * refund again is a decision for the host.
 */
final readonly class ReversalFailed
{
    public function __construct(
        public string $gatewayOrderId,
        public ?ReversalIds $ids,
        public ?TransactionResponse $refund,
    ) {
    }
}
