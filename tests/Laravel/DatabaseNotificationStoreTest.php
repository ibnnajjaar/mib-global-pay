<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Payable;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\Source;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionRolledBack;
use IbnNajjaar\MIBGlobalPay\Laravel\Facades\MibGlobalPay;
use IbnNajjaar\MIBGlobalPay\Tests\Support\GatewayHarness;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\ResolvedAttempt;
use IbnNajjaar\MIBGlobalPay\Core\Webhook\NotificationStore;
use IbnNajjaar\MIBGlobalPay\Laravel\Events\PaymentCaptured;
use Illuminate\Database\UniqueConstraintViolationException;
use IbnNajjaar\MIBGlobalPay\Laravel\Outcome\OutcomeProcessor;
use IbnNajjaar\MIBGlobalPay\Laravel\Stores\CacheNotificationStore;
use IbnNajjaar\MIBGlobalPay\Laravel\Stores\DatabaseNotificationStore;

/*
 * The store on real databases. Everything here runs on SQLite with no setup. MySQL and PostgreSQL
 * tests skip unless these point at a THROWAWAY database (tests drop and recreate their tables):
 *
 *   MIB_TEST_MYSQL_DSN=mysql://root:test@127.0.0.1:53306/test
 *   MIB_TEST_PGSQL_DSN=pgsql://postgres:test@127.0.0.1:55432/test
 *
 * PostgreSQL is the one that matters for host transactions: a failed statement aborts the whole
 * transaction there, unless it ran inside a savepoint.
 */

const STORE_TEST_CONNECTION = 'mib_store_test';
const STORE_TEST_HOST_TABLE = 'mib_test_host_writes';

function storeTestSqliteFile(): string
{
    return sys_get_temp_dir() . '/mib-store-test-' . getmypid() . '.sqlite';
}

/** @return array<string, mixed> connection config; skips the test when the server isn't configured */
function storeTestConfig(string $driver): array
{
    if ($driver === 'sqlite') {
        return ['driver' => 'sqlite', 'database' => storeTestSqliteFile(), 'prefix' => ''];
    }

    $variable = $driver === 'mysql' ? 'MIB_TEST_MYSQL_DSN' : 'MIB_TEST_PGSQL_DSN';
    $url = getenv($variable);

    if (! is_string($url) || $url === '') {
        test()->markTestSkipped("Set {$variable} to run this against a real server.");
    }

    return $driver === 'mysql'
        ? ['driver' => 'mysql', 'url' => $url, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true]
        : ['driver' => 'pgsql', 'url' => $url, 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public'];
}

function storeOn(string $driver): DatabaseNotificationStore
{
    $config = storeTestConfig($driver);

    if ($driver === 'sqlite') {
        touch(storeTestSqliteFile());
    }

    config()->set('database.connections.' . STORE_TEST_CONNECTION, $config);
    config()->set('database.default', STORE_TEST_CONNECTION);
    dropStoreTestTables();

    $migration = require __DIR__ . '/../../database/migrations/2026_10_28_000000_create_mib_global_pay_notifications_table.php';
    $migration->up();

    Schema::create(STORE_TEST_HOST_TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('key_name', 50);
        $table->string('stage', 20)->default('');
    });

    return new DatabaseNotificationStore(DB::connection());
}

function dropStoreTestTables(): void
{
    Schema::dropIfExists(STORE_TEST_HOST_TABLE);
    Schema::dropIfExists(DatabaseNotificationStore::TABLE);
}

function hostWrite(string $key): void
{
    DB::table(STORE_TEST_HOST_TABLE)->insert(['key_name' => $key]);
}

/** @return list<mixed> */
function hostWrites(): array
{
    return DB::table(STORE_TEST_HOST_TABLE)->orderBy('id')->pluck('key_name')->all();
}

function storeRows(): int
{
    return DB::table(DatabaseNotificationStore::TABLE)->count();
}

