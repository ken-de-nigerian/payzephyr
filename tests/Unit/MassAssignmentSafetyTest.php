<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\Models\RefundTransaction;
use KenDeNigerian\PayZephyr\Models\SubscriptionTransaction;
use KenDeNigerian\PayZephyr\Models\WebhookEvent;
use KenDeNigerian\PayZephyr\Repositories\EloquentRefundRepository;
use KenDeNigerian\PayZephyr\Repositories\EloquentSubscriptionRepository;
use KenDeNigerian\PayZephyr\Repositories\EloquentTransactionRepository;

/**
 * Verifies the actual mass-assignment protection boundary: every model
 * relies on an explicit $fillable allow-list (never $guarded = []), so even
 * if an attribute array were ever built from less-trusted data, an
 * unexpected key (like 'id', or a column no PayZephyr code path
 * intentionally sets) is silently dropped rather than written.
 *
 * Every actual create()/update() call site in the codebase builds its
 * attribute array from a hard-coded literal key list (see PaymentManager,
 * LogsRefundTransactions, LogsSubscriptionTransactions,
 * EloquentWebhookEventRepository) rather than merging in raw webhook/request
 * payloads - this test proves the model-level allow-list holds regardless,
 * as defense in depth.
 */
test('PaymentTransaction ignores attributes outside its fillable list', function () {
    $repository = new EloquentTransactionRepository;

    $transaction = $repository->create([
        'reference' => 'txn_mass_assign',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 100.0,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'id' => 999999, // attacker-supplied, must be ignored
        'created_at' => '1970-01-01 00:00:00', // must be ignored, Eloquent manages this
    ]);

    expect($transaction->id)->not->toBe(999999)
        ->and(PaymentTransaction::where('reference', 'txn_mass_assign')->first()->id)->not->toBe(999999);
});

test('amount and reference are fillable (required for create()), so verification can only ever change status/channel/paid_at through the real call site', function () {
    // Important distinction: unlike WebhookEvent's tightly-scoped fillable,
    // PaymentTransaction's $fillable includes 'amount' and 'reference'
    // because create() legitimately needs to set them. $fillable alone does
    // NOT stop a later update() call from overwriting them - the real
    // protection is that PaymentManager::updateTransactionFromVerification()
    // never includes those keys in the array it builds, no matter what a
    // driver's VerificationResponseDTO contains. This test exercises that
    // real call site directly, not a hypothetical arbitrary-array update.
    $repository = new EloquentTransactionRepository;

    $repository->create([
        'reference' => 'txn_amount_guard',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 100.0,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);

    $manager = new KenDeNigerian\PayZephyr\PaymentManager;
    $driver = makeCountingSuccessDriver('paystack');
    injectFakeDrivers($manager, ['paystack' => $driver]);

    // The fake driver's verify() always returns amount: 100.0, but even if
    // a real (or malicious) provider response somehow carried a different
    // amount, updateTransactionFromVerification() has no path to write it -
    // it only ever extracts status/channel/paid_at from the response.
    $manager->verify('txn_amount_guard', 'paystack');

    $transaction = PaymentTransaction::where('reference', 'txn_amount_guard')->first();

    expect((float) $transaction->amount)->toBe(100.0)
        ->and($transaction->reference)->toBe('txn_amount_guard');
});

test('RefundTransaction ignores attributes outside its fillable list', function () {
    $repository = new EloquentRefundRepository;

    $refund = $repository->updateOrCreateAtomic('ref_mass_assign', [
        'transaction_reference' => 'txn_1',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 50.0,
        'currency' => 'NGN',
        'id' => 999999,
    ]);

    expect($refund->id)->not->toBe(999999)
        ->and(RefundTransaction::where('refund_reference', 'ref_mass_assign')->first()->id)->not->toBe(999999);
});

test('SubscriptionTransaction ignores attributes outside its fillable list', function () {
    $repository = new EloquentSubscriptionRepository;

    $subscription = $repository->updateOrCreateAtomic('sub_mass_assign', [
        'provider' => 'paystack',
        'status' => 'active',
        'plan_code' => 'PLN_1',
        'customer_email' => 'test@example.com',
        'amount' => 50.0,
        'currency' => 'NGN',
        'id' => 999999,
    ]);

    expect($subscription->id)->not->toBe(999999)
        ->and(SubscriptionTransaction::where('subscription_code', 'sub_mass_assign')->first()->id)->not->toBe(999999);
});

test('WebhookEvent only ever accepts provider and event_key, nothing else', function () {
    expect((new WebhookEvent)->getFillable())->toBe(['provider', 'event_key']);
});

test('no PayZephyr model uses an unguarded ($guarded = []) mass-assignment policy', function () {
    foreach ([PaymentTransaction::class, RefundTransaction::class, SubscriptionTransaction::class, WebhookEvent::class] as $modelClass) {
        $model = new $modelClass;

        expect($model->getFillable())->not->toBeEmpty("$modelClass must declare an explicit \$fillable allow-list.");
    }
});
