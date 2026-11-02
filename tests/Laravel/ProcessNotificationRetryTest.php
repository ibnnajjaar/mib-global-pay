<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Database\Schema\Blueprint;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Queue\Events\JobProcessing;
use GuzzleHttp\Psr7\Request as Psr7Request;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Payable;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\Source;
use IbnNajjaar\MIBGlobalPay\Laravel\PaymentOutcome;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Settlement;
use IbnNajjaar\MIBGlobalPay\Core\Reversal\ReversalIds;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptState;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\PayableState;
use IbnNajjaar\MIBGlobalPay\Laravel\Jobs\ReverseCapture;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentFailed;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\VoidProcessed;
use IbnNajjaar\MIBGlobalPay\Tests\Support\GatewayHarness;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\ResolvedAttempt;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentPending;
use IbnNajjaar\MIBGlobalPay\Tests\Support\InMemoryAttempt;
use IbnNajjaar\MIBGlobalPay\Tests\Support\InMemoryPayable;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationStore;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\CaptureMismatch;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentCaptured;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentReversed;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\RefundProcessed;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationOutcome;
use IbnNajjaar\MIBGlobalPay\Laravel\Jobs\ProcessNotification;
use IbnNajjaar\MIBGlobalPay\Laravel\Outcome\OutcomeProcessor;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationVerifier;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\NotificationReceived;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\UnknownOrderNotification;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentCapturedForUnpayableOrder;

/**
 * Everything here goes through a real database queue and `queue:work --once --tries=1`, the worker
 * default, so the retry settings under test can only come from the job itself.
 */
function useRealQueue(string $store = 'cache'): void
{
    Carbon::setTestNow('2026-11-01 12:00:00');

    config()->set('database.default', 'testing');
    config()->set('logging.default', 'null');
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
    config()->set('queue.failed', ['driver' => 'database-uuids', 'database' => 'testing', 'table' => 'failed_jobs']);

    Schema::create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    Schema::create('failed_jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });

    if ($store === 'database') {
        config()->set('mib-global-pay.notification_store', 'database');
        (require __DIR__ . '/../../database/migrations/2026_10_28_000000_create_mib_global_pay_notifications_table.php')->up();
        app()->forgetInstance(NotificationStore::class);
    }
}

function runWorker(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);
}

/** Move the clock to when the released job becomes available; returns the delay it was given. */
function waitForRelease(): int
{
    $job = DB::table('jobs')->first();
    $delay = (int) $job->available_at - Carbon::now()->getTimestamp();
    Carbon::setTestNow(Carbon::now()->addSeconds($delay));

    return $delay;
}

function deliver(string $body, string $id = 'NOTIF-1'): Illuminate\Testing\TestResponse
{
    return test()->call('POST', '/mib/webhook', [], [], [], webhookServer($id), $body);
}

function gatewayTimeout(): ConnectException
{
    return new ConnectException('cURL error 28: timed out', new Psr7Request('GET', 'https://example.test'));
}

function outcomeOfNotification(string $id = 'NOTIF-1'): ?NotificationOutcome
{
    return app(NotificationStore::class)->outcomeOf($id);
}

/** @return ArrayObject<string, int> dispatch counts per event class */
function countEvents(): ArrayObject
{
    $counts = new ArrayObject();
    foreach ([NotificationReceived::class, UnknownOrderNotification::class, PaymentCaptured::class, PaymentCapturedForUnpayableOrder::class, CaptureMismatch::class, PaymentPending::class, PaymentFailed::class, PaymentReversed::class, RefundProcessed::class, VoidProcessed::class] as $class) {
        $counts[$class] = 0;
        Event::listen($class, function () use ($counts, $class): void {
            $counts[$class]++;
        });
    }

    return $counts;
}

/** Registered after countEvents(), so the event has already reached one listener when this throws. */
function listenerThrowsOnce(string $event): void
{
    $thrown = false;
    Event::listen($event, function () use (&$thrown): void {
        if (! $thrown) {
            $thrown = true;

            throw new RuntimeException('listener failed');
        }
    });
}

/** Wraps the bound store so that the named method throws the first time it is called. */
function storeFailsOnceOn(string $method): void
{
    app()->instance(NotificationStore::class, new class (app(NotificationStore::class), $method) implements NotificationStore {
        private bool $thrown = false;

        public function __construct(private NotificationStore $real, private string $method)
        {
        }

        private function failOnce(string $method): void
        {
            if ($method === $this->method && ! $this->thrown) {
                $this->thrown = true;

                throw new RuntimeException('store unavailable');
            }
        }

        public function claim(string $notificationId, ?string $orderId = null): bool
        {
            return $this->real->claim($notificationId, $orderId);
        }

        public function release(string $notificationId): void
        {
            $this->real->release($notificationId);
        }

        public function markProcessed(string $notificationId, NotificationOutcome $outcome): void
        {
            $this->failOnce('markProcessed');
            $this->real->markProcessed($notificationId, $outcome);
        }

        public function outcomeOf(string $notificationId): ?NotificationOutcome
        {
            return $this->real->outcomeOf($notificationId);
        }

        public function claimTransaction(string $orderId, string $transactionId, string $outcome): bool
        {
            return $this->real->claimTransaction($orderId, $transactionId, $outcome);
        }

        public function wasDelivered(string $orderId, string $transactionId, string $outcome): bool
        {
            return $this->real->wasDelivered($orderId, $transactionId, $outcome);
        }

        public function markDelivered(string $orderId, string $transactionId, string $outcome): void
        {
            $this->failOnce('markDelivered');
            $this->real->markDelivered($orderId, $transactionId, $outcome);
        }
    });
}

