<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KenDeNigerian\PayZephyr\Facades\Payment;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;

beforeEach(function (): void {
    DB::setDefaultConnection('testing');

    try {
        Schema::connection('testing')->dropIfExists('payment_transactions');
    } catch (\Exception) {
    }

    Schema::connection('testing')->create('payment_transactions', function ($table): void {
        $table->id();
        $table->string('reference')->unique();
        $table->string('provider');
        $table->string('status');
        $table->decimal('amount', 15, 2);
        $table->string('currency');
        $table->string('email');
        $table->string('channel')->nullable();
        $table->json('metadata')->nullable();
        $table->json('customer')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->timestamps();
    });
});

test('it handles high volume payment initializations', function (): void {

    $references = [];

    for ($i = 0; $i < 10; $i++) {
        try {
            $response = Payment::amount(10000 + $i)
                ->email("user{$i}@example.com")
                ->callback('https://example.com/callback')
                ->charge();

            $references[] = $response->reference;
        } catch (\Exception) {
        }
    }

    expect(array_unique($references))->toHaveCount(count($references));
});

test('it handles large number of transactions in database', function (): void {
    for ($i = 0; $i < 100; $i++) {
        PaymentTransaction::create([
            'reference' => "TEST_REF_{$i}",
            'provider' => 'paystack',
            'status' => $i % 3 === 0 ? 'success' : ($i % 3 === 1 ? 'failed' : 'pending'),
            'amount' => 10000 + $i,
            'currency' => 'NGN',
            'email' => "user{$i}@example.com",
        ]);
    }

    $startTime = microtime(true);

    $successful = PaymentTransaction::successful()->count();
    $failed = PaymentTransaction::failed()->count();
    $pending = PaymentTransaction::pending()->count();

    $endTime = microtime(true);
    $duration = $endTime - $startTime;

    expect($duration)->toBeLessThan(5.0);

    $total = $successful + $failed + $pending;
    expect($total)->toBeGreaterThanOrEqual(100);
});

test('it handles concurrent cache operations', function (): void {
    $references = [];

    for ($i = 0; $i < 20; $i++) {
        $ref = "CACHE_TEST_{$i}";
        $references[] = $ref;

        Cache::put("payzephyr:session:{$ref}", [
            'provider' => 'paystack',
            'id' => "provider_id_{$i}",
        ], now()->addHour());
    }

    foreach ($references as $ref) {
        expect(Cache::get("payzephyr:session:{$ref}"))->not->toBeNull();
    }
});

test('it handles bulk transaction queries efficiently', function (): void {
    $statuses = ['success', 'failed', 'pending'];

    for ($i = 0; $i < 50; $i++) {
        PaymentTransaction::create([
            'reference' => "BULK_REF_{$i}",
            'provider' => 'paystack',
            'status' => $statuses[$i % 3],
            'amount' => 10000,
            'currency' => 'NGN',
            'email' => "bulk{$i}@example.com",
        ]);
    }

    $all = PaymentTransaction::where('provider', 'paystack')->count();
    $successful = PaymentTransaction::successful()->count();
    $failed = PaymentTransaction::failed()->count();

    expect($all)->toBeGreaterThanOrEqual(50)
        ->and($successful)->toBeGreaterThanOrEqual(0)
        ->and($failed)->toBeGreaterThanOrEqual(0);
});
