<?php

declare(strict_types=1);

/*
 * One racer for the multi-process tests in DatabaseNotificationStoreTest. Claims each key at a
 * shared moment, so that the other workers are claiming the same key at the same time, and prints
 * what it was told for each key as JSON.
 *
 * Arguments: JSON connection config, mode (plain|transaction|rollback), kind (claim|claimTransaction|markDelivered),
 * worker number, key count, start time, slot length in seconds.
 */

use Illuminate\Database\Connection;
use Illuminate\Database\Capsule\Manager;
use IbnNajjaar\MIBGlobalPay\Laravel\Stores\DatabaseNotificationStore;

require __DIR__ . '/../../../vendor/autoload.php';

[, $config, $mode, $kind, $worker, $keys, $startAt, $slot] = $argv;

$capsule = new Manager();
$capsule->addConnection(json_decode($config, true, flags: JSON_THROW_ON_ERROR));

/** @var Connection $db */
$db = $capsule->getConnection();
$db->getPdo();
$store = new DatabaseNotificationStore($db);
$results = [];

for ($key = 0; $key < (int) $keys; $key++) {
    $moment = (float) $startAt + $key * (float) $slot;

    while (microtime(true) < $moment) {
        usleep(100);
    }

    $claim = static function () use ($store, $kind, $key): bool {
        if ($kind === 'markDelivered') {
            // Told nothing: 'won' only says it did not throw.
            $store->markDelivered('ORDER-1', sha1("race-{$key}"), 'payment_captured');

            return true;
        }

        return $kind === 'claim'
            ? $store->claim("race-{$key}", 'ORDER-1')
            : $store->claimTransaction('', sha1("race-{$key}"), 'notification_received');
    };

    $hostWrite = static fn (string $stage) => $db->table('mib_test_host_writes')
        ->insert(['key_name' => "{$worker}-{$key}", 'stage' => $stage]);

    try {
        if ($mode === 'plain') {
            $results[$key] = $claim() ? 'won' : 'lost';

            continue;
        }

        $db->beginTransaction();
        $hostWrite('before');
        $won = $claim();
        $hostWrite('after');

        if ($mode === 'rollback' && $won && (int) $worker % 2 === 0) {
            usleep(2000);
            $db->rollBack();
            $results[$key] = 'rolled-back';

            continue;
        }

        $db->commit();
        $results[$key] = $won ? 'won' : 'lost';
    } catch (Throwable $e) {
        $results[$key] = 'error: ' . $e->getMessage();

        while ($db->transactionLevel() > 0) {
            $db->rollBack();
        }
    }
}

echo json_encode($results, JSON_THROW_ON_ERROR);