/** Registered after countEvents(). Throws while the switch is on, so a test can mend the listener. */
function listenerThrowsWhile(string $event, ArrayObject $broken): void
{
    Event::listen($event, function () use ($broken): void {
        if ($broken['on']) {
            throw new RuntimeException('listener failed');
        }
    });
}

/** Runs the job's five attempts, all of them failing. */
function runOutOfRetries(): void
{
    runWorker();
    foreach (range(1, 4) as $attempt) {
        waitForRelease();
        runWorker();
    }
}

/** @return ArrayObject<int, object> every instance of the event, in order */
function collectEvents(string $event): ArrayObject
{
    $seen = new ArrayObject();
    Event::listen($event, function (object $e) use ($seen): void {
        $seen[] = $e;
    });

    return $seen;
}

/**
 * A payable whose lock lets $window run once, after the callback has finished (and committed) but
 * before OutcomeProcessor gets control back: the moment a second worker can enter the lock.
 */
function payableWithWindow(Payable $inner, Closure $window): Payable
{
    return new class ($inner, $window) implements Payable {
        private bool $used = false;

        public function __construct(private Payable $inner, private Closure $window)
        {
        }

        public function payableReference(): string
        {
            return $this->inner->payableReference();
        }

        public function expectedAmount(): IbnNajjaar\MIBGlobalPay\Core\Money\Money
        {
            return $this->inner->expectedAmount();
        }

        public function payableState(): PayableState
        {
            return $this->inner->payableState();
        }

        public function settledBy(): ?Settlement
        {
            return $this->inner->settledBy();
        }

        public function attempts(): iterable
        {
            return $this->inner->attempts();
        }

        public function lock(Closure $callback): mixed
        {
            $result = $this->inner->lock($callback);

            if (! $this->used) {
                $this->used = true;
                ($this->window)();
            }

            return $result;
        }
    };
}

function settlementBy(string $orderId, string $transactionId = '1'): Settlement
{
    return new Settlement($orderId, $transactionId, frozenAmount(), new DateTimeImmutable('-1 hour'));
}

function orderWithoutTransactionId(float $captured = 1250.5, array $extra = []): array
{
    $order = orderJson(captured: $captured, extra: $extra);
    unset($order['transaction'][0]['transaction']['id']);

    return $order;
}

function approvedVoidPayload(): array
{
    return ['result' => 'SUCCESS', 'response' => ['gatewayCode' => 'APPROVED'], 'transaction' => ['id' => ReversalIds::derive(LARAVEL_ORDER, '1')->void, 'type' => 'VOID_PAYMENT', 'targetTransactionId' => '1']];
}

function orderWithApprovedVoid(): array
{
    $order = orderJson();
    $order['transaction'][] = approvedVoidPayload();

    return $order;
}

/** Bind a payable under the store/lock pairing being tested. */
function bindPayableFor(string $store, InMemoryPayable $payable): void
{
    $resolver = bindResolver($payable);

    if ($store === 'database') {
        $resolver->byOrderId[LARAVEL_ORDER] = new ResolvedAttempt(transactionalPayable($payable), $payable->attempts[0]);
    }
}

dataset('store and lock', [
    'cache store, plain lock' => ['cache'],
    'database store, DB::transaction lock' => ['database'],
]);

it('sets its own tries and backoff', function () {
    $job = new ProcessNotification('{}', []);

    expect($job->tries)->toBe(5)->and($job->backoff)->toBe([30, 120, 300, 600]);
});

it('retries a transient failure with a backoff on a --tries=1 worker, then succeeds', function (Closure $failure) {
    useRealQueue();
    bindResolver(hostPayable());
    $harness = fakeGatewayResponses([$failure(), GatewayHarness::json(200, orderJson())]);
    $counts = countEvents();

    deliver(notificationBody())->assertOk()->assertJson(['status' => 'accepted']);
    runWorker();

    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->value('attempts'))->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(outcomeOfNotification())->toBeNull()
        ->and($counts[PaymentCaptured::class])->toBe(0);

    // Before the backoff has passed, a worker finds nothing to do.
    runWorker();
    expect($harness->history)->toHaveCount(1);

    // A redelivery in the meantime does not queue a second job.
    deliver(notificationBody())->assertOk()->assertJson(['status' => 'duplicate']);
    expect(DB::table('jobs')->count())->toBe(1);

    expect(waitForRelease())->toBe(30);
    runWorker();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Processed)
        ->and($counts[PaymentCaptured::class])->toBe(1)
        ->and($counts[NotificationReceived::class])->toBe(1);
})->with([
    'timeout' => [fn () => gatewayTimeout()],
    'HTTP 500' => [fn () => GatewayHarness::json(500, ['result' => 'ERROR'])],
    'HTTP 503' => [fn () => GatewayHarness::json(503, 'Service Unavailable')],
    'HTTP 429' => [fn () => GatewayHarness::json(429, ['result' => 'ERROR'])],
    'HTTP 401 (credentials the host can correct)' => [fn () => GatewayHarness::json(401, ['result' => 'ERROR'])],
    'HTTP 404, HTML from a wrong host' => [fn () => GatewayHarness::json(404, '<html>Not Found</html>')],
    'HTTP 404, other JSON from a proxy' => [fn () => GatewayHarness::json(404, ['message' => 'Not Found'])],
    'HTTP 400, HTML from a proxy' => [fn () => GatewayHarness::json(400, '<html>Bad Request</html>')],
    'a garbled 200' => [fn () => GatewayHarness::json(200, '<html>proxy</html>')],
]);

