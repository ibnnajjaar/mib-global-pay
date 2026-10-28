<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Testing;

use Closure;
use LogicException;
use PHPUnit\Framework\Assert;
use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Gateway;
use IbnNajjaar\MIBGlobalPay\Core\Clients\OrderClient;
use IbnNajjaar\MIBGlobalPay\Core\Contracts\Operation;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Clients\GatewayClient;
use IbnNajjaar\MIBGlobalPay\Core\Clients\SessionClient;
use IbnNajjaar\MIBGlobalPay\Core\Clients\CheckoutClient;
use IbnNajjaar\MIBGlobalPay\Core\Requests\SessionUpdate;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\Refund;
use IbnNajjaar\MIBGlobalPay\Core\Responses\GatewayStatus;
use IbnNajjaar\MIBGlobalPay\Core\Responses\OrderResponse;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\Capture;
use IbnNajjaar\MIBGlobalPay\Core\Requests\CheckoutRequest;
use IbnNajjaar\MIBGlobalPay\Core\Responses\PaymentOptions;
use IbnNajjaar\MIBGlobalPay\Core\Responses\SessionDetails;
use IbnNajjaar\MIBGlobalPay\Core\Responses\CheckoutSession;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Core\Verification\CaptureVerifier;
use IbnNajjaar\MIBGlobalPay\Core\Responses\TransactionResponse;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\RetrieveOrder;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Gateway\CheckGateway;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationResult;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\VoidTransaction;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Session\UpdateSession;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Session\RetrieveSession;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Checkout\InitiateCheckout;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\RetrieveTransaction;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\UpdateAuthorization;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Order\TransactionOperation;
use IbnNajjaar\MIBGlobalPay\Core\Operations\Gateway\PaymentOptionsInquiry;

/**
 * In-memory Gateway for host tests. No network.
 *
 * Install with MibGlobalPay::fake(): the container's Gateway binding and the facade then use it,
 * so the webhook job, OutcomeProcessor, PaymentOutcome and mib:reconcile all go through the fake.
 * Requests are still validated against the configuration, exactly like the real gateway client.
 *
 * Unstubbed calls get gateway-like defaults: Initiate Checkout returns a new session, an unknown
 * order is the gateway's 404 (verifyCapture() gives NotFound), Capture/Refund/Void/Update
 * Authorization are approved, Check Gateway is OPERATING. With strict(), any unstubbed call
 * throws a LogicException instead.
 */
final class MibGlobalPayFake implements Gateway
{
    private bool $strict = false;

    /** @var list<Operation<object>> */
    private array $sent = [];

    /** @var array<string, array{sessionId: string, successIndicator: string}> keyed by order ID, '*' for any */
    private array $sessions = [];

    /** @var array<string, CheckoutRequest> keyed by session ID */
    private array $createdSessions = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $orders = [];

    /** @var array<class-string, list<array<string, mixed>|\Throwable|Closure>> */
    private array $responses = [];

    public function __construct(
        private readonly GatewayConfig $config,
    ) {
    }

    /** Throw a LogicException on any call that has no stub. */
    public function strict(bool $strict = true): self
    {
        $this->strict = $strict;

        return $this;
    }

    /** Stub Initiate Checkout for $orderId (or for any order when null). */
    public function checkoutSession(?string $orderId = null, ?string $sessionId = null, ?string $successIndicator = null): self
    {
        $this->sessions[$orderId ?? '*'] = [
            'sessionId' => $sessionId ?? self::randomSessionId(),
            'successIndicator' => $successIndicator ?? bin2hex(random_bytes(8)),
        ];

        return $this;
    }

    /**
     * Stub Retrieve Order. Several payloads are returned in sequence, then the last repeats.
     * Payloads come from OrderFactory (or are raw gateway arrays); their "id" is set to $orderId.
     *
     * @param array<string, mixed> ...$payloads
     */
    public function order(string $orderId, array ...$payloads): self
    {
        if ($payloads === []) {
            throw new LogicException('order() needs at least one payload, e.g. OrderFactory::captured($amount)');
        }

        $this->orders[$orderId] = array_values(array_map(fn (array $p): array => ['id' => $orderId] + $p, $payloads));

        return $this;
    }

    /**
     * Stub any operation class with raw response payloads, exceptions to throw, or closures
     * receiving the operation. Returned in sequence, then the last repeats.
     *
     * @param class-string<Operation<object>> $operation
     * @param array<string, mixed>|\Throwable|Closure ...$responses
     */
    public function respondWith(string $operation, array|\Throwable|Closure ...$responses): self
    {
        if ($responses === []) {
            throw new LogicException('respondWith() needs at least one response');
        }

        $this->responses[$operation] = array_values($responses);

        return $this;
    }

