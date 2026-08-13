<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use KenDeNigerian\PayZephyr\Models\RefundTransaction;
use KenDeNigerian\PayZephyr\Repositories\EloquentRefundRepository;

beforeEach(function () {
    $this->repository = new EloquentRefundRepository;
});

test('updateStatusIfExists updates the status of an existing pending refund', function () {
    $this->repository->updateOrCreateAtomic('REF_UPD_1', [
        'transaction_reference' => 'TXN_UPD_1',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 5000,
        'currency' => 'NGN',
    ]);

    $applied = $this->repository->updateStatusIfExists('REF_UPD_1', 'completed');

    expect($applied)->toBeTrue()
        ->and(RefundTransaction::where('refund_reference', 'REF_UPD_1')->first()->status)->toBe('completed');
});

test('updateStatusIfExists is a safe no-op when the refund does not exist locally', function () {
    $applied = $this->repository->updateStatusIfExists('REF_DOES_NOT_EXIST', 'completed');

    expect($applied)->toBeFalse()
        ->and(RefundTransaction::where('refund_reference', 'REF_DOES_NOT_EXIST')->exists())->toBeFalse();
});

test('updateStatusIfExists never regresses a refund that already reached a terminal state', function () {
    // Guards against an out-of-order or replayed webhook delivery (e.g. a
    // stale "processing" event arriving after "completed" already landed)
    // flipping a resolved refund's status backward.
    $this->repository->updateOrCreateAtomic('REF_TERMINAL', [
        'transaction_reference' => 'TXN_TERMINAL',
        'provider' => 'paystack',
        'status' => 'completed',
        'amount' => 5000,
        'currency' => 'NGN',
    ]);

    $applied = $this->repository->updateStatusIfExists('REF_TERMINAL', 'failed');

    expect($applied)->toBeFalse()
        ->and(RefundTransaction::where('refund_reference', 'REF_TERMINAL')->first()->status)->toBe('completed');
});

test('updateOrCreateAtomic creates a new refund transaction', function () {
    $result = $this->repository->updateOrCreateAtomic('REF_1', [
        'transaction_reference' => 'TXN_1',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 5000,
        'currency' => 'NGN',
    ]);

    expect($result->refund_reference)->toBe('REF_1')
        ->and($result->status)->toBe('pending')
        ->and(RefundTransaction::where('refund_reference', 'REF_1')->count())->toBe(1);
});

test('updateOrCreateAtomic updates the existing row for a known refund_reference', function () {
    $this->repository->updateOrCreateAtomic('REF_2', [
        'transaction_reference' => 'TXN_2',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 5000,
        'currency' => 'NGN',
    ]);

    $result = $this->repository->updateOrCreateAtomic('REF_2', [
        'transaction_reference' => 'TXN_2',
        'provider' => 'paystack',
        'status' => 'completed',
        'amount' => 5000,
        'currency' => 'NGN',
    ]);

    expect($result->status)->toBe('completed')
        ->and(RefundTransaction::where('refund_reference', 'REF_2')->count())->toBe(1);
});

test('updateOrCreateAtomic does not lose data across repeated concurrent-style calls for a new reference', function () {
    for ($i = 0; $i < 3; $i++) {
        $this->repository->updateOrCreateAtomic('REF_3', [
            'transaction_reference' => 'TXN_3',
            'provider' => 'paystack',
            'status' => "state_$i",
            'amount' => 5000,
            'currency' => 'NGN',
        ]);
    }

    expect(RefundTransaction::where('refund_reference', 'REF_3')->count())->toBe(1)
        ->and(RefundTransaction::where('refund_reference', 'REF_3')->first()->status)->toBe('state_2');
});

test('updateOrCreateAtomic recovers when the create step loses a race to a concurrent insert', function () {
    RefundTransaction::create([
        'refund_reference' => 'REF_4',
        'transaction_reference' => 'TXN_4',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
    ]);

    $result = $this->repository->updateOrCreateAtomic('REF_4', [
        'transaction_reference' => 'TXN_4',
        'provider' => 'paystack',
        'status' => 'completed',
        'amount' => 1000,
        'currency' => 'NGN',
    ]);

    expect($result->status)->toBe('completed')
        ->and(RefundTransaction::where('refund_reference', 'REF_4')->count())->toBe(1);
});

test('isUniqueConstraintViolation correctly classifies a unique index violation', function () {
    RefundTransaction::create([
        'refund_reference' => 'REF_5',
        'transaction_reference' => 'TXN_5',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 1000,
        'currency' => 'NGN',
    ]);

    $reflection = new ReflectionClass($this->repository);
    $method = $reflection->getMethod('isUniqueConstraintViolation');
    $method->setAccessible(true);

    try {
        RefundTransaction::create([
            'refund_reference' => 'REF_5',
            'transaction_reference' => 'TXN_5',
            'provider' => 'paystack',
            'status' => 'pending',
            'amount' => 1000,
            'currency' => 'NGN',
        ]);
        expect(false)->toBeTrue('Expected a QueryException from the duplicate insert.');
    } catch (QueryException $e) {
        expect($method->invoke($this->repository, $e))->toBeTrue();
    }
});

test('sumRefundedAmount sums pending, processing, and completed refunds but excludes failed and cancelled ones', function () {
    $this->repository->updateOrCreateAtomic('REF_SUM_1', [
        'transaction_reference' => 'TXN_SUM', 'provider' => 'paystack', 'status' => 'completed', 'amount' => 1000, 'currency' => 'NGN',
    ]);
    $this->repository->updateOrCreateAtomic('REF_SUM_2', [
        'transaction_reference' => 'TXN_SUM', 'provider' => 'paystack', 'status' => 'pending', 'amount' => 500, 'currency' => 'NGN',
    ]);
    $this->repository->updateOrCreateAtomic('REF_SUM_3', [
        'transaction_reference' => 'TXN_SUM', 'provider' => 'paystack', 'status' => 'failed', 'amount' => 2000, 'currency' => 'NGN',
    ]);
    $this->repository->updateOrCreateAtomic('REF_SUM_4', [
        'transaction_reference' => 'TXN_SUM', 'provider' => 'paystack', 'status' => 'cancelled', 'amount' => 3000, 'currency' => 'NGN',
    ]);

    expect($this->repository->sumRefundedAmount('TXN_SUM'))->toBe(1500.0);
});

test('sumRefundedAmount returns 0.0 for a transaction with no refunds', function () {
    expect($this->repository->sumRefundedAmount('TXN_NONE'))->toBe(0.0);
});
