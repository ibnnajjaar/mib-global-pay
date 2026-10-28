<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Jobs;

use Psr\Log\LoggerInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Support\TransactionId;
use IbnNajjaar\MIBGlobalPay\Core\Reversal\ReversalHelper;

/**
 * Reverses the latest approved payment/capture of one gateway order (void, then refund).
 * The transaction IDs are generated once, when the job is created, so a retried job resends
 * the identical requests.
 */
final class ReverseCapture implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public readonly string $voidTransactionId;

    public readonly string $refundTransactionId;

    public function __construct(
        public readonly string $gatewayOrderId,
    ) {
        $this->voidTransactionId = TransactionId::generate('void');
        $this->refundTransactionId = TransactionId::generate('refund');
    }

    public function handle(Gateway $gateway, LoggerInterface $logger): void
    {
        $outcome = (new ReversalHelper($gateway))->reverse($this->gatewayOrderId, $this->voidTransactionId, $this->refundTransactionId, 'DOUBLE-CAPTURE');

        $logger->notice('MIB double capture auto-reversal', [
            'order' => $this->gatewayOrderId,
            'method' => $outcome->method->value,
            'reversed' => $outcome->reversed,
            'transaction' => $outcome->transaction?->transactionId,
        ]);
    }
}