    public function config(): GatewayConfig
    {
        return $this->config;
    }

    /**
     * @template T of object
     *
     * @param Operation<T> $operation
     *
     * @return T
     */
    public function send(Operation $operation): object
    {
        $operation->body($this->config); // validate exactly like the real client
        $this->sent[] = $operation;

        $payload = $this->stubbed($operation) ?? $this->default($operation);

        return $operation->parse(Payload::fromArray($payload), $this->config);
    }

    public function verifyCapture(string $orderId, Money $expected): VerificationResult
    {
        $verifier = new CaptureVerifier($this->config->approvalPolicy);

        try {
            $order = $this->orders()->retrieve($orderId);
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

    // MibGateway's shortcuts, so the fake can stand in behind the MibGlobalPay facade.

    public function initiateCheckout(CheckoutRequest $request): CheckoutSession
    {
        return $this->checkout()->initiate($request);
    }

    public function retrieveSession(string $sessionId): SessionDetails
    {
        return $this->sessions()->retrieve($sessionId);
    }

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

    public function refund(string $orderId, string $transactionId, Money $amount, ?string $targetTransactionId = null, ?string $reference = null): TransactionResponse
    {
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

    // ----------------------------------------------------------------------------------------
    // Inspection and assertions
    // ----------------------------------------------------------------------------------------

    /**
     * @template T of Operation<object>
     *
     * @param class-string<T>|null $operation
     *
     * @return ($operation is null ? list<Operation<object>> : list<T>)
     */
    public function sent(?string $operation = null): array
    {
        if ($operation === null) {
            return $this->sent;
        }

        return array_values(array_filter($this->sent, fn (Operation $op): bool => $op instanceof $operation));
    }

    /**
     * @param class-string<Operation<object>> $operation
     * @param (callable(Operation<object>): bool)|null $callback
     */
    public function assertSent(string $operation, ?callable $callback = null): void
    {
        $matching = array_filter($this->sent($operation), fn (Operation $op): bool => $callback === null || $callback($op));

        Assert::assertNotEmpty($matching, "No {$operation} was sent" . ($callback === null ? '.' : ' matching the callback.'));
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], array_map(fn (Operation $op): string => $op::class, $this->sent), 'Operations were sent to the gateway.');
    }

    /**
     * @param (callable(CheckoutRequest): bool)|null $callback
     */
    public function assertCheckoutInitiated(?callable $callback = null): void
    {
        $this->assertSent(InitiateCheckout::class, fn (Operation $op): bool => $op instanceof InitiateCheckout && ($callback === null || $callback($op->request)));
    }

    public function assertCheckoutNotInitiated(): void
    {
        Assert::assertSame([], $this->sent(InitiateCheckout::class), 'A checkout was initiated.');
    }

    public function assertOrderRetrieved(?string $orderId = null): void
    {
        $this->assertSent(RetrieveOrder::class, fn (Operation $op): bool => $op instanceof RetrieveOrder && ($orderId === null || $op->orderId === $orderId));
    }

    /**
     * @param (callable(Capture): bool)|null $callback
     */
    public function assertCaptured(?callable $callback = null): void
    {
        $this->assertSent(Capture::class, fn (Operation $op): bool => $op instanceof Capture && ($callback === null || $callback($op)));
    }

    /**
     * @param (callable(Refund): bool)|null $callback
     */
    public function assertRefunded(?callable $callback = null): void
    {
        $this->assertSent(Refund::class, fn (Operation $op): bool => $op instanceof Refund && ($callback === null || $callback($op)));
    }

    /**
     * @param (callable(VoidTransaction): bool)|null $callback
     */
    public function assertVoided(?callable $callback = null): void
    {
        $this->assertSent(VoidTransaction::class, fn (Operation $op): bool => $op instanceof VoidTransaction && ($callback === null || $callback($op)));
    }

    // ----------------------------------------------------------------------------------------

    /**
     * @param Operation<object> $operation
     *
     * @return array<string, mixed>|null
     */
    private function stubbed(Operation $operation): ?array
    {
        if (isset($this->responses[$operation::class])) {
            $queue = &$this->responses[$operation::class];
            $response = count($queue) > 1 ? array_shift($queue) : $queue[0];
            $response = $response instanceof Closure ? $response($operation) : $response;

            if ($response instanceof \Throwable) {
                throw $response;
            }

            /** @var array<string, mixed> $response */
            return $response;
        }

        if ($operation instanceof RetrieveOrder && isset($this->orders[$operation->orderId])) {
            $queue = &$this->orders[$operation->orderId];

            return count($queue) > 1 ? array_shift($queue) : $queue[0];
        }

        if ($operation instanceof InitiateCheckout) {
            $stub = $this->sessions[$operation->request->orderId] ?? $this->sessions['*'] ?? null;

            return $stub === null ? null : $this->checkoutPayload($operation->request, $stub['sessionId'], $stub['successIndicator']);
        }

        return null;
    }

    /**
     * @param Operation<object> $operation
     *
     * @return array<string, mixed>
     */
    private function default(Operation $operation): array
    {
        if ($this->strict) {
            throw new LogicException(sprintf(
                'MibGlobalPayFake is strict and has no stub for %s%s. Stub it with checkoutSession(), order() or respondWith().',
                $operation::class,
                $operation instanceof RetrieveOrder ? " (order {$operation->orderId})" : '',
            ));
        }

        return match (true) {
            $operation instanceof InitiateCheckout => $this->checkoutPayload($operation->request, self::randomSessionId(), bin2hex(random_bytes(8))),
            $operation instanceof RetrieveOrder => throw new GatewayException('Order not found', 404, ['cause' => 'INVALID_REQUEST', 'explanation' => 'Unable to find order ' . $operation->orderId]),
            $operation instanceof RetrieveTransaction => $this->transactionFromOrder($operation),
            $operation instanceof TransactionOperation => $this->approvedTransaction($operation),
            $operation instanceof RetrieveSession, $operation instanceof UpdateSession => $this->sessionPayload($operation->sessionId),
            $operation instanceof CheckGateway => ['status' => 'OPERATING', 'gatewayVersion' => 'fake'],
            $operation instanceof PaymentOptionsInquiry => [
                'result' => 'SUCCESS',
                'paymentTypes' => ['card' => [
                    'cardTypes' => [['cardType' => 'CREDIT'], ['cardType' => 'DEBIT']],
                    'currencies' => array_map(fn ($c): array => ['currency' => $c->value], $this->config->currencies),
                ]],
            ],
            default => throw new LogicException('MibGlobalPayFake has no default for ' . $operation::class . '; stub it with respondWith().'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutPayload(CheckoutRequest $request, string $sessionId, string $successIndicator): array
    {
        $this->createdSessions[$sessionId] = $request;

        return [
            'checkoutMode' => 'WEBSITE',
            'merchant' => $this->config->merchantId,
            'result' => 'SUCCESS',
            'session' => ['id' => $sessionId, 'updateStatus' => 'SUCCESS', 'version' => substr(bin2hex(random_bytes(5)), 0, 10)],
            'successIndicator' => $successIndicator,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(string $sessionId): array
    {
        $request = $this->createdSessions[$sessionId] ?? null;

        return [
            'merchant' => $this->config->merchantId,
            'session' => ['id' => $sessionId, 'updateStatus' => 'SUCCESS', 'version' => 'fakeversio'],
            'order' => $request === null ? [] : [
                'id' => $request->orderId,
                'amount' => $request->amount->toDecimal(),
                'currency' => $request->amount->currency->value,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionFromOrder(RetrieveTransaction $operation): array
    {
        $orders = $this->orders[$operation->orderId] ?? [];
        $order = $orders === [] ? [] : $orders[array_key_last($orders)];
        $transactions = is_array($order['transaction'] ?? null) ? $order['transaction'] : [];

        foreach ($transactions as $transaction) {
            if (is_array($transaction) && Payload::fromArray($transaction)->get('transaction.id') === $operation->transactionId) {
                /** @var array<string, mixed> $transaction */
                return $transaction;
            }
        }

        throw new GatewayException('Transaction not found', 404, ['cause' => 'INVALID_REQUEST', 'explanation' => 'Unable to find transaction ' . $operation->transactionId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function approvedTransaction(TransactionOperation $operation): array
    {
        [$type, $amount, $target] = match (true) {
            $operation instanceof Capture => ['CAPTURE', $operation->amount, null],
            $operation instanceof Refund => ['REFUND', $operation->amount, $operation->targetTransactionId],
            $operation instanceof VoidTransaction => ['VOID_PAYMENT', null, $operation->targetTransactionId],
            $operation instanceof UpdateAuthorization => ['AUTHORIZATION_UPDATE', $operation->amount, null],
            default => ['UNKNOWN', null, null],
        };

        return OrderFactory::transaction($operation->transactionId, $type, $amount, target: $target)
            + ['order' => ['id' => $operation->orderId]];
    }

    private static function randomSessionId(): string
    {
        return 'SESSION' . strtoupper(bin2hex(random_bytes(12)));
    }
}
