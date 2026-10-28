<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * The package's only table: webhook dedupe (one row per X-Notification-ID) and business-level
 * dedupe (one row per order ID + transaction ID + outcome). It stores no host data.
 * Optional: publish it with `php artisan vendor:publish --tag=mib-global-pay-migrations`
 * when MIB_NOTIFICATION_STORE=database (the default).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('mib_global_pay_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('notification_id', 191)->nullable();
            $table->string('order_id', 40)->nullable();
            $table->string('transaction_id', 40)->nullable();
            $table->string('outcome', 40)->nullable();
            $table->string('status', 20)->nullable();
            $table->timestamps();

            // Explicit short names (MySQL allows 64 characters).
            $table->unique('notification_id', 'mib_gp_notifications_notification_unique');
            $table->index('order_id', 'mib_gp_notifications_order_index');
            $table->unique(['order_id', 'transaction_id', 'outcome'], 'mib_gp_notifications_business_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mib_global_pay_notifications');
    }
};
