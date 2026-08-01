<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Models\SubscriptionTransaction;
use KenDeNigerian\PayZephyr\Repositories\EloquentSubscriptionRepository;

/**
 * Covers EloquentSubscriptionRepository::updateOrCreateAtomic()'s
 * catch (QueryException) recovery branch (ADR-0004): the initial
 * lockForUpdate()->first() finds nothing, but SubscriptionTransaction::create()
 * then loses a real race to a concurrent insert of the same subscription_code.
 *
 * tests/Unit/SubscriptionRepositoryTest.php's "recovers when the create step
 * loses a race..." test explicitly does NOT exercise this branch (its own
 * comment says so) because it pre-creates the row before calling the
 * repository, so the initial SELECT already finds it and takes the "existing"
 * branch. Here the competing row is inserted *during* create(), via a
 * `creating` model event that simulates the unique-constraint failure a real
 * concurrent writer would cause.
 */
beforeEach(function () {
    $this->repository = new EloquentSubscriptionRepository;
});

afterEach(function () {
    // The `creating` listener registered below is stored statically on the
    // model class - remove it so it doesn't leak into other test files.
    SubscriptionTransaction::flushEventListeners();
});

test('updateOrCreateAtomic recovers when create() genuinely loses the insert race', function () {
    $subscriptionCode = 'SUB_RACE_CODE';

    SubscriptionTransaction::creating(function (SubscriptionTransaction $model) use ($subscriptionCode) {
        if ($model->subscription_code !== $subscriptionCode) {
            return;
        }

        // Simulate a concurrent writer winning the race: insert the
        // competing row directly (bypassing Eloquent events to avoid
        // recursion), then throw the QueryException that a real unique
        // index violation would raise for our own INSERT.
        \Illuminate\Support\Facades\DB::table('subscription_transactions')->insert([
            'subscription_code' => $subscriptionCode,
            'provider' => 'paystack',
            'status' => 'active',
            'plan_code' => 'PLN_RACE',
            'customer_email' => 'racer@example.com',
            'amount' => 2500,
            'currency' => 'NGN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $previous = new PDOException('UNIQUE constraint failed: subscription_transactions.subscription_code', 23000);

        throw new \Illuminate\Database\QueryException(
            'testing',
            'insert into "subscription_transactions" ...',
            [],
            $previous
        );
    });

    $result = $this->repository->updateOrCreateAtomic($subscriptionCode, [
        'provider' => 'paystack',
        'status' => 'renewed-after-race',
        'plan_code' => 'PLN_RACE',
        'customer_email' => 'racer@example.com',
        'amount' => 2500,
        'currency' => 'NGN',
    ]);

    expect($result->subscription_code)->toBe($subscriptionCode)
        ->and($result->status)->toBe('renewed-after-race')
        ->and(SubscriptionTransaction::where('subscription_code', $subscriptionCode)->count())->toBe(1);
});

test('updateOrCreateAtomic rethrows a non-unique-constraint QueryException raised during create()', function () {
    $subscriptionCode = 'SUB_RACE_OTHER_ERROR';

    SubscriptionTransaction::creating(function (SubscriptionTransaction $model) use ($subscriptionCode) {
        if ($model->subscription_code !== $subscriptionCode) {
            return;
        }

        $previous = new PDOException('database is locked', 40001);

        throw new \Illuminate\Database\QueryException(
            'testing',
            'insert into "subscription_transactions" ...',
            [],
            $previous
        );
    });

    expect(fn () => $this->repository->updateOrCreateAtomic($subscriptionCode, [
        'provider' => 'paystack',
        'status' => 'active',
        'plan_code' => 'PLN_RACE',
        'customer_email' => 'racer2@example.com',
        'amount' => 2500,
        'currency' => 'NGN',
    ]))->toThrow(\Illuminate\Database\QueryException::class);

    expect(SubscriptionTransaction::where('subscription_code', $subscriptionCode)->count())->toBe(0);
});
