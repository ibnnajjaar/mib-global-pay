<?php

namespace IbnNajjaar\MIBGlobalPay\Responses\DataObjects;

use IbnNajjaar\MIBGlobalPay\Support\Arr;
use IbnNajjaar\MIBGlobalPay\Contracts\IsResponseData;

class WebhookResponseData implements IsResponseData
{
    /**
     * @var string|null
     */
    private $order_reference;

    /**
     * @var float|null
     */
    private $order_amount;

    /**
     * @var string|null
     */
    private $order_currency;

    /**
     * @var string|null
     */
    private $status;

    private $result;

    private $gateway_code;

    private $transaction_type;

    /**
     * @var string|null
     */
    private $notification_secret;

    /**
     * @var array|null
     */
    private $raw_response;

    /**
     * Request headers, keys lowercased, values flattened to strings.
     *
     * @var array
     */
    private $headers = [];

    public function __construct(
        ?string $order_reference = null,
        ?float $order_amount = null,
        ?string $status = null,
        ?string $order_currency = null,
        ?string $notification_secret = null,
        ?string $result = null,
        ?string $gateway_code = null,
        ?string $transaction_type = null,
        ?array $raw_response = null
    ) {
        $this->order_reference = $order_reference;
        $this->order_amount = $order_amount;
        $this->status = $status;
        $this->order_currency = $order_currency;
        $this->notification_secret = $notification_secret;
        $this->result = $result;
        $this->gateway_code = $gateway_code;
        $this->transaction_type = $transaction_type;
        $this->raw_response = $raw_response;
    }

    /**
     * @param array $response Decoded JSON body of the notification
     * @param array $headers Request headers, either getallheaders() style
     *                       (['X-Notification-Secret' => 'abc']) or Symfony/Laravel
     *                       style (['x-notification-secret' => ['abc']])
     */
    public static function fromArray(array $response, array $headers = []): self
    {
        $headers = self::normalizeHeaders($headers);

        $instance = new self(
            Arr::string(Arr::get($response, 'order.id')),
            Arr::float(Arr::get($response, 'order.amount')),
            Arr::lower(Arr::get($response, 'order.status')),
            Arr::string(Arr::get($response, 'order.currency')),
            $headers['x-notification-secret'] ?? null,
            Arr::lower(Arr::get($response, 'result')),
            Arr::lower(Arr::get($response, 'response.gatewayCode')),
            Arr::lower(Arr::get($response, 'transaction.type')),
            $response
        );
        $instance->setHeaders($headers);

        return $instance;
    }

    /**
     * Build from the raw JSON request body.
     */
    public static function fromJson(string $body, array $headers = []): self
    {
        $decoded = json_decode($body, true);

        return self::fromArray(is_array($decoded) ? $decoded : [], $headers);
    }

    /**
     * Build from the current PHP request (php://input and the request headers).
     * The gateway sends a JSON body, so $_POST is empty for webhook notifications.
     */
    public static function fromGlobals(): self
    {
        $body = file_get_contents('php://input');

        return self::fromJson($body === false ? '' : $body, self::requestHeaders());
    }

    private static function requestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                return $headers;
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && strpos($key, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                continue;
            }
            $value = self::headerValue([$name => $value], $name);
            if ($value !== null) {
                $normalized[strtolower($name)] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Case-insensitive header lookup. Array values (Symfony/Laravel style) return their first element.
     */
    private static function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (!is_string($key) || strcasecmp($key, $name) !== 0) {
                continue;
            }
            if (is_array($value)) {
                $value = reset($value);
            }

            return is_scalar($value) ? (string) $value : null;
        }

        return null;
    }

    private function setHeaders(array $headers): void
    {
        $this->headers = $headers;
    }

    public function getOrderReference(): ?string
    {
        return $this->order_reference;
    }

    public function getOrderAmount(): ?float
    {
        return $this->order_amount;
    }

    public function getOrderCurrency(): ?string
    {
        return $this->order_currency;
    }

    public function getOrderStatus(): ?string
    {
        return $this->status !== null ? strtolower($this->status) : null;
    }

    public function getNotificationSecret(): ?string
    {
        return $this->notification_secret;
    }

    public function getResult(): ?string
    {
        return $this->result ? strtolower($this->result) : null;
    }

    public function getGatewayCode(): ?string
    {
        return $this->gateway_code ? strtolower($this->gateway_code) : null;
    }

    public function getTransactionType(): ?string
    {
        return $this->transaction_type ? strtolower($this->transaction_type) : null;
    }

    public function getRawResponse(): ?array
    {
        return $this->raw_response;
    }

    public function paymentIsSuccessful(): bool
    {
        return $this->getOrderStatus() === 'captured'
            && $this->getResult() === 'success'
            && $this->getGatewayCode() === 'approved'
            && $this->getTransactionType() === 'payment';
    }

    public function getNotificationId(): ?string
    {
        return self::headerValue($this->headers, 'X-Notification-ID');
    }

    public function getNotificationAttempt(): ?int
    {
        $attempt = self::headerValue($this->headers, 'X-Notification-Attempt');

        return is_numeric($attempt) ? (int) $attempt : null;
    }

    public function getTransactionId(): ?string
    {
        return Arr::string($this->raw('transaction.id'));
    }

    public function getTransactionAmount(): ?float
    {
        return Arr::float($this->raw('transaction.amount'));
    }

    public function getTransactionCurrency(): ?string
    {
        return Arr::string($this->raw('transaction.currency'));
    }

    public function getTargetTransactionId(): ?string
    {
        return Arr::string($this->raw('transaction.targetTransactionId'));
    }

    /**
     * The transaction this notification is about (payment, refund, void, capture, ...).
     */
    public function getTransaction(): TransactionResponseData
    {
        return TransactionResponseData::fromArray($this->raw_response ?? []);
    }

    public function isRefund(): bool
    {
        return $this->getTransaction()->isRefund();
    }

    public function isVoid(): bool
    {
        return $this->getTransaction()->isVoid();
    }

    /**
     * @return mixed
     */
    private function raw(string $path)
    {
        return Arr::get($this->raw_response ?? [], $path);
    }
}
