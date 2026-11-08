<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * Checkout attempts, for hosts using the package's ready-made storage (the HasMibPayments trait).
 * Publish it with `php artisan vendor:publish --tag=mib-global-pay-migrations`.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('mib_global_pay_attempts', function (Blueprint $table): void {
            $table->id();
            $table->morphs('payable', 'mib_gp_attempts_payable_index');
            $table->unsignedInteger('attempt_number');
            $table->string('gateway_order_id', 40)->unique('mib_gp_attempts_order_unique');
            $table->string('operation', 20);
            $table->string('state', 20)->index('mib_gp_attempts_state_index');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('session_id', 64)->nullable();
            $table->string('session_version', 20)->nullable();
            $table->string('success_indicator', 64)->nullable();
            $table->string('payment_link_id', 64)->nullable();
            $table->string('payment_link_url', 500)->nullable();
            $table->string('settled_transaction_id', 40)->nullable();
            $table->unsignedBigInteger('settled_amount_minor')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamp('confirmed_unpaid_at')->nullable();
            $table->string('confirmed_unpaid_by')->nullable();
            $table->string('confirmed_unpaid_reason', 500)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['payable_type', 'payable_id', 'attempt_number'], 'mib_gp_attempts_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mib_global_pay_attempts');
    }
};