it('marks the notification Failed only when the retries run out', function (string $store) {
    useRealQueue($store);
    bindResolver(hostPayable());
    $harness = fakeGatewayResponses(array_map(fn () => gatewayTimeout(), range(1, 5)));
    $counts = countEvents();

    deliver(notificationBody())->assertOk();

    $delays = [];
    foreach (range(1, 4) as $attempt) {
        runWorker();
        expect(outcomeOfNotification())->toBeNull()
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and(DB::table('jobs')->value('attempts'))->toBe($attempt);
        $delays[] = waitForRelease();
    }

    runWorker();

    expect($delays)->toBe([30, 120, 300, 600])
        ->and($harness->history)->toHaveCount(5)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Failed)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and($counts[NotificationReceived::class])->toBe(1);

    // The claim is kept, so the Failed record survives a redelivery.
    deliver(notificationBody())->assertOk()->assertJson(['status' => 'duplicate']);
    expect(outcomeOfNotification())->toBe(NotificationOutcome::Failed);
})->with('store and lock');

it('finishes a failed notification through queue:retry, without repeating what already went out', function () {
    useRealQueue();
    bindResolver(hostPayable());
    fakeGatewayResponses([...array_map(fn () => gatewayTimeout(), range(1, 5)), GatewayHarness::json(200, orderJson())]);
    $counts = countEvents();

    deliver(notificationBody())->assertOk();
    runWorker();
    foreach (range(1, 4) as $attempt) {
        waitForRelease();
        runWorker();
    }
    expect(outcomeOfNotification())->toBe(NotificationOutcome::Failed);

    Artisan::call('queue:retry', ['id' => ['all']]);
    runWorker();

    expect(outcomeOfNotification())->toBe(NotificationOutcome::Processed)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($counts[PaymentCaptured::class])->toBe(1)
        ->and($counts[NotificationReceived::class])->toBe(1);
});

it('fails a malformed job on its first attempt, without calling the gateway', function (string $body, array $headers, bool $hasId) {
    useRealQueue();
    bindResolver(hostPayable());
    $harness = fakeGatewayResponses([]);

    app(Illuminate\Contracts\Bus\Dispatcher::class)->dispatch(new ProcessNotification($body, $headers));
    runWorker();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and($harness->history)->toHaveCount(0);

    if ($hasId) {
        expect(outcomeOfNotification())->toBe(NotificationOutcome::Failed);
    }
})->with([
    'body is not JSON' => ['not json', ['X-Notification-ID' => 'NOTIF-1'], true],
    'body has a bad value' => [(string) json_encode(['order' => ['id' => 'O-1', 'currency' => 'MVR', 'totalCapturedAmount' => 'abc'], 'transaction' => ['id' => '1', 'type' => 'PAYMENT']]), ['X-Notification-ID' => 'NOTIF-1'], true],
    'bad attempt header' => ['{}', ['X-Notification-ID' => 'NOTIF-1', 'X-Notification-Attempt' => 'x'], true],
    'no notification ID' => ['{}', [], false],
]);

it('fails on the first attempt when the gateway refuses the request as invalid', function () {
    useRealQueue();
    bindResolver(hostPayable());
    $harness = fakeGatewayResponses([GatewayHarness::json(400, ['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Value is invalid']])]);

    deliver(notificationBody())->assertOk();
    runWorker();

    expect($harness->history)->toHaveCount(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Failed);
});

it('never reports an expired attempt as failed on a wrong-host 404, and ends Failed when the retries run out', function () {
    useRealQueue();
    bindResolver(hostPayable(expires: '-5 minutes'));
    $harness = fakeGatewayResponses(array_map(fn () => GatewayHarness::json(404, '<html>Not Found</html>'), range(1, 5)));
    $counts = countEvents();

    deliver(notificationBody())->assertOk();

    foreach (range(1, 4) as $attempt) {
        runWorker();
        expect(outcomeOfNotification())->toBeNull()
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and(DB::table('jobs')->value('attempts'))->toBe($attempt);
        waitForRelease();
    }

    runWorker();

    expect($harness->history)->toHaveCount(5)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('not recognised as a gateway error')
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Failed)
        ->and($counts[PaymentFailed::class])->toBe(0);
});

it('fails on the first attempt on a gateway 4xx that is not a not-found, and reports no payment as failed', function (int $status, array $error) {
    useRealQueue();
    bindResolver(hostPayable(expires: '-5 minutes'));
    $harness = fakeGatewayResponses([GatewayHarness::json($status, ['result' => 'ERROR', 'error' => $error])]);
    $counts = countEvents();

    deliver(notificationBody())->assertOk();
    runWorker();

    expect($harness->history)->toHaveCount(1)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Failed)
        ->and($counts[PaymentFailed::class])->toBe(0);
})->with([
    '404 about a field' => [404, ['cause' => 'INVALID_REQUEST', 'field' => 'order.id', 'validationType' => 'INVALID']],
    '400 about another field' => [400, ['cause' => 'INVALID_REQUEST', 'explanation' => 'Order not found', 'field' => 'order.reference', 'validationType' => 'INVALID']],
]);

it('reports an expired attempt as failed when the gateway says the order is unknown', function () {
    useRealQueue();
    bindResolver(hostPayable(expires: '-5 minutes'));
    fakeGatewayResponses([GatewayHarness::notFound()]);
    $counts = countEvents();

    deliver(notificationBody())->assertOk();
    runWorker();

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Processed)
        ->and($counts[PaymentFailed::class])->toBe(1);
});

