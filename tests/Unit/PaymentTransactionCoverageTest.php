<?php

use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;

beforeEach(function () {
    \Illuminate\Support\Facades\DB::setDefaultConnection('testing');
    
    try {
        \Illuminate\Support\Facades\Schema::connection('testing')->dropIfExists('payment_transactions');
    } catch (\Exception $e) {
        // Ignore if table doesn't exist
    }
    
    \Illuminate\Support\Facades\Schema::connection('testing')->create('payment_transactions', function ($table) {
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

test('payment transaction isSuccessful uses container when available', function () {
    $normalizer = new StatusNormalizer();
    app()->instance(StatusNormalizer::class, $normalizer);
    
    $transaction = new PaymentTransaction(['status' => 'succeeded']);
    
    expect($transaction->isSuccessful())->toBeTrue();
});

test('payment transaction isSuccessful falls back to static when container unavailable', function () {
    $transaction = new PaymentTransaction(['status' => 'completed']);
    
    expect($transaction->isSuccessful())->toBeTrue();
});

test('payment transaction isFailed uses container when available', function () {
    $normalizer = new StatusNormalizer();
    app()->instance(StatusNormalizer::class, $normalizer);
    
    $transaction = new PaymentTransaction(['status' => 'declined']);
    
    expect($transaction->isFailed())->toBeTrue();
});

test('payment transaction isFailed falls back to static when container unavailable', function () {
    $transaction = new PaymentTransaction(['status' => 'rejected']);
    
    expect($transaction->isFailed())->toBeTrue();
});

test('payment transaction isPending uses container when available', function () {
    $normalizer = new StatusNormalizer();
    app()->instance(StatusNormalizer::class, $normalizer);
    
    $transaction = new PaymentTransaction(['status' => 'processing']);
    
    expect($transaction->isPending())->toBeTrue();
});

test('payment transaction isPending falls back to static when container unavailable', function () {
    $transaction = new PaymentTransaction(['status' => 'approved']);
    
    expect($transaction->isPending())->toBeTrue();
});