/** The error a call raised, for checks a plain toThrow() can't express. */
function errorFrom(Closure $call): ?Throwable
{
    try {
        $call();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

/** Replace the table with one the store's inserts can't satisfy: a required column it never sets. */
function requireAColumnTheStoreNeverSets(): void
{
    Schema::dropIfExists(DatabaseNotificationStore::TABLE);
    Schema::create(DatabaseNotificationStore::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('notification_id', 191)->nullable()->unique();
        $table->string('order_id', 40)->nullable();
        $table->string('transaction_id', 40)->nullable();
        $table->string('outcome', 40)->nullable();
        $table->string('status', 20)->nullable();
        $table->string('required_by_this_test', 20);
        $table->timestamps();
    });
}

/**
 * Race separate PHP processes over the same keys.
 *
 * @return array<int, list<string>> what each worker was told, per key
 */
function raceForClaims(string $driver, string $mode, string $kind, int $workers = 6, int $keys = 20): array
{
    $startAt = microtime(true) + 0.6;
    $processes = [];

    foreach (range(0, $workers - 1) as $worker) {
        $process = new Process([
            PHP_BINARY, '-d', 'xdebug.mode=off', __DIR__ . '/Support/claim-race-worker.php',
            json_encode(storeTestConfig($driver), JSON_THROW_ON_ERROR),
            $mode, $kind, (string) $worker, (string) $keys, (string) $startAt, '0.01',
        ], timeout: 120);
        $process->start();
        $processes[] = $process;
    }

    $outcomes = [];

    foreach ($processes as $process) {
        $process->wait();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput() . $process->getOutput());

        foreach (json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR) as $key => $outcome) {
            $outcomes[$key][] = $outcome;
        }
    }

    return $outcomes;
}

dataset('store databases', ['sqlite', 'mysql', 'pgsql']);
dataset('claim kinds', ['claim', 'claimTransaction']);

afterEach(function () {
    if (config('database.default') === STORE_TEST_CONNECTION) {
        // A fresh connection, in case a failed test left a transaction open.
        DB::purge(STORE_TEST_CONNECTION);
        dropStoreTestTables();
        DB::purge(STORE_TEST_CONNECTION);
    }

    if (is_file(storeTestSqliteFile())) {
        unlink(storeTestSqliteFile());
    }
});

it('returns false for a duplicate without raising an error, outside a transaction', function (string $driver) {
    $store = storeOn($driver);

    expect($store->claim('N1', 'O-1'))->toBeTrue()
        ->and($store->claim('N1', 'O-1'))->toBeFalse()
        ->and($store->claimTransaction('O-1', 'T-1', 'payment_captured'))->toBeTrue()
        ->and($store->claimTransaction('O-1', 'T-1', 'payment_captured'))->toBeFalse()
        ->and(storeRows())->toBe(2);
})->with('store databases');

it('keeps the host transaction usable after a duplicate, and its writes commit', function (string $driver) {
    $store = storeOn($driver);
    $store->claim('N1', 'O-1');
    $store->claimTransaction('O-1', 'T-1', 'payment_captured');

    DB::beginTransaction();
    hostWrite('before');

    expect($store->claim('N1', 'O-1'))->toBeFalse();
    hostWrite('after-claim');

    expect($store->claimTransaction('O-1', 'T-1', 'payment_captured'))->toBeFalse();
    hostWrite('after-claim-transaction');

    expect($store->claim('N2', 'O-1'))->toBeTrue()
        ->and($store->claimTransaction('O-1', 'T-2', 'payment_captured'))->toBeTrue()
        ->and(DB::transactionLevel())->toBe(1);

    DB::commit();

    expect(hostWrites())->toBe(['before', 'after-claim', 'after-claim-transaction'])
        ->and(storeRows())->toBe(4)
        ->and($store->claim('N2'))->toBeFalse()
        ->and($store->claimTransaction('O-1', 'T-2', 'payment_captured'))->toBeFalse();
})->with('store databases');

it('leaves DB::transaction working, including a later afterCommit callback', function (string $driver) {
    $store = storeOn($driver);
    $store->claim('N1');
    $committed = 0;

    DB::afterCommit(function () use (&$committed): void {
        $committed++;
    });

    DB::transaction(function () use ($store, &$committed): void {
        DB::afterCommit(function () use (&$committed): void {
            $committed += 10;
        });

        expect($store->claim('N1'))->toBeFalse();
        hostWrite('in-transaction');

        DB::afterCommit(function () use (&$committed): void {
            $committed += 100;
        });

        expect($committed)->toBe(1);
    });

    expect($committed)->toBe(111)->and(hostWrites())->toBe(['in-transaction']);
})->with('store databases');

