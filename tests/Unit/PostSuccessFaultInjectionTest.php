<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Events\PaymentInitiated;
use KenDeNigerian\PayZephyr\Models\RefundTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Repositories\EloquentRefundRepository;

/**
 * Fault injection for the paths that only run when something local breaks
 * *after* a provider has already acted.
 *
 * These are the guards behind the package's central invariant - a successful
 * charge must survive any local failure that follows it - so they need to be
 * exercised deliberately rather than left to chance.
 */
function faultChargeRequest(string $reference): ChargeRequestDTO
{
    return ChargeRequestDTO::fromArray([
        'amount' => 100.00,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => $reference,
    ]);
}

beforeEach(function () {
    app()->forgetInstance('payments.config');

    config([
        'payments.default' => 'primary',
        'payments.fallback' => 'secondary',
        'payments.health_check.enabled' => false,
        'payments.providers' => [
            'primary' => ['driver' => 'primary', 'enabled' => true, 'secret_key' => 'test'],
            'secondary' => ['driver' => 'secondary', 'enabled' => true, 'secret_key' => 'test'],
        ],
    ]);
});

test('a cache backend that cannot be reached does not block the charge', function () {
    // Claiming the in-flight lock is best-effort: if the cache is down we lose
    // double-submission protection, but refusing to charge at all would be a
    // worse outcome than proceeding without it.
    Cache::shouldReceive('add')->andThrow(new RuntimeException('cache backend unreachable'));
    Cache::shouldReceive('forget')->andReturnTrue();
    Cache::shouldReceive('put')->andReturnTrue();
    Cache::shouldReceive('get')->andReturnNull();

    $primary = makeCountingSuccessDriver('primary');
    $secondary = makeCountingSuccessDriver('secondary');

    $manager = new PaymentManager;
    injectFakeDrivers($manager, ['primary' => $primary, 'secondary' => $secondary]);

    $response = $manager->chargeWithFallback(faultChargeRequest('order_cache_down'));

    expect($response->reference)->toBe('ref_primary')
        ->and($primary->chargeCalls)->toBe(1)
        ->and($secondary->chargeCalls)->toBe(0);
});

test('a cache failure while releasing the in-flight claim does not fail the charge', function () {
    Cache::shouldReceive('add')->andReturnTrue();
    Cache::shouldReceive('put')->andReturnTrue();
    Cache::shouldReceive('get')->andReturnNull();
    Cache::shouldReceive('forget')->andThrow(new RuntimeException('cache backend died mid-release'));

    $primary = makeCountingSuccessDriver('primary');
    $secondary = makeCountingSuccessDriver('secondary');

    $manager = new PaymentManager;
    injectFakeDrivers($manager, ['primary' => $primary, 'secondary' => $secondary]);

    $response = $manager->chargeWithFallback(faultChargeRequest('order_release_fail'));

    expect($response->reference)->toBe('ref_primary')
        ->and($primary->chargeCalls)->toBe(1)
        ->and($secondary->chargeCalls)->toBe(0);
});

test('a transaction-logging failure after a successful charge never reaches the fallback provider', function () {
    // The provider has already charged the customer by this point, so a
    // failing transaction-log write must be absorbed - never turned into a
    // second charge against the fallback provider.
    $primary = makeCountingSuccessDriver('primary');
    $secondary = makeCountingSuccessDriver('secondary');

    $repository = Mockery::mock(TransactionRepositoryInterface::class);
    $repository->shouldReceive('create')->andThrow(new RuntimeException('transaction log write failed'));
    app()->instance(TransactionRepositoryInterface::class, $repository);

    $manager = new PaymentManager(transactionRepository: $repository);
    injectFakeDrivers($manager, ['primary' => $primary, 'secondary' => $secondary]);

    $response = $manager->chargeWithFallback(faultChargeRequest('order_log_fail'));

    expect($response->reference)->toBe('ref_primary')
        ->and($primary->chargeCalls)->toBe(1)
        ->and($secondary->chargeCalls)->toBe(0);
});

