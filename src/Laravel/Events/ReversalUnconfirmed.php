<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Events;

use IbnNajjaar\MIBGlobalPay\Core\Reversal\ReversalIds;
use IbnNajjaar\MIBGlobalPay\Core\Responses\TransactionResponse;

/**
 * ReverseCapture ran out of retries without knowing whether the money went back: a person must act.
 * Do not refund by other means until the acquirer confirms what happened to the void or refund.
 *
 * $error is set when the last attempt ended in an exception. $ids is null then, because the capture
 * is only known from reading the order; ReversalIds::derive() gives them from the order and capture.
 */
final readonly class ReversalUnconfirmed
{
    public function __construct(
        public string $gatewayOrderId,
        public ?ReversalIds $ids,
        public ?TransactionResponse $transaction,
        public ?string $error = null,
    ) {
    }
}
