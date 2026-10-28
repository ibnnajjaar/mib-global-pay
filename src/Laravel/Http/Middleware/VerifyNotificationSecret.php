<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use IbnNajjaar\MIBGlobalPay\Laravel\Support\Settings;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\SecretMismatch;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationVerifier;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedNotification;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\ConfigurationException;

/**
 * Verifies the notification before anything else. Never answers 2xx to an unverified request:
 * - no secret configured: 503 (fail closed; the gateway retries once it is fixed)
 * - missing/wrong X-Notification-Secret: 401
 * - verified but malformed (no X-Notification-ID, non-JSON body): 400
 */
final readonly class VerifyNotificationSecret
{
    public const ATTRIBUTE = 'mib-global-pay.notification';

    public function __construct(
        private Settings $settings,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ConfigurationException when no secret is configured (the container binding fails closed)
     */
    private function verifier(): NotificationVerifier
    {
        if ($this->settings->webhookSecretUsesDeprecatedEnv()) {
            $this->logger->warning('MIB_WEBHOOK_SIGNATURE is deprecated; rename it to MIB_WEBHOOK_SECRET.');
        }

        return new NotificationVerifier($this->settings->webhookSecret());
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $verifier = $this->verifier();
        } catch (ConfigurationException $e) {
            $this->logger->critical('MIB webhook received but no notification secret is configured', ['error' => $e->getMessage()]);

            return new JsonResponse(['error' => 'webhook not configured'], 503);
        }

        try {
            $notification = $verifier->verify((string) $request->getContent(), $request->headers->all());
        } catch (SecretMismatch) {
            $this->logger->warning('MIB webhook rejected: notification secret missing or wrong', ['ip' => $request->ip()]);

            return new JsonResponse(['error' => 'unauthorized'], 401);
        } catch (MalformedNotification $e) {
            $this->logger->warning('MIB webhook rejected: malformed notification', ['error' => $e->getMessage()]);

            return new JsonResponse(['error' => 'malformed notification'], 400);
        }

        $request->attributes->set(self::ATTRIBUTE, $notification);

        /** @var Response */
        return $next($request);
    }
}