test('a PaymentInitiated listener that throws never reaches the fallback provider', function () {
    Event::listen(PaymentInitiated::class, function () {
        throw new RuntimeException('listener exploded');
    });

    $primary = makeCountingSuccessDriver('primary');
    $secondary = makeCountingSuccessDriver('secondary');

    $manager = new PaymentManager;
    injectFakeDrivers($manager, ['primary' => $primary, 'secondary' => $secondary]);

    $response = $manager->chargeWithFallback(faultChargeRequest('order_listener_fail'));

    expect($response->reference)->toBe('ref_primary')
        ->and($primary->chargeCalls)->toBe(1)
        ->and($secondary->chargeCalls)->toBe(0);
});

test('updateOrCreateAtomic recovers when another writer wins the create race', function () {
    // Reproduces the lost create race: the row does not exist when we look,
    // but does by the time we insert. A `creating` hook inserts the competing
    // row, so create() hits the unique constraint exactly as a concurrent
    // writer would cause - and the repository must then load and update the
    // winner's row instead of losing this event's data.
    $repo = new EloquentRefundRepository;

    $raced = false;
    RefundTransaction::creating(function (RefundTransaction $model) use (&$raced) {
        if ($raced || $model->refund_reference !== 'rf_raced') {
            return;
        }
        $raced = true;

        RefundTransaction::withoutEvents(function () {
            RefundTransaction::create([
                'refund_reference' => 'rf_raced',
                'transaction_reference' => 'txn_race',
                'provider' => 'primary',
                'status' => 'pending',
                'amount' => 10.00,
                'currency' => 'NGN',
            ]);
        });
    });

    $result = $repo->updateOrCreateAtomic('rf_raced', [
        'transaction_reference' => 'txn_race',
        'provider' => 'primary',
        'status' => 'completed',
        'amount' => 10.00,
        'currency' => 'NGN',
    ]);

    RefundTransaction::flushEventListeners();

    expect($raced)->toBeTrue()
        ->and($result->status)->toBe('completed')
        ->and(RefundTransaction::where('refund_reference', 'rf_raced')->count())->toBe(1);
});

test('updateOrCreateAtomic rethrows a query error that is not a unique-constraint violation', function () {
    // A genuine DB fault must surface, not be mistaken for a lost race and
    // silently retried.
    $repo = new EloquentRefundRepository;

    RefundTransaction::creating(function () {
        throw new QueryException('sqlite', 'insert into refund_transactions', [], new RuntimeException('disk I/O error'));
    });

    expect(fn () => $repo->updateOrCreateAtomic('rf_db_broken', [
        'transaction_reference' => 'txn_x',
        'provider' => 'primary',
        'status' => 'pending',
        'amount' => 1.00,
        'currency' => 'NGN',
    ]))->toThrow(QueryException::class);

    RefundTransaction::flushEventListeners();
});

test('a cache failure while clearing session data after verification does not fail the verify', function () {
    // Verification already succeeded against the provider; a stale cache entry
    // is a cleanup concern, not a reason to report the payment unverified.
    Cache::shouldReceive('get')->andReturnNull();
    Cache::shouldReceive('put')->andReturnTrue();
    Cache::shouldReceive('add')->andReturnTrue();
    Cache::shouldReceive('forget')->andThrow(new RuntimeException('cache backend died'));

    $primary = makeCountingSuccessDriver('primary');
    $secondary = makeCountingSuccessDriver('secondary');

    $manager = new PaymentManager;
    injectFakeDrivers($manager, ['primary' => $primary, 'secondary' => $secondary]);

    $response = $manager->verify('ref_verify_cache_fail', 'primary');

    expect($response->reference)->toBe('ref_verify_cache_fail')
        ->and($primary->verifyCalls)->toBe(1)
        ->and($secondary->verifyCalls)->toBe(0);
});

test('transaction logging is skipped entirely when logging is disabled', function () {
    config(['payments.logging.enabled' => false]);
    app()->forgetInstance('payments.config');

    $repository = Mockery::mock(TransactionRepositoryInterface::class);
    $repository->shouldNotReceive('create');

    $primary = makeCountingSuccessDriver('primary');
    $manager = new PaymentManager(transactionRepository: $repository);
    injectFakeDrivers($manager, ['primary' => $primary]);

    $response = $manager->chargeWithFallback(faultChargeRequest('order_logging_off'), ['primary']);

    expect($response->reference)->toBe('ref_primary')
        ->and($primary->chargeCalls)->toBe(1);
});
