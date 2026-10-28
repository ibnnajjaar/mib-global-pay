<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationStore;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationOutcome;
use IbnNajjaar\MIBGlobalPay\Laravel\Outcome\OutcomeProcessor;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationVerifier;

/**
 * Processes a notification that WebhookController already verified and claimed. Carries the raw
 * body and the ID/attempt headers rather than objects, so it serializes cleanly.
 */
final class ProcessNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param array<string, string> $headers X-Notification-ID and X-Notification-Attempt only
     */
    public function __construct(
        public readonly string $body,
        public readonly array $headers,
    ) {
    }

    public function handle(OutcomeProcessor $processor, NotificationStore $store): void
    {
        // Already verified by the controller; the secret header is deliberately not stored in the queue.
        $notification = NotificationVerifier::unverified($this->body, $this->headers);

        $store->markProcessed($notification->id(), $processor->handleNotification($notification));
    }

    public function failed(\Throwable $e): void
    {
        $id = $this->headers[NotificationVerifier::ID_HEADER] ?? null;

        if ($id !== null) {
            app(NotificationStore::class)->markProcessed($id, NotificationOutcome::Failed);
        }
    }
}