it('fails on the first attempt when the attempt has an order ID no request can carry', function () {
    useRealQueue();
    $resolver = bindResolver();
    $attempt = new InMemoryAttempt(1, "BAD\nID", frozenAmount(), AttemptState::Live, new DateTimeImmutable('+20 minutes'));
    $resolver->byOrderId[LARAVEL_ORDER] = new ResolvedAttempt(new InMemoryPayable(frozenAmount(), PayableState::Frozen, [$attempt]), $attempt);
    $harness = fakeGatewayResponses([]);

    deliver(notificationBody())->assertOk();
    runWorker();

    expect($harness->history)->toHaveCount(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Failed);
});

it('does not send an event again when a later listener of the same event fails', function (string $store, string $event, string $body, array $orders) {
    useRealQueue($store);
    bindPayableFor($store, hostPayable());
    fakeGatewayResponses(array_map(fn (array $o) => GatewayHarness::json(200, $o), $orders));
    $counts = countEvents();
    listenerThrowsOnce($event);

    deliver($body)->assertOk();
    runWorker();
    expect($counts[$event])->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);

    waitForRelease();
    runWorker();

    expect($counts[$event])->toBe(1)
        ->and($counts[NotificationReceived::class])->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Processed);
})->with('store and lock')->with([
    'PaymentPending' => [PaymentPending::class, notificationBody(), [orderJson('PENDING', 'PENDING', 0), orderJson('PENDING', 'PENDING', 0)]],
    'PaymentFailed' => [PaymentFailed::class, notificationBody('PAYMENT', 'FAILURE', 'DECLINED'), [orderJson('DECLINED', 'FAILURE', 0), orderJson('DECLINED', 'FAILURE', 0)]],
    'NotificationReceived' => [NotificationReceived::class, notificationBody(), [orderJson('PENDING', 'PENDING', 0)]],
]);

it('does not send UnknownOrderNotification again', function (string $store) {
    useRealQueue($store);
    bindResolver();
    fakeGatewayResponses([]);
    $counts = countEvents();
    listenerThrowsOnce(UnknownOrderNotification::class);

    deliver(notificationBody())->assertOk();
    runWorker();
    waitForRelease();
    runWorker();

    expect($counts[UnknownOrderNotification::class])->toBe(1)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Processed);
})->with('store and lock');

it('does not send the earlier events again when a listener fails after them', function (string $store) {
    useRealQueue($store);
    bindPayableFor($store, hostPayable());
    fakeGatewayResponses([GatewayHarness::json(200, orderJson('PENDING', 'PENDING', 0)), GatewayHarness::json(200, orderJson('PENDING', 'PENDING', 0))]);
    $counts = countEvents();
    listenerThrowsOnce(PaymentPending::class);

    deliver(notificationBody('REFUND', txnId: 'R1'))->assertOk();
    runWorker();
    waitForRelease();
    runWorker();

    expect($counts[NotificationReceived::class])->toBe(1)
        ->and($counts[RefundProcessed::class])->toBe(1)
        ->and($counts[PaymentPending::class])->toBe(1)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Processed);
})->with('store and lock');

it('sends a lifecycle event once when the gateway times out after it', function (string $store, string $type, string $event) {
    useRealQueue($store);
    bindPayableFor($store, hostPayable());
    fakeGatewayResponses([gatewayTimeout(), GatewayHarness::json(200, orderJson(extra: ['totalRefundedAmount' => 1250.5]))]);
    $counts = countEvents();

    deliver(notificationBody($type, txnId: 'R1'))->assertOk();
    runWorker();
    expect($counts[$event])->toBe(1)->and($counts[PaymentReversed::class])->toBe(0);

    waitForRelease();
    runWorker();

    expect($counts[$event])->toBe(1)
        ->and($counts[PaymentReversed::class])->toBe(1)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Processed);
})->with('store and lock')->with([
    'refund' => ['REFUND', RefundProcessed::class],
    'void' => ['VOID_PAYMENT', VoidProcessed::class],
]);

it('sends a lifecycle event once even when the notification has no transaction ID', function () {
    useRealQueue();
    bindResolver(hostPayable());
    fakeGatewayResponses([gatewayTimeout(), GatewayHarness::json(200, orderJson('PENDING', 'PENDING', 0))]);
    $counts = countEvents();

    $body = json_decode(notificationBody('REFUND'), true);
    unset($body['transaction']['id']);

    deliver((string) json_encode($body))->assertOk();
    runWorker();
    waitForRelease();
    runWorker();

    expect($counts[RefundProcessed::class])->toBe(1)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Processed);
});

it('does not send an event again when recording the outcome fails after it went out', function () {
    useRealQueue();
    bindResolver(hostPayable());
    fakeGatewayResponses([GatewayHarness::json(200, orderJson('PENDING', 'PENDING', 0)), GatewayHarness::json(200, orderJson('PENDING', 'PENDING', 0))]);
    $counts = countEvents();

    storeFailsOnceOn('markProcessed');

    deliver(notificationBody())->assertOk();
    runWorker();
    waitForRelease();
    runWorker();

    expect($counts[PaymentPending::class])->toBe(1)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Processed);
});

it('still sends PaymentPending for a new notification, and for triggers without one', function () {
    useRealQueue();
    bindResolver(hostPayable());
    fakeGatewayResponses(array_map(fn () => GatewayHarness::json(200, orderJson('PENDING', 'PENDING', 0)), range(1, 3)));
    $counts = countEvents();

    deliver(notificationBody(), 'NOTIF-1')->assertOk();
    deliver(notificationBody(), 'NOTIF-2')->assertOk();
    runWorker();
    runWorker();
    expect($counts[PaymentPending::class])->toBe(2);

    Artisan::call('mib:reconcile');
    expect($counts[PaymentPending::class])->toBe(3);
});

