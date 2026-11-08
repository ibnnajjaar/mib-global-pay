<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Database\Schema\Blueprint;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use IbnNajjaar\MIBGlobalPay\Core\Reversal\ReversalIds;
use IbnNajjaar\MIBGlobalPay\Tests\Support\ArrayLogger;
use IbnNajjaar\MIBGlobalPay\Laravel\Jobs\ReverseCapture;
use IbnNajjaar\MIBGlobalPay\Tests\Support\GatewayHarness;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\ReversalFailed;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\ReversalUnconfirmed;

/**
 * Goes through a real database queue and `queue:work --once --tries=1`, the worker default, so the
 * retry settings under test can only come from the job itself.
 */
function reversalQueue(): ArrayLogger
{
    Carbon::setTestNow('2026-11-01 12:00:00');

    config()->set('database.default', 'testing');
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

    $logger = new ArrayLogger();
    app()->instance(LoggerInterface::class, $logger);

    return $logger;
}

/**
 * Runs the worker until the job is gone or the attempts are used up.
 *
 * @return list<int> the delay given to each release
 */
function runReversalJob(int $maxAttempts = 10): array
{
    $delays = [];

    for ($i = 0; $i < $maxAttempts && DB::table('jobs')->exists(); $i++) {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);

        $job = DB::table('jobs')->first();
        if ($job !== null) {
            $delay = (int) $job->available_at - Carbon::now()->getTimestamp();
            $delays[] = $delay;
            Carbon::setTestNow(Carbon::now()->addSeconds($delay));
        }
    }

    return $delays;
}

function pendingVoidPayload(): array
{
    return ['result' => 'PENDING', 'response' => ['gatewayCode' => 'PENDING'], 'transaction' => ['id' => ReversalIds::derive(LARAVEL_ORDER, '1')->void, 'type' => 'VOID_PAYMENT', 'targetTransactionId' => '1']];
}

/**
 * The job's own error logs; the worker also reports each exception through the same logger.
 *
 * @return list<array{level: string, message: string, context: array<string, mixed>}>
 */
function errorLogs(ArrayLogger $logger): array
{
    return array_values(array_filter($logger->records, fn (array $r): bool => $r['level'] === 'error' && str_starts_with($r['message'], 'MIB double capture')));
}

