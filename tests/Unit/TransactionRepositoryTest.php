<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Repositories\EloquentTransactionRepository;

beforeEach(function () {
    $this->repository = new EloquentTransactionRepository;
});

test('create persists a payment transaction', function () {
    $transaction = $this->repository->create([
        'reference' => 'REPO_TEST_1',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    expect($transaction->reference)->toBe('REPO_TEST_1')
        ->and($transaction->exists)->toBeTrue();
});

test('findByReference returns the matching transaction', function () {
    $this->repository->create([
        'reference' => 'REPO_TEST_2',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $found = $this->repository->findByReference('REPO_TEST_2');

    expect($found)->not->toBeNull()
        ->and($found->reference)->toBe('REPO_TEST_2');
});

test('findByReference returns null for an unknown reference', function () {
    expect($this->repository->findByReference('DOES_NOT_EXIST'))->toBeNull();
});

test('updateIfNotSuccessful applies the update and returns true when pending', function () {
    $this->repository->create([
        'reference' => 'REPO_TEST_3',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $updated = $this->repository->updateIfNotSuccessful('REPO_TEST_3', ['status' => 'success']);

    expect($updated)->toBeTrue();
    expect($this->repository->findByReference('REPO_TEST_3')->status)->toBe('success');
});

test('updateIfNotSuccessful is a no-op once the transaction is already successful', function () {
    $this->repository->create([
        'reference' => 'REPO_TEST_4',
        'provider' => 'paystack',
        'status' => 'success',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $updated = $this->repository->updateIfNotSuccessful('REPO_TEST_4', ['status' => 'failed']);

    expect($updated)->toBeFalse();
    // The already-successful status must not have been overwritten.
    expect($this->repository->findByReference('REPO_TEST_4')->status)->toBe('success');
});

test('updateIfNotSuccessful returns false for a reference that does not exist', function () {
    expect($this->repository->updateIfNotSuccessful('NOPE', ['status' => 'success']))->toBeFalse();
});

test('updateIfNotSuccessful is idempotent under repeated duplicate-webhook-style calls', function () {
    $this->repository->create([
        'reference' => 'REPO_TEST_5',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 10000,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $appliedCount = 0;
    for ($i = 0; $i < 3; $i++) {
        if ($this->repository->updateIfNotSuccessful('REPO_TEST_5', ['status' => 'success'])) {
            $appliedCount++;
        }
    }

    expect($appliedCount)->toBe(1);
});
