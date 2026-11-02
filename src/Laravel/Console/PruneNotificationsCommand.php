<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\ConnectionInterface;
use IbnNajjaar\MIBGlobalPay\Laravel\Support\Settings;
use IbnNajjaar\MIBGlobalPay\Laravel\Stores\DatabaseNotificationStore;

/**
 * Deletes old rows from mib_global_pay_notifications (database store only). Keep more than
 * the gateway's 3-day redelivery window.
 *
 * Money-event delivery records stay: reconcile, AttemptGuard and the return URL can reach an attempt
 * at any time, and without its record the event would go out again. That is one small row per money
 * event. Rows it deletes are notification IDs and the per-notification claims of advisory events
 * (empty order ID).
 */
final class PruneNotificationsCommand extends Command
{
    protected $signature = 'mib:prune-notifications {--days=30 : Delete rows older than this many days (minimum 4)}';

    protected $description = 'Prune old MIB webhook dedupe records';

    public function handle(Settings $settings, ConnectionInterface $db): int
    {
        if ($settings->notificationStore() !== 'database') {
            $this->info('The notification store is not "database"; nothing to prune.');

            return self::SUCCESS;
        }

        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);

        if (! is_int($days) || $days < 4) {
            $this->error('--days must be an integer of at least 4 (the gateway redelivers for up to 3 days).');

            return self::INVALID;
        }

        $deleted = $db->table(DatabaseNotificationStore::TABLE)
            ->where('updated_at', '<', now()->subDays($days))
            ->where(fn (Builder $query): Builder => $query->whereNotNull('notification_id')->orWhere('order_id', ''))
            ->delete();

        $this->info("Deleted {$deleted} record(s).");

        return self::SUCCESS;
    }
}