it('retries an unconfirmed void by itself with backoff, then escalates once with the IDs, and never refunds', function () {
    $logger = reversalQueue();
    // Later reads list the pending void.
    $order = orderJson();
    $order['transaction'][] = pendingVoidPayload();
    $harness = fakeGatewayResponses([
        GatewayHarness::json(200, orderJson()),
        GatewayHarness::json(200, pendingVoidPayload()),
        ...array_fill(0, 5, GatewayHarness::json(200, $order)),
    ]);
    Event::fake([ReversalUnconfirmed::class]);

    dispatch(new ReverseCapture(LARAVEL_ORDER));
    $delays = runReversalJob();

    $ids = ReversalIds::derive(LARAVEL_ORDER, '1');

    expect($delays)->toBe([60, 300, 600, 900, 900])
        ->and($harness->history)->toHaveCount(7)
        ->and(array_filter(array_map(fn (array $h): string => $h['request']->getMethod(), $harness->history), fn (string $m): bool => $m === 'PUT'))->toHaveCount(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(errorLogs($logger))->toHaveCount(1)
        ->and(errorLogs($logger)[0]['context'])->toMatchArray(['order' => LARAVEL_ORDER, 'method' => 'unconfirmed', 'reversed' => false, 'target_transaction' => '1', 'void_transaction' => $ids->void, 'refund_transaction' => $ids->refund]);

    Event::assertDispatchedTimes(ReversalUnconfirmed::class, 1);
    Event::assertDispatched(ReversalUnconfirmed::class, fn (ReversalUnconfirmed $e): bool => $e->gatewayOrderId === LARAVEL_ORDER
        && $e->ids == $ids
        && $e->transaction?->transactionId === $ids->void
        && $e->error === null);
});

it('stops retrying without escalation once the unconfirmed void is approved', function () {
    $logger = reversalQueue();
    $ids = ReversalIds::derive(LARAVEL_ORDER, '1');
    $order = orderJson();
    $order['transaction'][] = ['result' => 'SUCCESS', 'response' => ['gatewayCode' => 'APPROVED'], 'transaction' => ['id' => $ids->void, 'type' => 'VOID_PAYMENT', 'targetTransactionId' => '1']];
    $harness = fakeGatewayResponses([
        GatewayHarness::json(200, orderJson()),
        GatewayHarness::json(200, pendingVoidPayload()),
        GatewayHarness::json(200, $order),
    ]);
    Event::fake([ReversalUnconfirmed::class]);

    dispatch(new ReverseCapture(LARAVEL_ORDER));
    $delays = runReversalJob();

    expect($delays)->toBe([60])
        ->and($harness->history)->toHaveCount(3)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(errorLogs($logger))->toBe([]);

    Event::assertNotDispatched(ReversalUnconfirmed::class);
});

it('escalates through failed() when every attempt throws, keeping the failed job', function () {
    $logger = reversalQueue();
    $timeout = fn () => new ConnectException('timed out', new Psr7Request('GET', 'https://example.test'));
    $harness = fakeGatewayResponses(array_map(fn () => $timeout(), range(1, 6)));
    Event::fake([ReversalUnconfirmed::class]);

    dispatch(new ReverseCapture(LARAVEL_ORDER));
    $delays = runReversalJob();

    expect($delays)->toBe([60, 300, 600, 900, 900])
        ->and($harness->history)->toHaveCount(6)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(errorLogs($logger))->toHaveCount(1);

    Event::assertDispatchedTimes(ReversalUnconfirmed::class, 1);
    Event::assertDispatched(ReversalUnconfirmed::class, fn (ReversalUnconfirmed $e): bool => $e->gatewayOrderId === LARAVEL_ORDER
        && $e->ids === null
        && str_contains((string) $e->error, 'timed out'));
});

it('sends no refund and retries, then escalates with the reason, when a wrong host answers the void read', function () {
    $logger = reversalQueue();
    // Each attempt reads the order, gets a gateway 400 on the void, then a 404 page for the void read.
    $responses = [];
    foreach (range(1, 6) as $attempt) {
        array_push(
            $responses,
            GatewayHarness::json(200, orderJson()),
            GatewayHarness::json(400, GatewayHarness::errorBody(['explanation' => 'Void not allowed'])),
            GatewayHarness::json(404, '<html>Not Found</html>'),
        );
    }
    $harness = fakeGatewayResponses($responses);
    Event::fake([ReversalUnconfirmed::class, ReversalFailed::class]);

    dispatch(new ReverseCapture(LARAVEL_ORDER));
    runReversalJob();

    $refunds = array_filter($harness->history, fn (array $entry): bool => str_contains((string) $entry['request']->getBody(), 'REFUND'));

    expect($refunds)->toBe([])
        ->and($harness->history)->toHaveCount(18)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(errorLogs($logger))->toHaveCount(1);

    Event::assertDispatchedTimes(ReversalUnconfirmed::class, 1);
    Event::assertDispatched(ReversalUnconfirmed::class, fn (ReversalUnconfirmed $e): bool => str_contains((string) $e->error, 'not recognised as a gateway error'));
    Event::assertNotDispatched(ReversalFailed::class);
});

it('escalates a definitely failed refund at once, as failed and not unconfirmed, without retrying', function () {
    $logger = reversalQueue();
    $harness = fakeGatewayResponses([
        GatewayHarness::json(200, orderJson()),
        GatewayHarness::json(200, ['result' => 'FAILURE', 'response' => ['gatewayCode' => 'DECLINED'], 'transaction' => ['type' => 'VOID_PAYMENT']]),
        GatewayHarness::json(200, ['result' => 'FAILURE', 'response' => ['gatewayCode' => 'DECLINED'], 'transaction' => ['id' => 'x', 'type' => 'REFUND']]),
    ]);
    Event::fake([ReversalUnconfirmed::class, ReversalFailed::class]);

    dispatch(new ReverseCapture(LARAVEL_ORDER));
    $delays = runReversalJob();

    expect($delays)->toBe([])
        ->and($harness->history)->toHaveCount(3)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(errorLogs($logger))->toHaveCount(1);

    Event::assertNotDispatched(ReversalUnconfirmed::class);
    Event::assertDispatched(ReversalFailed::class, fn (ReversalFailed $e): bool => $e->gatewayOrderId === LARAVEL_ORDER
        && $e->ids == ReversalIds::derive(LARAVEL_ORDER, '1'));
});

it('gives a second job for the same capture the same IDs', function () {
    $harness = fakeGatewayResponses([
        GatewayHarness::json(200, orderJson()),
        GatewayHarness::json(200, pendingVoidPayload()),
        GatewayHarness::json(200, orderJson()),
        GatewayHarness::json(200, pendingVoidPayload()),
    ]);
    Event::fake([ReversalUnconfirmed::class]);

    app()->call([new ReverseCapture(LARAVEL_ORDER)->withFakeQueueInteractions(), 'handle']);
    app()->call([new ReverseCapture(LARAVEL_ORDER)->withFakeQueueInteractions(), 'handle']);

    expect($harness->request(3)->getUri()->getPath())->toBe($harness->request(1)->getUri()->getPath())
        ->and($harness->requestBody(3))->toBe($harness->requestBody(1));
});

it('escalates at once where a release would be lost: the sync queue and a direct call', function (string $how) {
    fakeGatewayResponses([
        GatewayHarness::json(200, orderJson()),
        GatewayHarness::json(200, pendingVoidPayload()),
    ]);
    Event::fake([ReversalUnconfirmed::class]);

    if ($how === 'sync') {
        dispatch_sync(new ReverseCapture(LARAVEL_ORDER));
    } else {
        app()->call([new ReverseCapture(LARAVEL_ORDER), 'handle']);
    }

    Event::assertDispatchedTimes(ReversalUnconfirmed::class, 1);
})->with(['sync', 'direct']);