// Money events: at least once until one dispatch completes, then never again.
$moneyEvents = [
    'PaymentCaptured' => [PaymentCaptured::class, fn () => hostPayable(), orderJson()],
    'PaymentCapturedForUnpayableOrder' => [PaymentCapturedForUnpayableOrder::class, fn () => hostPayable(PayableState::Cancelled), orderJson()],
    'CaptureMismatch (amount)' => [CaptureMismatch::class, fn () => hostPayable(), orderJson(captured: 1000.0)],
    'CaptureMismatch (double capture)' => [CaptureMismatch::class, fn () => hostPayable(settlement: settlementBy('ANOTHER-ORDER')), orderJson()],
    'PaymentReversed' => [PaymentReversed::class, fn () => hostPayable(), orderJson(extra: ['totalRefundedAmount' => 1250.5])],
];

it('sends a money event again after a listener failure, and never again once a dispatch completes', function (string $store, string $event, Closure $payable, array $order) {
    useRealQueue($store);
    bindPayableFor($store, $payable());
    fakeGatewayResponses(array_fill(0, 6, GatewayHarness::json(200, $order)));
    $counts = countEvents();
    listenerThrowsOnce($event);

    deliver(notificationBody())->assertOk();
    runWorker();

    expect($counts[$event])->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(outcomeOfNotification())->toBeNull();

    waitForRelease();
    runWorker();

    expect($counts[$event])->toBe(2)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Processed);

    // Every other trigger now finds the record: a new notification, reconcile, the return URL.
    deliver(notificationBody(), 'NOTIF-2')->assertOk()->assertJson(['status' => 'accepted']);
    runWorker();
    expect(Artisan::call('mib:reconcile'))->toBe(0);
    $return = app(PaymentOutcome::class)->resolveFromReturn(LARAVEL_ORDER, []);

    expect($counts[$event])->toBe(2)
        ->and($return['outcome']->duplicate)->toBeTrue()
        ->and(outcomeOfNotification('NOTIF-2'))->toBe(NotificationOutcome::Processed);
})->with('store and lock')->with($moneyEvents);

it('sends a refund or void event again after a listener failure, and never again once a dispatch completes', function (string $store, string $type, string $event) {
    useRealQueue($store);
    bindPayableFor($store, hostPayable());
    fakeGatewayResponses(array_fill(0, 3, GatewayHarness::json(200, orderJson('PENDING', 'PENDING', 0))));
    $counts = countEvents();
    listenerThrowsOnce($event);

    deliver(notificationBody($type, txnId: 'R1'))->assertOk();
    runWorker();
    expect($counts[$event])->toBe(1)->and(outcomeOfNotification())->toBeNull();

    waitForRelease();
    runWorker();
    expect($counts[$event])->toBe(2)->and(outcomeOfNotification())->toBe(NotificationOutcome::Processed);

    deliver(notificationBody($type, txnId: 'R1'), 'NOTIF-2')->assertOk();
    runWorker();
    expect($counts[$event])->toBe(2);

    // Another transaction on the same order is another event.
    deliver(notificationBody($type, txnId: 'R2'), 'NOTIF-3')->assertOk();
    runWorker();
    expect($counts[$event])->toBe(3);
})->with('store and lock')->with([
    'refund' => ['REFUND', RefundProcessed::class],
    'void' => ['VOID_PAYMENT', VoidProcessed::class],
]);

it('marks the notification Failed when a money event never completes, and queue:retry finishes it', function (string $store, string $event, string $body, Closure $payable, array $order) {
    useRealQueue($store);
    bindPayableFor($store, $payable());
    fakeGatewayResponses(array_fill(0, 6, GatewayHarness::json(200, $order)));
    $counts = countEvents();
    $broken = new ArrayObject(['on' => true]);
    listenerThrowsWhile($event, $broken);

    deliver($body)->assertOk();
    runOutOfRetries();

    expect($counts[$event])->toBe(5)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Failed)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);

    $broken['on'] = false;
    Artisan::call('queue:retry', ['id' => ['all']]);
    runWorker();

    expect($counts[$event])->toBe(6)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Processed)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
})->with('store and lock')->with([
    'PaymentCaptured' => [PaymentCaptured::class, notificationBody(), fn () => hostPayable(), orderJson()],
    'PaymentReversed' => [PaymentReversed::class, notificationBody(), fn () => hostPayable(), orderJson(extra: ['totalRefundedAmount' => 1250.5])],
    'RefundProcessed' => [RefundProcessed::class, notificationBody('REFUND', txnId: 'R1'), fn () => hostPayable(), orderJson('PENDING', 'PENDING', 0)],
    'VoidProcessed' => [VoidProcessed::class, notificationBody('VOID_PAYMENT', txnId: 'R1'), fn () => hostPayable(), orderJson('PENDING', 'PENDING', 0)],
]);

it('lets mib:reconcile dispatch a money event again after the retries ran out, then stop', function (string $store, string $event, Closure $payable, array $order) {
    useRealQueue($store);
    bindPayableFor($store, $payable());
    fakeGatewayResponses(array_fill(0, 8, GatewayHarness::json(200, $order)));
    $counts = countEvents();
    $broken = new ArrayObject(['on' => true]);
    listenerThrowsWhile($event, $broken);

    deliver(notificationBody())->assertOk();
    runOutOfRetries();
    expect($counts[$event])->toBe(5)->and(outcomeOfNotification())->toBe(NotificationOutcome::Failed);

    // Still failing: reconcile reports it and records nothing.
    expect(Artisan::call('mib:reconcile'))->toBe(1)->and($counts[$event])->toBe(6);

    $broken['on'] = false;
    expect(Artisan::call('mib:reconcile'))->toBe(0)->and($counts[$event])->toBe(7);

    expect(Artisan::call('mib:reconcile'))->toBe(0)->and($counts[$event])->toBe(7);
})->with('store and lock')->with($moneyEvents);

