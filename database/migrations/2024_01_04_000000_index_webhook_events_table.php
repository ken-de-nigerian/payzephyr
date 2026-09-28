<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index webhook_events by (provider, created_at) for payzephyr:webhooks:prune.
 *
 * ADR-0005 described this index as part of the original table; it was never
 * added. Pruning selects one provider's rows older than a cutoff, which
 * without it is a full scan of a table that only grows. A separate migration
 * rather than an edit to the create migration, so installs that already ran
 * that one get it too.
 */
return new class extends Migration
{
    private const INDEX = 'webhook_events_provider_created_at_index';

    public function up(): void
    {
        $table = config('payments.webhook.events.table', 'webhook_events');

        if (Schema::hasIndex($table, self::INDEX)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) {
            $table->index(['provider', 'created_at'], self::INDEX);
        });
    }

    public function down(): void
    {
        $table = config('payments.webhook.events.table', 'webhook_events');

        if (! Schema::hasIndex($table, self::INDEX)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
