<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Jobs;

use Psr\Log\LoggerInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Reversal\ReversalHelper;
use IbnNajjaar\MIBGlobalPay\Core\Reversal\ReversalMethod;
use IbnNajjaar\MIBGlobalPay\Core\Reversal\ReversalOutcome;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\ReversalFailed;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\ReversalUnconfirmed;

/**
 * Reverses the latest approved payment/capture of one gateway order (void, then refund). The
 * transaction IDs are derived from the order and that capture, so a retry or a second job for the
 * same capture sends identical requests.
 *
 * Retries come from this class, not from the worker's --tries or --backoff. An unconfirmed result
 * is released and an exception is retried, about 45 minutes in all, each one re-reading the order.
 * When they run out, handle() or failed() logs an error and dispatches ReversalUnconfirmed. A refund
 * that definitely failed is not retried: it logs an error and dispatches ReversalFailed.
 *
 * Delays stay within 15 minutes, the most Amazon SQS accepts.
 */
final class ReverseCapture implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 6;

    /** @var list<int> seconds before attempts 2 to 6 */
    public array $backoff = [60, 300, 600, 900, 900];

    public function __construct(
        public readonly string $gatewayOrderId,
    ) {
    }

    public function handle(Gateway $gateway, LoggerInterface $logger): void
    {
        $outcome = new ReversalHelper($gateway)->reverseWithDerivedIds($this->gatewayOrderId, 'DOUBLE-CAPTURE');

        if ($outcome->method === ReversalMethod::Unconfirmed) {
            $delay = $this->nextDelay();

            if ($delay !== null) {
                $logger->warning('MIB double capture auto-reversal', $this->logContext($outcome));
                $this->release($delay);

                return;
            }

            $logger->error('MIB double capture auto-reversal unconfirmed, retries exhausted', $this->logContext($outcome));
            event(new ReversalUnconfirmed($this->gatewayOrderId, $outcome->ids, $outcome->transaction));

            return;
        }

        if ($outcome->method === ReversalMethod::Refund && ! $outcome->reversed) {
            $logger->error('MIB double capture auto-reversal refund failed', $this->logContext($outcome));
            event(new ReversalFailed($this->gatewayOrderId, $outcome->ids, $outcome->transaction));

            return;
        }

        $logger->notice('MIB double capture auto-reversal', $this->logContext($outcome));
    }

    public function failed(\Throwable $e): void
    {
        app(LoggerInterface::class)->error('MIB double capture auto-reversal unconfirmed, job failed', [
            'order' => $this->gatewayOrderId,
            'error' => $e->getMessage(),
        ]);

        event(new ReversalUnconfirmed($this->gatewayOrderId, null, null, $e->getMessage()));
    }

    /**
     * Seconds until the next attempt, or null when there will not be one: the attempts are used up,
     * or the job is not on a real queue (called directly, or on the sync queue, which drops a release).
     */
    private function nextDelay(): ?int
    {
        if ($this->job === null || $this->job instanceof SyncJob || $this->attempts() >= $this->tries) {
            return null;
        }

        return $this->backoff[$this->attempts() - 1] ?? 900;
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(ReversalOutcome $outcome): array
    {
        return [
            'order' => $this->gatewayOrderId,
            'method' => $outcome->method->value,
            'reversed' => $outcome->reversed,
            'transaction' => $outcome->transaction?->transactionId,
            'target_transaction' => $outcome->ids?->target,
            'void_transaction' => $outcome->ids?->void,
            'refund_transaction' => $outcome->ids?->refund,
        ];
    }
}