it('LIMIT: mib:reconcile does not reach an attempt the host no longer reports as awaiting the gateway', function (string $store, AttemptState $state) {
    useRealQueue($store);
    bindPayableFor($store, hostPayable(attemptState: $state));
    $harness = fakeGatewayResponses(array_fill(0, 6, GatewayHarness::json(200, orderJson())));
    $counts = countEvents();
    $broken = new ArrayObject(['on' => true]);
    listenerThrowsWhile(PaymentCaptured::class, $broken);

    deliver(notificationBody())->assertOk();
    runOutOfRetries();
    $broken['on'] = false;

    expect(Artisan::call('mib:reconcile'))->toBe(0)
        ->and($counts[PaymentCaptured::class])->toBe(5)
        ->and($harness->history)->toHaveCount(5);

    // The failed job is the way back for these.
    Artisan::call('queue:retry', ['id' => ['all']]);
    runWorker();

    expect($counts[PaymentCaptured::class])->toBe(6)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Processed);
})->with('store and lock')->with([
    'Captured' => [AttemptState::Captured],
    'Failed' => [AttemptState::Failed],
    'Superseded' => [AttemptState::Superseded],
]);

it('reaches a failed later listener again when a lock with no rollback kept an earlier settlement', function (string $store) {
    useRealQueue($store);
    $payable = hostPayable();
    bindResolver($payable);
    fakeGatewayResponses(array_fill(0, 4, GatewayHarness::json(200, orderJson())));
    $counts = countEvents();

    Event::listen(PaymentCaptured::class, function () use ($payable): void {
        $payable->settlement = settlementBy(LARAVEL_ORDER);
    });
    listenerThrowsOnce(PaymentCaptured::class);

    deliver(notificationBody())->assertOk();
    runWorker();
    expect($payable->settlement)->not->toBeNull()->and(outcomeOfNotification())->toBeNull();

    waitForRelease();
    runWorker();

    expect($counts[PaymentCaptured::class])->toBe(2)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Processed);

    // It completed, so the next trigger stops as usual.
    deliver(notificationBody(), 'NOTIF-2')->assertOk();
    runWorker();
    expect(app(PaymentOutcome::class)->resolveFromReturn(LARAVEL_ORDER, [])['outcome']->duplicate)->toBeTrue()
        ->and($counts[PaymentCaptured::class])->toBe(2);
})->with('store and lock');

it('marks the notification Failed when that later listener never succeeds', function () {
    useRealQueue();
    $payable = hostPayable();
    bindResolver($payable);
    fakeGatewayResponses(array_fill(0, 5, GatewayHarness::json(200, orderJson())));
    $counts = countEvents();
    Event::listen(PaymentCaptured::class, function () use ($payable): void {
        $payable->settlement = settlementBy(LARAVEL_ORDER);
    });
    listenerThrowsWhile(PaymentCaptured::class, new ArrayObject(['on' => true]));

    deliver(notificationBody())->assertOk();
    runOutOfRetries();

    expect($counts[PaymentCaptured::class])->toBe(5)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Failed);
});

it('LIMIT: a crash during the dispatch leaves a settlement that stops PaymentCaptured, with a lock with no rollback', function () {
    useRealQueue();
    $payable = hostPayable(settlement: settlementBy(LARAVEL_ORDER));
    bindResolver($payable);
    fakeGatewayResponses([GatewayHarness::json(200, orderJson())]);
    $counts = countEvents();

    // What the next worker finds: a settlement, no delivery record, and no sign that a listener failed.
    deliver(notificationBody())->assertOk();
    runWorker();

    expect($counts[PaymentCaptured::class])->toBe(0)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Processed);
});

it('sends a money event again when the crash comes after it completed, before it was recorded', function (string $store) {
    useRealQueue($store);
    bindPayableFor($store, hostPayable());
    fakeGatewayResponses(array_fill(0, 3, GatewayHarness::json(200, orderJson())));
    $counts = countEvents();
    storeFailsOnceOn('markDelivered');

    deliver(notificationBody())->assertOk();
    runWorker();
    expect($counts[PaymentCaptured::class])->toBe(1)->and(outcomeOfNotification())->toBeNull();

    waitForRelease();
    runWorker();
    expect($counts[PaymentCaptured::class])->toBe(2)->and(outcomeOfNotification())->toBe(NotificationOutcome::Processed);

    Artisan::call('mib:reconcile');
    expect($counts[PaymentCaptured::class])->toBe(2);
})->with('store and lock');

