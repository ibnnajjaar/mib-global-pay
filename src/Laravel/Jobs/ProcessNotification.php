<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationStore;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationOutcome;
use IbnNajjaar\MIBGlobalPay\Laravel\Outcome\OutcomeProcessor;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationVerifier;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedNotification;

/**
 * Processes a notification that WebhookController already verified and claimed. Carries the raw
 * body and the ID/attempt headers rather than objects, so it serializes cleanly.
 *
 * Retries come from this class, not from the worker's --tries or --backoff. The whole window is
 * about 18 minutes: long enough to ride out a gateway blip or a failing listener, while mib:reconcile
 * can dispatch again for attempts still awaiting the gateway, and queue:retry for anything else. The
 * status stays claimed between attempts and failed() runs once, at the end, so a notification whose
 * money event never completed ends as Failed, not Processed.
 */
final class ProcessNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> seconds before attempts 2 to 5 */
    public array $backoff = [30, 120, 300, 600];

    /**
     * @param array<string, string> $headers X-Notification-ID and X-Notification-Attempt only
     */
    public function __construct(
        public readonly string $body,
        public readonly array $headers,
    ) {
    }

    /**
     * Written out because Illuminate's FailOnException, which does the same, only exists from Laravel
     * 12.19 and the package installs on all of 12. Without it, every delivery on an older 12 fails.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [static function (self $job, callable $next): mixed {
            try {
                return $next($job);
            } catch (\Throwable $e) {
                if (self::isPermanent($e)) {
                    $job->fail($e);
                }

                throw $e;
            }
        }];
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

    /**
     * True when sending the same job again must end the same way: a body or header the parser
     * rejects, or a request the gateway refuses as invalid. An identical GET gets the same 4xx,
     * apart from statuses that mean "try later" (408, 425, 429) and credentials (401, 403), which
     * the host can correct while the retries are still running. A 4xx not in the gateway's error
     * format did not come from the gateway as far as we can tell: a wrong host or a proxy, which may
     * be corrected or clear up. Anything else is retried.
     */
    private static function isPermanent(\Throwable $e): bool
    {
        if ($e instanceof GatewayException) {
            return $e->isClientError() && $e->isGatewayError() && ! in_array($e->httpStatus(), [401, 403, 408, 425, 429], true);
        }

        return $e instanceof MalformedNotification || $e instanceof InvalidRequest;
    }
}
