<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use KenDeNigerian\PayZephyr\Models\SubscriptionTransaction;
use KenDeNigerian\PayZephyr\Repositories\EloquentSubscriptionRepository;

/**
 * Covers EloquentSubscriptionRepository::updateOrCreateAtomic()'s
 * catch (QueryException) recovery branch (ADR-0004): the initial
 * lockForUpdate()->first() finds nothing, but SubscriptionTransaction::create()
 * then loses a real race to a concurrent insert of the same subscription_code.
 *
 * tests/Unit/SubscriptionRepositoryTest.php's "recovers when the create step
 * loses a race..." test does not exercise this branch: it pre-creates the row,
 * so the initial select finds it. Here the competing row lands between the
 * select and the insert, and the insert hits the real unique index - on every
 * database the suite runs on, so PostgreSQL's 23505 and its aborted
 * transaction are exercised too.
 */
beforeEach(function (): void {
    $this->repository = new EloquentSubscriptionRepository;
});

test('updateOrCreateAtomic recovers when create() genuinely loses the insert race', function (): void {
    insertConcurrentlyAfterLookup((new SubscriptionTransaction)->getTable(), [
        'subscription_code' => 'SUB_RACE_CODE',
        'provider' => 'paystack',
        'status' => 'active',
        'plan_code' => 'PLN_RACE',
        'customer_email' => 'racer@example.com',
        'amount' => 2500,
        'currency' => 'NGN',
    ]);

    $result = $this->repository->updateOrCreateAtomic('SUB_RACE_CODE', [
        'provider' => 'paystack',
        'status' => 'renewed-after-race',
        'plan_code' => 'PLN_RACE',
        'customer_email' => 'racer@example.com',
        'amount' => 2500,
        'currency' => 'NGN',
    ]);

    expect($result->subscription_code)->toBe('SUB_RACE_CODE')
        ->and($result->status)->toBe('renewed-after-race')
        ->and(SubscriptionTransaction::where('subscription_code', 'SUB_RACE_CODE')->count())->toBe(1);
});

test('updateOrCreateAtomic rethrows an integrity failure that is not a duplicate', function (): void {
    // A missing NOT NULL column is SQLSTATE 23000 on SQLite and MySQL - the
    // code the duplicate check used to treat as "duplicate key", which sent
    // this down the race path to a ModelNotFoundException instead.
    $thrown = null;

    try {
        $this->repository->updateOrCreateAtomic('SUB_NO_CURRENCY', [
            'provider' => 'paystack',
            'status' => 'active',
            'plan_code' => 'PLN_RACE',
            'customer_email' => 'racer2@example.com',
            'amount' => 2500,
        ]);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(QueryException::class)
        ->and($thrown)->not->toBeInstanceOf(UniqueConstraintViolationException::class)
        ->and(SubscriptionTransaction::where('subscription_code', 'SUB_NO_CURRENCY')->count())->toBe(0);
});
