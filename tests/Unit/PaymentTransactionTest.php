<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentServiceProvider;

/**
 * Set up the database schema for the model tests.
 * This ensures we don't rely on external migrations.
 */
beforeEach(function (): void {
    DB::setDefaultConnection('testing');

    try {
        Schema::connection('testing')->dropIfExists('payment_transactions');
    } catch (\Exception) {
    }

    Schema::connection('testing')->create('payment_transactions', function (Blueprint $table): void {
        $table->id();
        $table->string('reference');
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

test('it uses the configured table name', function (): void {
    app()->forgetInstance('payments.config');

    config(['payments.logging.table' => 'custom_transactions_table']);

    $provider = new PaymentServiceProvider(app());
    $reflection = new \ReflectionClass($provider);
    $method = $reflection->getMethod('configureModel');
    $method->invoke($provider);

    $model = new PaymentTransaction;

    expect($model->getTable())->toBe('custom_transactions_table');
});

test('it defaults to payment_transactions table if config is missing', function (): void {
    config(['payments.logging.table' => null]);

    $model = new PaymentTransaction;

    expect($model->getTable())->toBe('payment_transactions');
});

test('it casts attributes correctly', function (): void {
    $now = Carbon::now();
    $laravelVersion = (float) app()->version();

    $transaction = PaymentTransaction::create([
        'reference' => 'ref_123',
        'provider' => 'paystack',
        'status' => 'success',
        'amount' => 5000.50, // Float input
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'metadata' => ['order_id' => 1],
        'customer' => ['name' => 'Ken'],
        'paid_at' => $now,
    ]);

    $amount = $transaction->amount;
    expect(is_string($amount) ? $amount : number_format((float) $amount, 2, '.', ''))->toBe('5000.50')
        ->and($transaction->paid_at)->toBeInstanceOf(Carbon::class);

    if ($laravelVersion >= 11.0) {
        expect($transaction->metadata)->toBeInstanceOf(\ArrayObject::class)
            ->and($transaction->metadata['order_id'])->toBe(1)
            ->and($transaction->customer)->toBeInstanceOf(\ArrayObject::class);
    } else {
        expect($transaction->metadata)->toBeArray()
            ->and($transaction->metadata['order_id'])->toBe(1)
            ->and($transaction->customer)->toBeArray()
            ->and($transaction->customer['name'])->toBe('Ken');
    }
});

test('it determines successful status correctly', function (): void {
    $successStatuses = ['success', 'succeeded', 'completed', 'successful'];
    $failedStatuses = ['failed', 'pending', 'cancelled'];

    foreach ($successStatuses as $status) {
        $model = new PaymentTransaction(['status' => $status]);
        expect($model->isSuccessful())->toBeTrue("Failed asserting true for status: $status");
    }

    foreach ($failedStatuses as $status) {
        $model = new PaymentTransaction(['status' => $status]);
        expect($model->isSuccessful())->toBeFalse("Failed asserting false for status: $status");
    }
});

test('it determines failed status correctly', function (): void {
    $failedStatuses = ['failed', 'cancelled', 'declined'];
    $otherStatuses = ['success', 'pending', 'completed'];

    foreach ($failedStatuses as $status) {
        $model = new PaymentTransaction(['status' => $status]);
        expect($model->isFailed())->toBeTrue("Failed asserting true for status: $status");
    }

    foreach ($otherStatuses as $status) {
        $model = new PaymentTransaction(['status' => $status]);
        expect($model->isFailed())->toBeFalse("Failed asserting false for status: $status");
    }
});

test('it determines pending status correctly', function (): void {
    $model = new PaymentTransaction(['status' => 'pending']);
    expect($model->isPending())->toBeTrue();

    $model = new PaymentTransaction(['status' => 'PENDING']); // Case-insensitive check
    expect($model->isPending())->toBeTrue();

    $model = new PaymentTransaction(['status' => 'success']);
    expect($model->isPending())->toBeFalse();
});

test('scope successful filters correctly', function (): void {
    PaymentTransaction::create(factoryData(['status' => 'success']));
    PaymentTransaction::create(factoryData(['status' => 'completed']));
    PaymentTransaction::create(factoryData(['status' => 'failed']));
    PaymentTransaction::create(factoryData(['status' => 'pending']));

    $successful = PaymentTransaction::successful()->get();

    expect($successful)->toHaveCount(2)
        ->and($successful->pluck('status')->toArray())->toContain('success', 'completed');
});

test('scope failed filters correctly', function (): void {
    PaymentTransaction::create(factoryData(['status' => 'failed']));
    PaymentTransaction::create(factoryData(['status' => 'declined']));
    PaymentTransaction::create(factoryData(['status' => 'success']));

    $failed = PaymentTransaction::failed()->get();

    expect($failed)->toHaveCount(2)
        ->and($failed->pluck('status')->toArray())->toContain('failed', 'declined');
});

test('scope pending filters correctly', function (): void {
    PaymentTransaction::create(factoryData(['status' => 'pending']));
    PaymentTransaction::create(factoryData(['status' => 'success']));

    $pending = PaymentTransaction::pending()->get();

    expect($pending)->toHaveCount(1)
        ->and($pending->first()->status)->toBe('pending');
});

/**
 * Helper to generate dummy data for tests
 */
function factoryData(array $overrides = []): array
{
    return array_merge([
        'reference' => 'ref_'.uniqid(),
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'user@test.com',
    ], $overrides);
}
