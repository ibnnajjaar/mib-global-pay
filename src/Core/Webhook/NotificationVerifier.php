<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Webhook;

use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Enums\TransactionType;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\SecretMismatch;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedNotification;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\ConfigurationException;

/**
 * The only way to obtain a trusted Notification.
 *
 * Per the MIB webhook guide: the gateway sends the merchant's notification secret (a random
 * 32-character string from Merchant Administration) in X-Notification-Secret, and only to
 * HTTPS URLs; X-Notification-ID is identical for redeliveries; X-Notification-Attempt counts
 * attempts (up to 20 over 3 days). The endpoint must answer 2xx within 2 seconds, so verify,
 * dedupe and queue the processing. HTTPS itself is enforced by the HTTP layer (Laravel stage 6).
 *
 * Bodies are REST-JSON. NVP-format notifications (a Merchant Administration option) are not
 * supported and are rejected as malformed.
 */
final readonly class NotificationVerifier
{
    public const SECRET_HEADER = 'X-Notification-Secret';

    public const ID_HEADER = 'X-Notification-ID';

    public const ATTEMPT_HEADER = 'X-Notification-Attempt';

    /**
     * @throws ConfigurationException when no secret is configured (fails closed)
     */
    public function __construct(
        #[\SensitiveParameter]
        private string $secret,
    ) {
        if (trim($secret) === '') {
            throw new ConfigurationException('A webhook notification secret is required (MIB_WEBHOOK_SECRET); notifications cannot be verified without it');
        }
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['secret' => '********'];
    }

    /**
     * @param array<array-key, mixed> $headers plain (getallheaders()) or Symfony/Laravel style (name => list of values)
     *
     * @throws SecretMismatch        the secret header is missing or wrong; checked before anything is parsed
     * @throws MalformedNotification the ID header is missing, or the body is not a JSON object
     */
    public function verify(string $body, array $headers): Notification
    {
        $sent = self::header($headers, self::SECRET_HEADER);

        if ($sent === null || ! hash_equals($this->secret, $sent)) {
            throw new SecretMismatch('Webhook notification secret is missing or does not match');
        }

        return self::parse($body, $headers);
    }

    /**
     * Build a Notification WITHOUT checking the secret. For tests and the testing fake only;
     * never call this with request data.
     *
     * @param array<array-key, mixed> $headers
     *
     * @throws MalformedNotification
     */
    public static function unverified(string $body, array $headers): Notification
    {
        return self::parse($body, $headers);
    }

    /**
     * @param array<array-key, mixed> $headers
     *
     * @throws MalformedNotification
     */
    private static function parse(string $body, array $headers): Notification
    {
        $id = self::header($headers, self::ID_HEADER);
        if ($id === null) {
            throw new MalformedNotification('Webhook notification has no ' . self::ID_HEADER . ' header');
        }

        $attemptHeader = self::header($headers, self::ATTEMPT_HEADER);
        if ($attemptHeader !== null && preg_match('/^\d+$/', $attemptHeader) !== 1) {
            throw new MalformedNotification('Webhook notification has an invalid ' . self::ATTEMPT_HEADER . ' header');
        }
        $attempt = $attemptHeader === null ? null : (int) $attemptHeader;

        $decoded = json_decode($body, true);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new MalformedNotification('Webhook notification body is not a JSON object (NVP notifications are not supported)');
        }

        $payload = Payload::fromArray($decoded);

        try {
            return match (self::kindOf($payload)) {
                NotificationKind::Transaction => TransactionNotification::fromPayload($payload, $id, $attempt),
                NotificationKind::Authentication => AuthenticationNotification::fromPayload($payload, $id, $attempt),
                NotificationKind::Other => GenericNotification::fromPayload($payload, $id, $attempt),
            };
        } catch (MalformedPayload $e) {
            throw new MalformedNotification('Webhook notification body is malformed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws MalformedPayload
     */
    private static function kindOf(Payload $payload): NotificationKind
    {
        if ($payload->payload('transaction') === null) {
            return NotificationKind::Other;
        }

        if ($payload->enum('transaction.type', TransactionType::class) === TransactionType::Authentication) {
            return NotificationKind::Authentication;
        }

        return $payload->has('order.id') ? NotificationKind::Transaction : NotificationKind::Other;
    }

    /**
     * Case-insensitive header lookup; array values (Symfony/Laravel) use their first element.
     *
     * @param array<array-key, mixed> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (! is_string($key) || strcasecmp($key, $name) !== 0) {
                continue;
            }

            if (is_array($value)) {
                $value = reset($value);
            }

            if (! is_string($value) && ! is_int($value)) {
                return null;
            }

            $value = trim((string) $value);

            return $value === '' ? null : $value;
        }

        return null;
    }
}
