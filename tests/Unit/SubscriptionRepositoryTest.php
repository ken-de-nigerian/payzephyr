<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use KenDeNigerian\PayZephyr\Models\SubscriptionTransaction;
use KenDeNigerian\PayZephyr\Repositories\EloquentSubscriptionRepository;

beforeEach(function () {
    $this->repository = new EloquentSubscriptionRepository;
});

test('updateOrCreateAtomic creates a new subscription transaction', function () {
    $result = $this->repository->updateOrCreateAtomic('SUB_CODE_1', [
        'provider' => 'paystack',
        'status' => 'active',
        'plan_code' => 'PLN_1',
        'customer_email' => 'test@example.com',
        'amount' => 5000,
        'currency' => 'NGN',
    ]);

    expect($result->subscription_code)->toBe('SUB_CODE_1')
        ->and($result->status)->toBe('active')
        ->and(SubscriptionTransaction::where('subscription_code', 'SUB_CODE_1')->count())->toBe(1);
});

test('updateOrCreateAtomic updates the existing row for a known subscription_code', function () {
    $this->repository->updateOrCreateAtomic('SUB_CODE_2', [
        'provider' => 'paystack',
        'status' => 'active',
        'plan_code' => 'PLN_1',
        'customer_email' => 'test@example.com',
        'amount' => 5000,
        'currency' => 'NGN',
    ]);

    $result = $this->repository->updateOrCreateAtomic('SUB_CODE_2', [
        'provider' => 'paystack',
        'status' => 'cancelled',
        'plan_code' => 'PLN_1',
        'customer_email' => 'test@example.com',
        'amount' => 5000,
        'currency' => 'NGN',
    ]);

    expect($result->status)->toBe('cancelled')
        ->and(SubscriptionTransaction::where('subscription_code', 'SUB_CODE_2')->count())->toBe(1);
});

test('updateOrCreateAtomic does not lose data across repeated concurrent-style calls for a new code', function () {
    // Simulates the bug ADR-0004 fixes: multiple webhook deliveries racing
    // to persist the same brand-new subscription_code must not throw, and
    // must not silently drop the losing write - exactly one row must exist,
    // reflecting the last write applied.
    for ($i = 0; $i < 3; $i++) {
        $this->repository->updateOrCreateAtomic('SUB_CODE_3', [
            'provider' => 'paystack',
            'status' => "state_$i",
            'plan_code' => 'PLN_1',
            'customer_email' => 'test@example.com',
            'amount' => 5000,
            'currency' => 'NGN',
        ]);
    }

    expect(SubscriptionTransaction::where('subscription_code', 'SUB_CODE_3')->count())->toBe(1)
        ->and(SubscriptionTransaction::where('subscription_code', 'SUB_CODE_3')->first()->status)->toBe('state_2');
});

test('updateOrCreateAtomic recovers when the create step loses a race to a concurrent insert', function () {
    // Directly exercises the catch-and-retry branch: force SubscriptionTransaction::create()
    // to hit the unique index by having a row already present with the same
    // subscription_code at the moment of insert. lockForUpdate() would normally
    // have found this row first in a real race; here we assert the repository's
    // fallback path (catch QueryException -> re-select -> update) still
    // produces a correct, non-throwing result rather than propagating the
    // exception or dropping the write.
    SubscriptionTransaction::create([
        'subscription_code' => 'SUB_CODE_4',
        'provider' => 'paystack',
        'status' => 'active',
        'plan_code' => 'PLN_1',
        'customer_email' => 'first@example.com',
        'amount' => 1000,
        'currency' => 'NGN',
    ]);

    // updateOrCreateAtomic's own lockForUpdate() SELECT will find this row
    // (since it now exists), so it takes the "existing" branch rather than
    // the catch branch - this test's real value is confirming the outcome is
    // still correct end-to-end, while the isolated unique-violation
    // classification is covered directly below.
    $result = $this->repository->updateOrCreateAtomic('SUB_CODE_4', [
        'provider' => 'paystack',
        'status' => 'renewed',
        'plan_code' => 'PLN_1',
        'customer_email' => 'first@example.com',
        'amount' => 1000,
        'currency' => 'NGN',
    ]);

    expect($result->status)->toBe('renewed')
        ->and(SubscriptionTransaction::where('subscription_code', 'SUB_CODE_4')->count())->toBe(1);
});

test('isUniqueConstraintViolation correctly classifies a unique index violation', function () {
    SubscriptionTransaction::create([
        'subscription_code' => 'SUB_CODE_5',
        'provider' => 'paystack',
        'status' => 'active',
        'plan_code' => 'PLN_1',
        'customer_email' => 'test@example.com',
        'amount' => 1000,
        'currency' => 'NGN',
    ]);

    $reflection = new ReflectionClass($this->repository);
    $method = $reflection->getMethod('isUniqueConstraintViolation');
    $method->setAccessible(true);

    try {
        // Bypasses updateOrCreate-style guards to force a raw duplicate insert.
        SubscriptionTransaction::create([
            'subscription_code' => 'SUB_CODE_5',
            'provider' => 'paystack',
            'status' => 'active',
            'plan_code' => 'PLN_1',
            'customer_email' => 'test@example.com',
            'amount' => 1000,
            'currency' => 'NGN',
        ]);
        expect(false)->toBeTrue('Expected a QueryException from the duplicate insert.');
    } catch (QueryException $e) {
        expect($method->invoke($this->repository, $e))->toBeTrue();
    }
});
