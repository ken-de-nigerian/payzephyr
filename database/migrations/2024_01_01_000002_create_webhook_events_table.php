<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(config('payments.webhook.events.table', 'webhook_events'), function (Blueprint $table) {
            $table->id();
            $table->string('provider')->index();
            $table->string('event_key');
            $table->timestamps();

            $table->unique(['provider', 'event_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('payments.webhook.events.table', 'webhook_events'));
    }
};
