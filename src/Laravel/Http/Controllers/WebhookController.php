<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use IbnNajjaar\MIBGlobalPay\Laravel\Support\Settings;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\Notification;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableResolver;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationStore;
use IbnNajjaar\MIBGlobalPay\Laravel\Jobs\ProcessNotification;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationVerifier;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\TransactionNotification;
use IbnNajjaar\MIBGlobalPay\Laravel\Http\Middleware\VerifyNotificationSecret;

/**
 * POST webhook endpoint. Does only cheap work so it answers well within the gateway's 2-second
 * window: (secret already verified by the middleware) claim the notification ID, queue
 * ProcessNotification, answer 200. A duplicate also gets 200. If queueing fails, the claim is
 * released and the gateway gets 503, so it redelivers (up to 20 times over 3 days).
 */
final readonly class WebhookController
{
    public function __construct(
        private Container $container,
        private Settings $settings,
        private Dispatcher $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->settings->webhookEnabled()) {
            return new JsonResponse(['error' => 'webhook disabled'], 404);
        }

        if (! $this->container->bound(PayableResolver::class)) {
            $this->logger->critical('MIB webhook received but no PayableResolver is bound; notification not accepted');

            return new JsonResponse(['error' => 'webhook not configured'], 503);
        }

        $notification = $request->attributes->get(VerifyNotificationSecret::ATTRIBUTE);
        if (! $notification instanceof Notification) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $store = $this->container->make(NotificationStore::class);
        $orderId = $notification instanceof TransactionNotification ? $notification->orderId : null;

        if (! $store->claim($notification->id(), $orderId)) {
            return new JsonResponse(['status' => 'duplicate']);
        }

        $headers = [NotificationVerifier::ID_HEADER => $notification->id()];
        if ($notification->attempt() !== null) {
            $headers[NotificationVerifier::ATTEMPT_HEADER] = (string) $notification->attempt();
        }

        $job = new ProcessNotification((string) $request->getContent(), $headers);
        $job->onConnection($this->settings->webhookConnection())->onQueue($this->settings->webhookQueue());

        try {
            $this->bus->dispatch($job);
        } catch (\Throwable $e) {
            $store->release($notification->id());
            $this->logger->error('MIB webhook could not be queued; claim released', ['notification' => $notification->id(), 'error' => $e->getMessage()]);

            return new JsonResponse(['error' => 'try again later'], 503);
        }

        return new JsonResponse(['status' => 'accepted']);
    }
}
