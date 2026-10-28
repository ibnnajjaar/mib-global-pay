<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Reversal;

use IbnNajjaar\MIBGlobalPay\Core\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Core\Responses\TransactionResponse;

/**
 * What ReversalHelper::reverse() did. Check $reversed: a declined refund is not a reversal.
 */
final readonly class ReversalOutcome
{
    public function __construct(
        public ReversalMethod $method,
        public bool $reversed,
        public ?TransactionResponse $transaction,
        public ?TransactionResponse $voidAttempt = null,
        public ?GatewayException $voidError = null,
    ) {
    }
}
