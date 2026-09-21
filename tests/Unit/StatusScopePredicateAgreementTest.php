<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use KenDeNigerian\PayZephyr\Enums\PaymentStatus;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;

uses(RefreshDatabase::class);

/**
 * A query scope and its matching predicate must never disagree about a row.
 *
 * They used to. `scopeSuccessful()` matched a hand-maintained list of five
 * strings while `isSuccessful()` asked the normalizer, so a transaction stored
 * as `captured`, `overpaid`, `paidout` or `complete` answered true to the
 * predicate and was invisible to the scope. A merchant reconciling with
 * `PaymentTransaction::successful()->sum('amount')` silently under-counted
 * revenue, and the gap widened every time a provider vocabulary was added -
 * `captured` arrived with Razorpay, `overpaid` and `paidout` with Mollie.
 *
 * Both sides now derive from StatusNormalizer, and this test is what keeps
 * them derived. It reads the vocabulary rather than restating it, so a new
 * provider status is covered the moment it is registered.
 */
function storeTransactionWithStatus(string $status, int $i): void
{
    PaymentTransaction::create([
        'reference' => "ref_{$i}_{$status}",
        'provider' => 'paystack',
        'status' => $status,
        'amount' => 1000,
        'currency' => 'NGN',
        'email' => 'a@b.test',
    ]);
}

test('every status the normalizer understands is matched by both the scope and the predicate', function (
    string $normalized,
    string $scope,
    string $predicate,
) {
    $vocabulary = (new StatusNormalizer)->statusesNormalizingTo($normalized);

    expect($vocabulary)->not->toBeEmpty();

    foreach (array_values($vocabulary) as $i => $status) {
        storeTransactionWithStatus($status, $i);
    }

    $foundByScope = PaymentTransaction::query()->{$scope}()->pluck('status')->all();

    $disagreements = [];

    foreach (PaymentTransaction::all() as $transaction) {
        $inScope = in_array($transaction->status, $foundByScope, true);

        if ($inScope !== $transaction->{$predicate}()) {
            $disagreements[] = $transaction->status;
        }
    }

    expect($disagreements)->toBe([])
        ->and($foundByScope)->toHaveCount(count($vocabulary));
})->with([
    'successful' => [PaymentStatus::SUCCESS->value, 'successful', 'isSuccessful'],
    'failed' => [PaymentStatus::FAILED->value, 'failed', 'isFailed'],
    'pending' => [PaymentStatus::PENDING->value, 'pending', 'isPending'],
]);

test('the statuses that regressed before are all covered now', function (string $status) {
    // Named explicitly as well as derived, so the specific bug stays pinned
    // even if someone changes how the vocabulary is assembled.
    storeTransactionWithStatus($status, 0);

    expect(PaymentTransaction::successful()->pluck('status')->all())->toBe([$status])
        ->and(PaymentTransaction::first()->isSuccessful())->toBeTrue();
})->with(['captured', 'overpaid', 'paidout', 'complete']);

test('a cancelled transaction is still found by the failed scope', function () {
    // Cancelled is its own PaymentStatus but has always been reported by
    // scopeFailed(); keeping that is deliberate, not accidental.
    storeTransactionWithStatus(PaymentStatus::CANCELLED->value, 0);

    expect(PaymentTransaction::failed()->count())->toBe(1);
});

test('a status no provider claims is matched by no scope and no predicate', function () {
    storeTransactionWithStatus('teapot', 0);

    $transaction = PaymentTransaction::first();

    expect($transaction->isSuccessful())->toBeFalse()
        ->and($transaction->isFailed())->toBeFalse()
        ->and($transaction->isPending())->toBeFalse()
        ->and(PaymentTransaction::successful()->count())->toBe(0)
        ->and(PaymentTransaction::failed()->count())->toBe(0)
        ->and(PaymentTransaction::pending()->count())->toBe(0);
});

test('a predicate reads the vocabulary of the provider that wrote the row', function () {
    // Drivers normalize before persisting, so this is the uncommon path - a
    // row written directly by the application, or stored before a provider
    // vocabulary was registered. It still must not answer "none of the above".
    //
    // `billed` is Paddle's, and means pending. The same string means nothing
    // to Paystack, which is the point: the answer depends on the provider
    // column, not on the status string alone.
    PaymentTransaction::create([
        'reference' => 'ref_paddle', 'provider' => 'paddle', 'status' => 'billed',
        'amount' => 1000, 'currency' => 'USD', 'email' => 'a@b.test',
    ]);
    PaymentTransaction::create([
        'reference' => 'ref_paystack', 'provider' => 'paystack', 'status' => 'billed',
        'amount' => 1000, 'currency' => 'NGN', 'email' => 'a@b.test',
    ]);

    $paddle = PaymentTransaction::where('reference', 'ref_paddle')->first();
    $paystack = PaymentTransaction::where('reference', 'ref_paystack')->first();

    expect($paddle->isPending())->toBeTrue()
        ->and($paystack->isPending())->toBeFalse();
});

test('an ambiguous status is read differently for each provider that claims it', function () {
    // APPROVED is success for Square and pending for PayPal. This is exactly
    // why the query scopes use only the provider-agnostic vocabulary: at SQL
    // level the string alone cannot answer the question.
    PaymentTransaction::create([
        'reference' => 'ref_square', 'provider' => 'square', 'status' => 'approved',
        'amount' => 1000, 'currency' => 'USD', 'email' => 'a@b.test',
    ]);
    PaymentTransaction::create([
        'reference' => 'ref_paypal', 'provider' => 'paypal', 'status' => 'approved',
        'amount' => 1000, 'currency' => 'USD', 'email' => 'a@b.test',
    ]);

    expect(PaymentTransaction::where('reference', 'ref_square')->first()->isSuccessful())->toBeTrue()
        ->and(PaymentTransaction::where('reference', 'ref_paypal')->first()->isPending())->toBeTrue();
});
