<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;

beforeEach(function (): void {
    DB::setDefaultConnection('testing');

    try {
        Schema::connection('testing')->dropIfExists('payment_transactions');
    } catch (\Exception) {
    }

    Schema::connection('testing')->create('payment_transactions', function ($table): void {
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

test('payment transaction isSuccessful uses container when available', function (): void {
    $normalizer = new StatusNormalizer;
    app()->instance(StatusNormalizer::class, $normalizer);

    $transaction = new PaymentTransaction(['status' => 'succeeded']);

    expect($transaction->isSuccessful())->toBeTrue();
});

test('payment transaction isSuccessful falls back to static when container unavailable', function (): void {
    $transaction = new PaymentTransaction(['status' => 'completed']);

    expect($transaction->isSuccessful())->toBeTrue();
});

test('payment transaction isFailed uses container when available', function (): void {
    $normalizer = new StatusNormalizer;
    app()->instance(StatusNormalizer::class, $normalizer);

    $transaction = new PaymentTransaction(['status' => 'declined']);

    expect($transaction->isFailed())->toBeTrue();
});

test('payment transaction isFailed falls back to static when container unavailable', function (): void {
    $transaction = new PaymentTransaction(['status' => 'rejected']);

    expect($transaction->isFailed())->toBeTrue();
});

test('payment transaction isPending uses container when available', function (): void {
    $normalizer = new StatusNormalizer;
    app()->instance(StatusNormalizer::class, $normalizer);

    $transaction = new PaymentTransaction(['status' => 'processing']);

    expect($transaction->isPending())->toBeTrue();
});

test('payment transaction isPending falls back to static when container unavailable', function (): void {
    $transaction = new PaymentTransaction(['status' => 'approved']);

    expect($transaction->isPending())->toBeTrue();
});
