<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core;

use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use IbnNajjaar\MIBGlobalPay\Core\Http\Redactor;
use IbnNajjaar\MIBGlobalPay\Core\Http\PathScope;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Http\RetryPolicy;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Clients\OrderClient;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Operation;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Clients\GatewayClient;
use IbnNajjaar\MIBGlobalPay\Core\Clients\SessionClient;
use IbnNajjaar\MIBGlobalPay\Core\Clients\CheckoutClient;
use IbnNajjaar\MIBGlobalPay\Core\Requests\SessionUpdate;
use IbnNajjaar\MIBGlobalPay\Core\Responses\GatewayStatus;
use IbnNajjaar\MIBGlobalPay\Core\Responses\OrderResponse;
use IbnNajjaar\MIBGlobalPay\Core\Requests\CheckoutRequest;
use IbnNajjaar\MIBGlobalPay\Core\Responses\PaymentOptions;
use IbnNajjaar\MIBGlobalPay\Core\Responses\SessionDetails;
use IbnNajjaar\MIBGlobalPay\Core\Responses\CheckoutSession;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;
use IbnNajjaar\MIBGlobalPay\Core\Verification\CaptureVerifier;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\TransportException;
use IbnNajjaar\MIBGlobalPay\Core\Responses\TransactionResponse;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationResult;

/**
 * The v2 gateway client.
 *
 * Transport is any PSR-18 client; by default a Guzzle 7 client with the configured timeout.
 * A custom client is responsible for its own timeouts. Request and response bodies are logged
 * at debug level with secrets redacted.
 */
final class MibGateway implements Gateway
{
    private readonly ClientInterface $http;

    private readonly RequestFactoryInterface $requests;

    private readonly StreamFactoryInterface $streams;

    private readonly LoggerInterface $logger;

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /**
     * @param RetryPolicy|null       $retryPolicy opt-in; applies to idempotent operations only
     * @param (\Closure(int): void)|null $sleep   receives milliseconds; injectable for tests
     */
    public function __construct(
        private readonly GatewayConfig $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
        private readonly ?RetryPolicy $retryPolicy = null,
        ?\Closure $sleep = null,
    ) {
        $factory = new HttpFactory();

        $this->http = $httpClient ?? new GuzzleClient([
            'timeout' => $config->timeout,
            'connect_timeout' => $config->timeout,
            'http_errors' => false,
        ]);
        $this->requests = $requestFactory ?? $factory;
        $this->streams = $streamFactory ?? $factory;
        $this->logger = $logger ?? new NullLogger();
        $this->sleep = $sleep ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
    }

    public function config(): GatewayConfig
    {
        return $this->config;
    }

