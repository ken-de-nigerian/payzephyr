<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the request that produced a subscription row's state was sent.
 *
 * Rows are written from API responses - create, cancel, enable, fetch - and a
 * response can finish after a later request's did: a fetch sent before a
 * cancel committed, answered after it, would put "active" back over
 * "cancelled". The repository refuses a write whose request was sent before
 * the one that produced the stored state (ADR-0004's open follow-up).
 * Microsecond precision, because one process can send two requests in the
 * same second.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('payments.subscriptions.logging.table', 'subscription_transactions');

        if (Schema::hasColumn($table, 'state_as_of')) {
            return;
        }

        Schema::table($table, function (Blueprint $table) {
            $table->dateTime('state_as_of', 6)->nullable();
        });
    }

    public function down(): void
    {
        $table = config('payments.subscriptions.logging.table', 'subscription_transactions');

        if (! Schema::hasColumn($table, 'state_as_of')) {
            return;
        }

        Schema::table($table, function (Blueprint $table) {
            $table->dropColumn('state_as_of');
        });
    }
};