it('lets a claim roll back with the host transaction', function (string $driver) {
    $store = storeOn($driver);

    DB::beginTransaction();
    expect($store->claim('N1'))->toBeTrue()
        ->and($store->claim('N1'))->toBeFalse()
        ->and($store->claimTransaction('O-1', 'T-1', 'payment_captured'))->toBeTrue();
    DB::rollBack();

    expect(storeRows())->toBe(0)
        ->and($store->claim('N1'))->toBeTrue()
        ->and($store->claimTransaction('O-1', 'T-1', 'payment_captured'))->toBeTrue();
})->with('store databases');

it('reports a duplicate committed after the snapshot of a repeatable read transaction', function (string $driver) {
    $store = storeOn($driver);
    config()->set('database.connections.mib_store_test_other', storeTestConfig($driver));
    $isolate = "set transaction isolation level repeatable read";

    if ($driver === 'mysql') {
        DB::statement($isolate);
    }

    DB::beginTransaction();

    if ($driver === 'pgsql') {
        DB::statement($isolate);
    }

    expect(storeRows())->toBe(0);
    new DatabaseNotificationStore(DB::connection('mib_store_test_other'))->claim('N1');
    DB::purge('mib_store_test_other');

    expect($store->claim('N1'))->toBeFalse();
    hostWrite('still-usable');
    DB::commit();

    expect(hostWrites())->toBe(['still-usable']);
})->with(['mysql', 'pgsql']);

// Pins the mechanism where the database itself can't show the bug: on SQLite and MySQL a failed
// insert doesn't abort the transaction, so this is what keeps a no-server run honest.
it('isolates the insert in a savepoint only when a transaction is open', function (string $driver) {
    $store = storeOn($driver);
    $began = 0;
    $rolledBack = 0;
    Event::listen(TransactionBeginning::class, function () use (&$began): void {
        $began++;
    });
    Event::listen(TransactionRolledBack::class, function () use (&$rolledBack): void {
        $rolledBack++;
    });

    $store->claim('N1');
    expect($store->claim('N1'))->toBeFalse()->and($began)->toBe(0)->and($rolledBack)->toBe(0);

    DB::beginTransaction();
    expect($began)->toBe(1)->and($store->claim('N1'))->toBeFalse()->and($began)->toBe(2)->and($rolledBack)->toBe(1);
    DB::commit();
})->with('store databases');

it('does not confuse rows with empty or null columns in the unique indexes', function (string $driver) {
    $store = storeOn($driver);

    // claim() rows leave transaction_id and outcome null: NULLs never conflict in (order, transaction, outcome).
    expect($store->claim('N1', 'O-1'))->toBeTrue()
        ->and($store->claim('N2', 'O-1'))->toBeTrue()
        ->and($store->claim('N3'))->toBeTrue()
        ->and($store->claim('N4'))->toBeTrue();

    // claimTransaction() rows leave notification_id null, which must not conflict either.
    expect($store->claimTransaction('O-1', 'T-1', 'payment_captured'))->toBeTrue()
        ->and($store->claimTransaction('O-1', 'T-2', 'payment_captured'))->toBeTrue();

    // OutcomeProcessor::claimForNotification: an empty order ID and a sha1 transaction ID. Empty is a
    // value, not a NULL, so these do dedupe, and they use all 40 characters of transaction_id.
    $first = sha1('notification-1');
    $second = sha1('notification-2');

    DB::beginTransaction();
    expect($store->claimTransaction('', $first, 'notification_received'))->toBeTrue()
        ->and($store->claimTransaction('', $first, 'notification_received'))->toBeFalse()
        ->and($store->claimTransaction('', $first, 'unknown_order'))->toBeTrue()
        ->and($store->claimTransaction('', $second, 'notification_received'))->toBeTrue();
    DB::commit();

    expect($store->claimTransaction('', $first, 'notification_received'))->toBeFalse()
        ->and(storeRows())->toBe(9);
})->with('store databases');

