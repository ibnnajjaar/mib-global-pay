<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Console;

use Illuminate\Console\Command;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\Source;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableResolver;
use IbnNajjaar\MIBGlobalPay\Laravel\Outcome\OutcomeProcessor;

/**
 * Runs the outcome pipeline for every attempt the host reports as awaiting the gateway.
 * Covers lost webhooks and the frozen-session policy; schedule it (e.g. every five minutes).
 */
final class ReconcileCommand extends Command
{
    protected $signature = 'mib:reconcile';

    protected $description = 'Verify attempts awaiting the MIB gateway and dispatch their outcome events';

    public function handle(PayableResolver $resolver, OutcomeProcessor $processor): int
    {
        $rows = [];
        $failures = 0;

        foreach ($resolver->awaitingGateway() as $resolved) {
            $orderId = $resolved->attempt->gatewayOrderId();

            try {
                $result = $processor->process($resolved, Source::Reconcile);
                $rows[] = [
                    $orderId,
                    $result->verification === null ? '-' : $result->verification->status->value,
                    $result->event === null ? ($result->duplicate ? 'duplicate' : '-') : class_basename($result->event),
                ];
            } catch (\Throwable $e) {
                $failures++;
                $rows[] = [$orderId, 'error', $e->getMessage()];
                report($e);
            }
        }

        if ($rows === []) {
            $this->info('No attempts awaiting the gateway.');

            return self::SUCCESS;
        }

        $this->table(['Order ID', 'Verification', 'Event'], $rows);

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
