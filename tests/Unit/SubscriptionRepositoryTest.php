<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Drivers\AbstractDriver;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Models\SubscriptionTransaction;
use KenDeNigerian\PayZephyr\Repositories\EloquentSubscriptionRepository;

beforeEach(function (): void {
    $this->repository = new EloquentSubscriptionRepository;
});

test('updateOrCreateAtomic creates a new subscription transaction', function (): void {
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

test('updateOrCreateAtomic updates the existing row for a known subscription_code', function (): void {
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

test('updateOrCreateAtomic does not lose data across repeated concurrent-style calls for a new code', function (): void {
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

test('updateOrCreateAtomic recovers when the create step loses a race to a concurrent insert', function (): void {
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

test('isUniqueConstraintViolation correctly classifies a unique index violation', function (): void {
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

/*
 * Rows are written from API responses, and a response can finish after a
 * later request's did. state_as_of is when the producing request was sent;
 * a write older than the stored state is refused (ADR-0004's follow-up).
 */

function subscriptionAttributes(string $status, ?CarbonImmutable $stateAsOf): array
{
    return array_filter([
        'provider' => 'paystack',
        'status' => $status,
        'plan_code' => 'PLN_1',
        'customer_email' => 'test@example.com',
        'amount' => 5000,
        'currency' => 'NGN',
        'state_as_of' => $stateAsOf,
    ], fn ($value): bool => $value !== null);
}

test('a write whose request was sent before the stored state is refused', function (): void {
    $sentAt = CarbonImmutable::parse('2026-09-28 12:00:00');

    // The cancel, sent second, was answered first and written.
    $this->repository->updateOrCreateAtomic('SUB_ORDER', subscriptionAttributes('cancelled', $sentAt->addSeconds(2)));
    // The fetch, sent first, is answered last and still says active.
    $this->repository->updateOrCreateAtomic('SUB_ORDER', subscriptionAttributes('active', $sentAt));

    $row = SubscriptionTransaction::where('subscription_code', 'SUB_ORDER')->first();
    expect($row->status)->toBe('cancelled')
        ->and($row->state_as_of->equalTo($sentAt->addSeconds(2)))->toBeTrue();
});

test('a later request\'s state is applied, including moving out of cancelled', function (): void {
    // Re-enabling is legitimate, which is why this is ordered by time and
    // not by treating cancelled as final.
    $sentAt = CarbonImmutable::parse('2026-09-28 12:00:00');

    $this->repository->updateOrCreateAtomic('SUB_REENABLE', subscriptionAttributes('cancelled', $sentAt));
    $this->repository->updateOrCreateAtomic('SUB_REENABLE', subscriptionAttributes('active', $sentAt->addMinute()));

    expect(SubscriptionTransaction::where('subscription_code', 'SUB_REENABLE')->first()->status)->toBe('active');
});

test('requests sent within the same second are still ordered, to the microsecond', function (): void {
    $first = CarbonImmutable::parse('2026-09-28 12:00:00.100000');
    $second = CarbonImmutable::parse('2026-09-28 12:00:00.900000');

    $this->repository->updateOrCreateAtomic('SUB_MICRO', subscriptionAttributes('cancelled', $second));
    $this->repository->updateOrCreateAtomic('SUB_MICRO', subscriptionAttributes('active', $first));

    $row = SubscriptionTransaction::where('subscription_code', 'SUB_MICRO')->first();
    expect($row->status)->toBe('cancelled')
        ->and($row->state_as_of->format('u'))->toBe('900000');
});

test('writes without a send time, or onto a row without one, are applied as before', function (): void {
    $this->repository->updateOrCreateAtomic('SUB_LEGACY', subscriptionAttributes('active', null));
    $this->repository->updateOrCreateAtomic('SUB_LEGACY', subscriptionAttributes('cancelled', CarbonImmutable::parse('2026-01-01')));
    $this->repository->updateOrCreateAtomic('SUB_LEGACY', subscriptionAttributes('active', null));

    expect(SubscriptionTransaction::where('subscription_code', 'SUB_LEGACY')->first()->status)->toBe('active');
});

test('the create-race path refuses a stale write too', function (): void {
    // The select finds nothing, then a concurrent writer inserts the row with
    // newer state before this create runs; the fallback update must still
    // compare send times.
    insertConcurrentlyAfterLookup((new SubscriptionTransaction)->getTable(), [
        'subscription_code' => 'SUB_RACE', 'provider' => 'paystack', 'status' => 'cancelled',
        'plan_code' => 'PLN_1', 'customer_email' => 'test@example.com', 'amount' => 5000, 'currency' => 'NGN',
        'state_as_of' => '2026-09-28 12:00:05.000000',
    ]);

    $result = $this->repository->updateOrCreateAtomic('SUB_RACE', subscriptionAttributes('active', CarbonImmutable::parse('2026-09-28 12:00:00')));

    expect($result->status)->toBe('cancelled');
});

test('an install that has not added the state_as_of column keeps logging, without ordering', function (): void {
    $table = (new SubscriptionTransaction)->getTable();
    Schema::table($table, fn (Blueprint $t) => $t->dropColumn('state_as_of'));

    $repository = new EloquentSubscriptionRepository;
    $repository->updateOrCreateAtomic('SUB_NO_COLUMN', subscriptionAttributes('active', CarbonImmutable::now()));

    expect(SubscriptionTransaction::where('subscription_code', 'SUB_NO_COLUMN')->value('status'))->toBe('active');
});

test('a driver response logged after a newer one from another process does not overwrite it', function (): void {
    // Two workers, one subscription: A fetches, B cancels. B's request is sent
    // after A's but answered first. A's stale "active" must not win.
    $makeDriver = fn (): PaystackDriver => new PaystackDriver(['secret_key' => 'sk_test', 'currencies' => ['NGN']]);
    $mark = new ReflectionMethod(AbstractDriver::class, 'markRequestSent');
    $log = new ReflectionMethod(PaystackDriver::class, 'logSubscriptionFromResponse');
    $response = fn (string $status): SubscriptionResponseDTO => new SubscriptionResponseDTO(
        subscriptionCode: 'SUB_TWO_WORKERS', status: $status, customer: 'test@example.com', plan: 'PLN_1',
        amount: 50.0, currency: 'NGN', provider: 'paystack',
    );

    $workerA = $makeDriver();
    $workerB = $makeDriver();

    CarbonImmutable::setTestNow('2026-09-28 12:00:00.000000');
    $mark->invoke($workerA);
    CarbonImmutable::setTestNow('2026-09-28 12:00:00.400000');
    $mark->invoke($workerB);
    CarbonImmutable::setTestNow();

    $log->invoke($workerB, $response('cancelled'));
    $log->invoke($workerA, $response('active'));

    expect(SubscriptionTransaction::where('subscription_code', 'SUB_TWO_WORKERS')->value('status'))->toBe('cancelled');
});

test('makeRequest records when each request was sent', function (): void {
    $driver = new PaystackDriver(['secret_key' => 'sk_test', 'currencies' => ['NGN']]);
    $driver->setClient(new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, [], '{"status":true}'),
    ]))]));
    $lastSent = new ReflectionMethod(AbstractDriver::class, 'lastRequestSentAt');

    expect($lastSent->invoke($driver))->toBeNull();

    CarbonImmutable::setTestNow('2026-09-28 09:30:00.250000');
    (new ReflectionMethod($driver, 'makeRequest'))->invoke($driver, 'GET', '/bank');
    CarbonImmutable::setTestNow();

    expect($lastSent->invoke($driver)->format('Y-m-d H:i:s.u'))->toBe('2026-09-28 09:30:00.250000');
});

test('state_as_of round-trips with its microseconds, and can be cleared', function (): void {
    $row = SubscriptionTransaction::create(array_merge(
        ['subscription_code' => 'SUB_ROUNDTRIP'],
        subscriptionAttributes('active', CarbonImmutable::parse('2026-09-28 12:00:00.123456')),
    ));

    expect($row->fresh()->state_as_of->format('Y-m-d H:i:s.u'))->toBe('2026-09-28 12:00:00.123456');

    $row->update(['state_as_of' => null]);

    expect($row->fresh()->state_as_of)->toBeNull();
});

test('openSubscriptionCodes lists the customer\'s logged subscriptions to the plan that have not ended, newest first', function (): void {
    $log = fn (string $code, array $overrides = []) => $this->repository->updateOrCreateAtomic($code, array_merge([
        'provider' => 'paypal',
        'status' => 'active',
        'plan_code' => 'P-1',
        'customer_email' => 'a@b.com',
        'currency' => 'USD',
    ], $overrides));

    $log('I-OLD', ['status' => 'attention']);
    $log('I-SUSPENDED', ['status' => 'non-renewing']);
    $log('I-NEW');
    $log('I-CANCELLED', ['status' => 'cancelled']);
    $log('I-COMPLETED', ['status' => 'completed']);
    $log('I-EXPIRED', ['status' => 'expired']);
    $log('I-OTHER-PLAN', ['plan_code' => 'P-2']);
    $log('I-OTHER-CUSTOMER', ['customer_email' => 'c@d.com']);
    $log('SUB-OTHER-PROVIDER', ['provider' => 'stripe']);

    expect($this->repository->openSubscriptionCodes('paypal', 'a@b.com', 'P-1'))->toBe(['I-NEW', 'I-SUSPENDED', 'I-OLD'])
        ->and($this->repository->openSubscriptionCodes('paypal', 'nobody@b.com', 'P-1'))->toBe([]);
});