it('never reports two different over-long IDs as a duplicate', function (string $driver) {
    $store = storeOn($driver);
    $long = str_repeat('x', 40);

    $claimBoth = function (string $first, string $second) use ($store, $driver): void {
        foreach ([$first, $second] as $orderId) {
            $outcome = null;
            $error = errorFrom(function () use ($store, $orderId, &$outcome): void {
                $outcome = $store->claimTransaction($orderId, 'T-1', 'payment_captured');
            });

            // SQLite ignores varchar lengths, so there the IDs stay whole and different.
            if ($driver === 'sqlite') {
                expect($error)->toBeNull()->and($outcome)->toBeTrue();
            } else {
                expect($error)->toBeInstanceOf(QueryException::class)
                    ->not->toBeInstanceOf(UniqueConstraintViolationException::class);
            }
        }
    };

    $claimBoth("{$long}a", "{$long}b");

    DB::beginTransaction();
    $claimBoth("{$long}c", "{$long}d");
    hostWrite('still-usable');
    DB::commit();

    expect(hostWrites())->toBe(['still-usable'])->and(storeRows())->toBe($driver === 'sqlite' ? 4 : 0);
})->with('store databases');

it('surfaces a NOT NULL failure instead of calling it a duplicate', function (string $driver) {
    $store = storeOn($driver);
    requireAColumnTheStoreNeverSets();

    $outside = errorFrom(fn () => $store->claim('N1'));

    DB::beginTransaction();
    $inside = errorFrom(fn () => $store->claimTransaction('O-1', 'T-1', 'payment_captured'));
    hostWrite('still-usable');
    DB::commit();

    expect($outside)->toBeInstanceOf(QueryException::class)->not->toBeInstanceOf(UniqueConstraintViolationException::class)
        ->and($inside)->toBeInstanceOf(QueryException::class)->not->toBeInstanceOf(UniqueConstraintViolationException::class)
        ->and(hostWrites())->toBe(['still-usable'])
        ->and(storeRows())->toBe(0);
})->with('store databases');

it('surfaces a missing table instead of calling it a duplicate', function (string $driver) {
    $store = storeOn($driver);
    Schema::dropIfExists(DatabaseNotificationStore::TABLE);

    expect(errorFrom(fn () => $store->claim('N1')))->toBeInstanceOf(QueryException::class)
        ->not->toBeInstanceOf(UniqueConstraintViolationException::class);
})->with('store databases');

it('gives each key to exactly one racing process', function (string $driver, string $kind) {
    storeOn($driver);

    $outcomes = raceForClaims($driver, 'plain', $kind);

    expect($outcomes)->toHaveCount(20);

    foreach ($outcomes as $key => $results) {
        sort($results);
        expect($results)->toBe(['lost', 'lost', 'lost', 'lost', 'lost', 'won'], "key {$key}");
    }

    expect(storeRows())->toBe(20);
})->with('store databases')->with('claim kinds');

it('gives each key to exactly one racing transaction, and every loser still commits', function (string $driver, string $kind) {
    storeOn($driver);

    $outcomes = raceForClaims($driver, 'transaction', $kind);

    expect($outcomes)->toHaveCount(20);

    foreach ($outcomes as $key => $results) {
        sort($results);
        expect($results)->toBe(['lost', 'lost', 'lost', 'lost', 'lost', 'won'], "key {$key}");
    }

    // Two host writes per worker per key, before and after the claim, all committed.
    expect(storeRows())->toBe(20)->and(count(hostWrites()))->toBe(6 * 20 * 2);
})->with('store databases')->with('claim kinds');

it('only reports a duplicate when a claim really committed, even if racing winners roll back', function (string $driver, string $kind) {
    storeOn($driver);

    $outcomes = raceForClaims($driver, 'rollback', $kind);
    $committedWinners = 0;
    $committedLosers = 0;

    foreach ($outcomes as $key => $results) {
        $counts = array_count_values(array_map(fn (string $r) => str_starts_with($r, 'error') ? 'error' : $r, $results));
        $won = $counts['won'] ?? 0;
        $lost = $counts['lost'] ?? 0;

        expect($won)->toBeLessThanOrEqual(1, "key {$key}");

        // A loser was told false, so a row must exist that survived.
        if ($lost > 0) {
            expect($won)->toBe(1, "key {$key}: false without a committed claim");
        }

        // The only acceptable error is MySQL's deadlock when a rolled-back winner frees waiting duplicates.
        foreach ($results as $result) {
            if (str_starts_with($result, 'error')) {
                expect($driver)->toBe('mysql', $result)->and($result)->toContain('Deadlock');
            }
        }

        $committedWinners += $won;
        $committedLosers += $lost;
    }

    expect(storeRows())->toBe($committedWinners)
        ->and(count(hostWrites()))->toBe(($committedWinners + $committedLosers) * 2);
})->with('store databases')->with('claim kinds');