it('shows what a second worker does in the window between the lock and the record', function (string $store, string $event, Closure $payable, array $order, int $expected, bool $settles) {
    useRealQueue($store);
    bindResolver();
    $inner = $payable();
    $lock = $store === 'database' ? transactionalPayable($inner) : $inner;
    fakeGatewayResponses(array_fill(0, 3, GatewayHarness::json(200, $order)));
    $counts = countEvents();

    if ($settles) {
        Event::listen(PaymentCaptured::class, function () use ($inner): void {
            $inner->settlement = settlementBy(LARAVEL_ORDER);
        });
    }

    $attempt = $inner->attempts[0];
    $secondWorker = new ResolvedAttempt($lock, $attempt);
    $resolved = new ResolvedAttempt(payableWithWindow($lock, function () use ($secondWorker, $counts, $event): void {
        // The first dispatch has completed, and no record exists yet.
        expect($counts[$event])->toBe(1);
        app(OutcomeProcessor::class)->process($secondWorker, Source::Webhook);
    }), $attempt);

    app(OutcomeProcessor::class)->process($resolved, Source::Webhook);

    expect($counts[$event])->toBe($expected);

    // Both workers recorded, or one did and the settlement stopped the other. Nothing repeats after that.
    app(OutcomeProcessor::class)->process($secondWorker, Source::Reconcile);
    expect($counts[$event])->toBe($expected);
})->with('store and lock')->with([
    'PaymentCaptured stopped by the settlement' => [PaymentCaptured::class, fn () => hostPayable(), orderJson(), 1, true],
    'PaymentCaptured with a listener that does not settle' => [PaymentCaptured::class, fn () => hostPayable(), orderJson(), 2, false],
    'PaymentCapturedForUnpayableOrder repeats' => [PaymentCapturedForUnpayableOrder::class, fn () => hostPayable(PayableState::Cancelled), orderJson(), 2, false],
    'CaptureMismatch (amount) repeats' => [CaptureMismatch::class, fn () => hostPayable(), orderJson(captured: 1000.0), 2, false],
    'CaptureMismatch (double capture) repeats' => [CaptureMismatch::class, fn () => hostPayable(settlement: settlementBy('ANOTHER-ORDER')), orderJson(), 2, false],
    'PaymentReversed repeats' => [PaymentReversed::class, fn () => hostPayable(), orderJson(extra: ['totalRefundedAmount' => 1250.5]), 2, false],
]);

it('repeats a refund or void event when a second worker arrives during the dispatch, since it has no lock', function (string $store, string $type, string $event) {
    useRealQueue($store);
    bindPayableFor($store, hostPayable());
    fakeGatewayResponses(array_fill(0, 4, GatewayHarness::json(200, orderJson('PENDING', 'PENDING', 0))));
    $counts = countEvents();
    $body = notificationBody($type, txnId: 'R1');

    $overlapped = false;
    Event::listen($event, function () use (&$overlapped, $body): void {
        if (! $overlapped) {
            $overlapped = true;
            app(OutcomeProcessor::class)->handleNotification(NotificationVerifier::unverified($body, ['X-Notification-ID' => 'NOTIF-2']));
        }
    });

    app(OutcomeProcessor::class)->handleNotification(NotificationVerifier::unverified($body, ['X-Notification-ID' => 'NOTIF-1']));
    expect($counts[$event])->toBe(2);

    app(OutcomeProcessor::class)->handleNotification(NotificationVerifier::unverified($body, ['X-Notification-ID' => 'NOTIF-3']));
    expect($counts[$event])->toBe(2);
})->with('store and lock')->with([
    'refund' => ['REFUND', RefundProcessed::class],
    'void' => ['VOID_PAYMENT', VoidProcessed::class],
]);

it('gives a money event the same deliveryKey from every trigger, and another key for another event', function (string $store) {
    useRealQueue($store);
    bindPayableFor($store, hostPayable());
    fakeGatewayResponses(array_fill(0, 3, GatewayHarness::json(200, orderJson())));
    $seen = collectEvents(PaymentCaptured::class);
    $broken = new ArrayObject(['on' => true]);
    listenerThrowsWhile(PaymentCaptured::class, $broken);

    deliver(notificationBody())->assertOk();
    runWorker();
    waitForRelease();
    runWorker();
    $broken['on'] = false;
    Artisan::call('mib:reconcile');

    expect($seen)->toHaveCount(3)
        ->and($seen[0]->source)->toBe(Source::Webhook)
        ->and($seen[2]->source)->toBe(Source::Reconcile)
        ->and($seen[1]->deliveryKey)->toBe($seen[0]->deliveryKey)
        ->and($seen[2]->deliveryKey)->toBe($seen[0]->deliveryKey)
        ->and($seen[0]->deliveryKey)->toMatch('/^[0-9a-f]{64}$/');
})->with('store and lock');

it('gives refunds and voids their own deliveryKey per transaction', function () {
    useRealQueue();
    bindResolver();
    fakeGatewayResponses([]);
    $refunds = collectEvents(RefundProcessed::class);
    $voids = collectEvents(VoidProcessed::class);

    deliver(notificationBody('REFUND', txnId: 'R1'), 'NOTIF-1')->assertOk();
    deliver(notificationBody('REFUND', txnId: 'R2'), 'NOTIF-2')->assertOk();
    deliver(notificationBody('VOID_PAYMENT', txnId: 'R1'), 'NOTIF-3')->assertOk();
    runWorker();
    runWorker();
    runWorker();

    expect([$refunds[0]->deliveryKey, $refunds[1]->deliveryKey, $voids[0]->deliveryKey])->each->toMatch('/^[0-9a-f]{64}$/')
        ->and(array_unique([$refunds[0]->deliveryKey, $refunds[1]->deliveryKey, $voids[0]->deliveryKey]))->toHaveCount(3);
});

