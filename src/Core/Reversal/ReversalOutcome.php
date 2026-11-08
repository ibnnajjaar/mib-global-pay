<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Reversal;

use IbnNajjaar\MIBGlobalPay\Core\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Core\Responses\TransactionResponse;

/**
 * What ReversalHelper::reverse() did. Check $reversed: a declined refund is not a reversal, and
 * neither is ReversalMethod::Unconfirmed, where $transaction is the void or refund whose result is
 * unknown, if there is one. $ids is null only when the order has no approved payment/capture.
 */
final readonly class ReversalOutcome
{
    public function __construct(
        public ReversalMethod $method,
        public bool $reversed,
        public ?TransactionResponse $transaction,
        public ?TransactionResponse $voidAttempt = null,
        public ?GatewayException $voidError = null,
        public ?ReversalIds $ids = null,
    ) {
    }
}