it('records a delivery idempotently, and a duplicate keeps the host transaction usable', function (string $driver) {
    $store = storeOn($driver);

    expect($store->wasDelivered('O-1', 'T-1', 'payment_captured'))->toBeFalse();

    $store->markDelivered('O-1', 'T-1', 'payment_captured');
    $store->markDelivered('O-1', 'T-1', 'payment_captured');

    expect($store->wasDelivered('O-1', 'T-1', 'payment_captured'))->toBeTrue()
        ->and($store->wasDelivered('O-1', 'T-1', 'double_capture'))->toBeFalse()
        ->and($store->wasDelivered('O-1', 'T-2', 'payment_captured'))->toBeFalse()
        ->and($store->wasDelivered('O-2', 'T-1', 'payment_captured'))->toBeFalse()
        ->and(storeRows())->toBe(1);

    DB::beginTransaction();
    hostWrite('before');
    $store->markDelivered('O-1', 'T-1', 'payment_captured');
    hostWrite('after');
    DB::commit();

    expect(hostWrites())->toBe(['before', 'after'])->and(storeRows())->toBe(1);
})->with('store databases');

it('lets a delivery record roll back with the host transaction', function (string $driver) {
    $store = storeOn($driver);

    DB::beginTransaction();
    $store->markDelivered('O-1', 'T-1', 'payment_captured');
    expect($store->wasDelivered('O-1', 'T-1', 'payment_captured'))->toBeTrue();
    DB::rollBack();

    expect($store->wasDelivered('O-1', 'T-1', 'payment_captured'))->toBeFalse();
})->with('store databases');

it('records a delivery committed after the snapshot of a repeatable read transaction, without an error', function (string $driver) {
    $store = storeOn($driver);
    config()->set('database.connections.mib_store_test_other', storeTestConfig($driver));
    $isolate = 'set transaction isolation level repeatable read';

    if ($driver === 'mysql') {
        DB::statement($isolate);
    }

    DB::beginTransaction();

    if ($driver === 'pgsql') {
        DB::statement($isolate);
    }

    // This snapshot cannot see the other worker's record, so the event goes out again here.
    expect(storeRows())->toBe(0);
    new DatabaseNotificationStore(DB::connection('mib_store_test_other'))->markDelivered('O-1', 'T-1', 'payment_captured');
    DB::purge('mib_store_test_other');
    expect($store->wasDelivered('O-1', 'T-1', 'payment_captured'))->toBeFalse();

    $store->markDelivered('O-1', 'T-1', 'payment_captured');
    hostWrite('still-usable');
    DB::commit();

    expect(hostWrites())->toBe(['still-usable'])
        ->and($store->wasDelivered('O-1', 'T-1', 'payment_captured'))->toBeTrue();
})->with(['mysql', 'pgsql']);

it('records a delivery once when processes race, with no errors', function (string $driver, string $mode) {
    storeOn($driver);

    $outcomes = raceForClaims($driver, $mode, 'markDelivered');

    expect($outcomes)->toHaveCount(20);

    foreach ($outcomes as $key => $results) {
        expect($results)->toBe(array_fill(0, 6, 'won'), "key {$key}");
    }

    expect(storeRows())->toBe(20);
})->with('store databases')->with(['plain', 'transaction']);

/**
 * A captured attempt and a pipeline on the given store. The host's listener writes to the database,
 * inside the transactional lock README documents. Returns the dispatch count.
 *
 * @return array{0: Payable, 1: ArrayObject<string, int>}
 */
function capturedPipeline(NotificationStore $store): array
{
    app()->instance(NotificationStore::class, $store);
    bindResolver();
    fakeGatewayResponses(array_fill(0, 6, GatewayHarness::json(200, orderJson())));

    $count = new ArrayObject(['dispatched' => 0]);
    Event::listen(PaymentCaptured::class, function () use ($count): void {
        $count['dispatched']++;
        hostWrite('paid');
    });

    return [transactionalPayable(hostPayable()), $count];
}

