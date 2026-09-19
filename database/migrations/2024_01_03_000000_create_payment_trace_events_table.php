<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection($this->connection())->create($this->tableName(), function (Blueprint $table) {
            $table->id();
            $table->string('reference', 255)->index();
            $table->string('provider', 50)->nullable()->index();
            $table->uuid('correlation_id')->nullable()->index();

            $table->string('event', 100)->index();
            $table->string('direction', 20);

            $table->json('payload')->nullable();
            $table->json('metadata')->nullable();

            $table->string('http_method', 10)->nullable();
            $table->text('http_url')->nullable();
            $table->unsignedSmallInteger('http_status_code')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();

            $table->timestamps(3);

            $table->index(['reference', 'created_at'], 'payment_trace_timeline_idx');
            $table->index(['correlation_id', 'created_at'], 'payment_trace_correlation_idx');
            $table->index(['provider', 'event', 'created_at'], 'payment_trace_provider_event_idx');
            $table->index('created_at', 'payment_trace_retention_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->tableName());
    }

    private function connection(): ?string
    {
        $connection = config('payments.trace.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    private function tableName(): string
    {
        $table = config('payments.trace.table');

        return is_string($table) && $table !== '' ? $table : 'payment_trace_events';
    }
};