it('falls back to what the gateway reports when a money event has no transaction ID', function (string $store, string $event, array $first, array $changed) {
    useRealQueue($store);
    bindPayableFor($store, hostPayable());
    fakeGatewayResponses([...array_fill(0, 3, GatewayHarness::json(200, $first)), GatewayHarness::json(200, $changed)]);
    $seen = collectEvents($event);
    listenerThrowsOnce($event);

    // Reconcile has no notification to dedupe on, and it still stops once a dispatch completes.
    expect(Artisan::call('mib:reconcile'))->toBe(1)->and($seen)->toHaveCount(1);
    expect(Artisan::call('mib:reconcile'))->toBe(0)->and($seen)->toHaveCount(2);
    expect(Artisan::call('mib:reconcile'))->toBe(0)->and($seen)->toHaveCount(2);

    expect($seen[0]->verification->transactionId())->toBeNull()
        ->and($seen[1]->deliveryKey)->toBe($seen[0]->deliveryKey);

    // New totals are a new event.
    expect(Artisan::call('mib:reconcile'))->toBe(0)->and($seen)->toHaveCount(3)
        ->and($seen[2]->deliveryKey)->not->toBe($seen[0]->deliveryKey);
})->with('store and lock')->with([
    'CaptureMismatch' => [CaptureMismatch::class, orderWithoutTransactionId(captured: 1000.0), orderWithoutTransactionId(captured: 900.0)],
    'PaymentReversed' => [PaymentReversed::class, orderWithoutTransactionId(extra: ['totalRefundedAmount' => 1250.5]), orderWithoutTransactionId(captured: 1000.0, extra: ['totalRefundedAmount' => 1000.0])],
]);

it('falls back to the notification when a refund has no transaction ID', function (string $store) {
    useRealQueue($store);
    bindResolver();
    fakeGatewayResponses([]);
    $seen = collectEvents(RefundProcessed::class);
    listenerThrowsOnce(RefundProcessed::class);

    $body = json_decode(notificationBody('REFUND'), true);
    unset($body['transaction']['id']);
    $body = (string) json_encode($body);

    deliver($body, 'NOTIF-1')->assertOk();
    runWorker();
    waitForRelease();
    runWorker();
    expect($seen)->toHaveCount(2)
        ->and($seen[1]->deliveryKey)->toBe($seen[0]->deliveryKey)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Processed);

    // Two partial refunds can look identical, so another notification is another event.
    deliver($body, 'NOTIF-2')->assertOk();
    runWorker();
    expect($seen)->toHaveCount(3)->and($seen[2]->deliveryKey)->not->toBe($seen[0]->deliveryKey);
})->with('store and lock');

it('does not reverse twice when a dispatched-again CaptureMismatch queues a second ReverseCapture', function (string $store, array $responses, int $reversalJobs) {
    useRealQueue($store);
    config()->set('mib-global-pay.double_capture.auto_reverse', true);
    bindPayableFor($store, hostPayable(settlement: settlementBy('ANOTHER-ORDER')));
    $harness = fakeGatewayResponses($responses);
    $counts = countEvents();
    listenerThrowsOnce(CaptureMismatch::class);
    $ran = 0;
    Queue::before(function (JobProcessing $e) use (&$ran): void {
        $ran += $e->job->resolveName() === ReverseCapture::class ? 1 : 0;
    });

    deliver(notificationBody())->assertOk();
    runWorker();

    while (DB::table('jobs')->count() > 0) {
        if ((int) DB::table('jobs')->min('available_at') > Carbon::now()->getTimestamp()) {
            Carbon::setTestNow(Carbon::createFromTimestamp((int) DB::table('jobs')->min('available_at')));
        }
        runWorker();
    }

    $puts = array_values(array_filter($harness->history, fn (array $h): bool => $h['request']->getMethod() === 'PUT'));

    expect($counts[CaptureMismatch::class])->toBe(2)
        ->and($ran)->toBe($reversalJobs)
        ->and($puts)->toHaveCount(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Processed);
})->with([
    // No rollback: the first job was queued before the failure, so two jobs run.
    'cache store, plain lock' => ['cache', fn () => [
        GatewayHarness::json(200, orderJson()),
        GatewayHarness::json(200, orderJson()),
        GatewayHarness::json(200, approvedVoidPayload()),
        GatewayHarness::json(200, orderJson()),
        GatewayHarness::json(200, orderWithApprovedVoid()),
    ], 2],
    // The rollback discards the first job, since it is queued after commit.
    'database store, DB::transaction lock' => ['database', fn () => [
        GatewayHarness::json(200, orderJson()),
        GatewayHarness::json(200, orderJson()),
        GatewayHarness::json(200, orderJson()),
        GatewayHarness::json(200, approvedVoidPayload()),
    ], 1],
]);

it('sends a money event again on the sync queue when the bank redelivers after a listener failure', function () {
    config()->set('logging.default', 'null');
    bindResolver(hostPayable());
    fakeGatewayResponses([GatewayHarness::json(200, orderJson()), GatewayHarness::json(200, orderJson())]);
    $counts = countEvents();
    listenerThrowsOnce(PaymentCaptured::class);

    deliver(notificationBody())->assertStatus(503);
    deliver(notificationBody())->assertOk()->assertJson(['status' => 'accepted']);

    expect($counts[PaymentCaptured::class])->toBe(2)
        ->and($counts[NotificationReceived::class])->toBe(1)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Processed);
});

it('on the sync queue answers 503 and lets the bank redeliver, without repeating events', function () {
    config()->set('logging.default', 'null');
    bindResolver(hostPayable());
    fakeGatewayResponses([gatewayTimeout(), GatewayHarness::json(200, orderJson())]);
    $counts = countEvents();

    deliver(notificationBody())->assertStatus(503);
    deliver(notificationBody())->assertOk()->assertJson(['status' => 'accepted']);

    expect($counts[NotificationReceived::class])->toBe(1)
        ->and($counts[PaymentCaptured::class])->toBe(1)
        ->and(outcomeOfNotification())->toBe(NotificationOutcome::Processed);
});