dataset('store kinds', [
    'database store' => ['database'],
    'cache store' => ['cache'],
]);

/** $database is only here so storeOn() has built the tables and connection first. */
function storeUnderTest(string $kind, DatabaseNotificationStore $database): NotificationStore
{
    return $kind === 'database'
        ? new DatabaseNotificationStore(DB::connection(), app('db.transactions'))
        : new CacheNotificationStore(app('cache')->store(), app('db.transactions'));
}

it('leaves nothing behind when a listener fails inside the transactional lock', function (string $driver, string $kind) {
    $store = storeUnderTest($kind, storeOn($driver));
    [$payable, $count] = capturedPipeline($store);

    Event::listen(PaymentCaptured::class, function () use ($count): void {
        if ($count['dispatched'] === 1) {
            throw new RuntimeException('listener failed');
        }
    });

    $resolved = new ResolvedAttempt($payable, $payable->attempts()[0]);

    expect(errorFrom(fn () => app(OutcomeProcessor::class)->process($resolved, Source::Webhook)))->toBeInstanceOf(RuntimeException::class)
        ->and(hostWrites())->toBe([])
        ->and(DB::transactionLevel())->toBe(0)
        ->and($store->wasDelivered(LARAVEL_ORDER, '1', 'payment_captured'))->toBeFalse();

    expect(app(OutcomeProcessor::class)->process($resolved, Source::Webhook)->dispatched())->toBeTrue()
        ->and(hostWrites())->toBe(['paid'])
        ->and($store->wasDelivered(LARAVEL_ORDER, '1', 'payment_captured'))->toBeTrue();

    expect(app(OutcomeProcessor::class)->process($resolved, Source::Reconcile)->duplicate)->toBeTrue()
        ->and($count['dispatched'])->toBe(2);
})->with('store databases')->with('store kinds');

it('goes again when the host rolls back around AttemptGuard, and stops once it commits', function (string $driver, string $kind) {
    $store = storeUnderTest($kind, storeOn($driver));
    [$payable, $count] = capturedPipeline($store);
    $guard = fn () => MibGlobalPay::attemptGuard()->canStartNewAttempt($payable);

    // The host's own lock around the guard, which nests the pipeline's lock inside it.
    expect(errorFrom(fn () => DB::transaction(function () use ($guard): void {
        $guard();

        throw new RuntimeException('host failed after the guard');
    })))->toBeInstanceOf(RuntimeException::class);

    expect($count['dispatched'])->toBe(1)
        ->and(hostWrites())->toBe([])
        ->and($store->wasDelivered(LARAVEL_ORDER, '1', 'payment_captured'))->toBeFalse();

    DB::transaction(function () use ($guard, $store, $kind, $count): void {
        $guard();

        // Inside the outer transaction: the database store has joined it, the cache store is waiting for the commit.
        expect($count['dispatched'])->toBe(2)
            ->and($store->wasDelivered(LARAVEL_ORDER, '1', 'payment_captured'))->toBe($kind === 'database');
    });

    expect(hostWrites())->toBe(['paid'])
        ->and($store->wasDelivered(LARAVEL_ORDER, '1', 'payment_captured'))->toBeTrue();

    DB::transaction($guard);

    expect($count['dispatched'])->toBe(2)->and(hostWrites())->toBe(['paid']);
})->with('store databases')->with('store kinds');

it('waits for a host transaction on another connection before recording', function (string $kind) {
    $store = storeUnderTest($kind, storeOn('sqlite'));
    config()->set('database.connections.mib_store_test_other', storeTestConfig('sqlite'));

    DB::connection('mib_store_test_other')->beginTransaction();
    $store->markDelivered('O-1', 'T-1', 'payment_captured');
    expect($store->wasDelivered('O-1', 'T-1', 'payment_captured'))->toBeFalse();
    DB::connection('mib_store_test_other')->rollBack();
    expect($store->wasDelivered('O-1', 'T-1', 'payment_captured'))->toBeFalse();

    DB::connection('mib_store_test_other')->beginTransaction();
    $store->markDelivered('O-1', 'T-1', 'payment_captured');
    DB::connection('mib_store_test_other')->commit();
    expect($store->wasDelivered('O-1', 'T-1', 'payment_captured'))->toBeTrue();

    DB::purge('mib_store_test_other');
})->with('store kinds');
