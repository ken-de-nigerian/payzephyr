<?php

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
        Schema::create(config('payments.refunds.logging.table', 'refund_transactions'), function (Blueprint $table) {
            $table->id();
            $table->string('refund_reference')->unique()->index();
            $table->string('transaction_reference')->index();
            $table->string('provider')->index();
            $table->string('status')->index();
            $table->decimal('amount', 15);
            $table->string('currency', 3);
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('payments.refunds.logging.table', 'refund_transactions'));
    }
};
