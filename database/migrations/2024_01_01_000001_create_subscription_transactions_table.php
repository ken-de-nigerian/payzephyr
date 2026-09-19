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
        Schema::create(config('payments.subscriptions.logging.table', 'subscription_transactions'), function (Blueprint $table) {
            $table->id();
            $table->string('subscription_code')->unique()->index();
            $table->string('provider')->index();
            $table->string('status')->index();
            $table->string('plan_code')->index();
            $table->string('customer_email');
            $table->decimal('amount', 15)->nullable();
            $table->string('currency', 3);
            $table->date('next_payment_date')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('payments.subscriptions.logging.table', 'subscription_transactions'));
    }
};
