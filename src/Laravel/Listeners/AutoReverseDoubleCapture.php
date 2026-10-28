<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use IbnNajjaar\MIBGlobalPay\Laravel\Support\Settings;
use IbnNajjaar\MIBGlobalPay\Laravel\Jobs\ReverseCapture;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\MismatchReason;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\CaptureMismatch;

/**
 * Opt-in (MIB_AUTO_REVERSE_DOUBLE_CAPTURE, off by default): on CaptureMismatch(DoubleCapture),
 * queue a reversal (void, falling back to refund) of the second capture. The CaptureMismatch event
 * still reaches the host's own listeners either way. The job is dispatched after the database
 * transaction commits, never inside the payable lock.
 */
final readonly class AutoReverseDoubleCapture
{
    public function __construct(
        private Settings $settings,
        private Dispatcher $bus,
    ) {
    }

    public function handle(CaptureMismatch $event): void
    {
        if ($event->reason !== MismatchReason::DoubleCapture || ! $this->settings->autoReverseDoubleCapture()) {
            return;
        }

        $this->bus->dispatch((new ReverseCapture($event->gatewayOrderId))->afterCommit());
    }
}