    public function send(Operation $operation): object
    {
        $body = $operation->body($this->config);
        $request = $this->buildRequest($operation, $body);
        $attempts = 1 + ($operation->isIdempotent() && $this->retryPolicy !== null ? $this->retryPolicy->maxRetries : 0);
        $context = [
            'operation' => $operation::class,
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
        ];

        for ($attempt = 1; ; $attempt++) {
            $this->logger->debug('MIB request', $context + [
                'attempt' => $attempt,
                'body' => $body === null ? null : Redactor::redact($body),
            ]);

            $started = hrtime(true);

            try {
                $response = $this->http->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                $this->logger->warning('MIB request failed without a response', $context + [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $attempts) {
                    $this->pause($attempt, $context);

                    continue;
                }

                throw new TransportException('HTTP request failed: ' . $e->getMessage(), 0, $e);
            }

            $status = $response->getStatusCode();
            $rawBody = (string) $response->getBody();
            $decoded = json_decode($rawBody, true);

            $this->logger->debug('MIB response', $context + [
                'attempt' => $attempt,
                'status' => $status,
                'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
                'body' => is_array($decoded) ? Redactor::redact($decoded) : '[non-JSON body]',
            ]);

            if ($status >= 500 && $attempt < $attempts) {
                $this->logger->warning('MIB server error, retrying', $context + ['attempt' => $attempt, 'status' => $status]);
                $this->pause($attempt, $context);

                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw $this->gatewayException($response, $rawBody, $decoded);
            }

            if (! is_array($decoded)) {
                throw new MalformedPayload(sprintf('Gateway returned a non-JSON body (HTTP %d)', $status));
            }

            return $operation->parse(Payload::fromArray($decoded), $this->config);
        }
    }

    public function verifyCapture(string $orderId, Money $expected): VerificationResult
    {
        $verifier = new CaptureVerifier($this->config->approvalPolicy);

        try {
            $order = $this->retrieveOrder($orderId);
        } catch (GatewayException $e) {
            if (CaptureVerifier::isOrderNotFound($e)) {
                return $verifier->notFound($orderId, $expected);
            }

            throw $e;
        }

        return $verifier->verify($order, $expected);
    }

    public function checkout(): CheckoutClient
    {
        return new CheckoutClient($this);
    }

    public function sessions(): SessionClient
    {
        return new SessionClient($this);
    }

    public function orders(): OrderClient
    {
        return new OrderClient($this);
    }

    public function gateway(): GatewayClient
    {
        return new GatewayClient($this);
    }

    // Shortcuts for the common operations.

    public function initiateCheckout(CheckoutRequest $request): CheckoutSession
    {
        return $this->checkout()->initiate($request);
    }

    public function retrieveSession(string $sessionId): SessionDetails
    {
        return $this->sessions()->retrieve($sessionId);
    }

    /**
     * @experimental unconfirmed for Hosted Checkout sessions (DESIGN-v2.md §12 Q4)
     */
    public function updateSession(string $sessionId, SessionUpdate $update): SessionDetails
    {
        return $this->sessions()->update($sessionId, $update);
    }

    public function retrieveOrder(string $orderId): OrderResponse
    {
        return $this->orders()->retrieve($orderId);
    }

    public function retrieveTransaction(string $orderId, string $transactionId): TransactionResponse
    {
        return $this->orders()->retrieveTransaction($orderId, $transactionId);
    }

    public function capture(string $orderId, string $transactionId, Money $amount, ?string $reference = null): TransactionResponse
    {
        return $this->orders()->capture($orderId, $transactionId, $amount, $reference);
    }

    public function refund(
        string $orderId,
        string $transactionId,
        Money $amount,
        ?string $targetTransactionId = null,
        ?string $reference = null,
    ): TransactionResponse {
        return $this->orders()->refund($orderId, $transactionId, $amount, $targetTransactionId, $reference);
    }

    public function void(string $orderId, string $transactionId, string $targetTransactionId, ?string $reference = null): TransactionResponse
    {
        return $this->orders()->void($orderId, $transactionId, $targetTransactionId, $reference);
    }

    public function updateAuthorization(string $orderId, string $transactionId, ?Money $amount = null, ?string $reference = null): TransactionResponse
    {
        return $this->orders()->updateAuthorization($orderId, $transactionId, $amount, $reference);
    }

    public function checkGateway(): GatewayStatus
    {
        return $this->gateway()->check();
    }

    public function paymentOptionsInquiry(?Currency $currency = null, ?Money $amount = null): PaymentOptions
    {
        return $this->gateway()->paymentOptions($currency, $amount);
    }

    /**
     * @param Operation<object>         $operation
     * @param array<string, mixed>|null $body
     */
    private function buildRequest(Operation $operation, ?array $body): RequestInterface
    {
        $base = $operation->scope() === PathScope::Merchant ? $this->config->baseUrl() : $this->config->apiBaseUrl();

        $request = $this->requests
            ->createRequest($operation->method()->value, $base . $operation->path())
            ->withHeader('Accept', 'application/json');

        if ($operation->scope() === PathScope::Merchant) {
            $request = $request->withHeader(
                'Authorization',
                'Basic ' . base64_encode('merchant.' . $this->config->merchantId . ':' . $this->config->apiPassword),
            );
        }

        if ($body !== null) {
            $json = json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream($json));
        }

        return $request;
    }

    private function gatewayException(ResponseInterface $response, string $rawBody, mixed $decoded): GatewayException
    {
        $error = is_array($decoded) && isset($decoded['error']) && is_array($decoded['error']) ? $decoded['error'] : [];
        /** @var array<string, mixed> $error */
        $explanation = $error['explanation'] ?? $error['cause'] ?? null;
        $message = is_string($explanation) && $explanation !== '' ? $explanation : 'HTTP ' . $response->getStatusCode();

        return new GatewayException($message, $response->getStatusCode(), $error, $rawBody);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function pause(int $attempt, array $context): void
    {
        $delay = $this->retryPolicy === null ? 0 : $this->retryPolicy->delayMilliseconds;
        $this->logger->info('MIB retrying idempotent request', $context + ['attempt' => $attempt + 1, 'delay_ms' => $delay]);
        ($this->sleep)($delay);
    }
}
