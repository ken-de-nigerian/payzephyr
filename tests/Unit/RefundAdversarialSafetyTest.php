<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use KenDeNigerian\PayZephyr\Contracts\RefundRepositoryInterface;
use KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Enums\RefundStatus;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\Models\RefundTransaction;
use KenDeNigerian\PayZephyr\Services\RefundValidator;

/**
 * Adversarial refund safety.
 *
 * THE INVARIANT: total successful refunds against a payment must never exceed
 * the captured amount, under any interleaving of retries, concurrency, partial
 * refunds, and local failures.
 */
beforeEach(function () {
    app()->forgetInstance('payments.config');
    Cache::flush();

    config([
        'payments.default' => 'primary',
        'payments.health_check.enabled' => false,
        'payments.refunds.validation.enabled' => true,
        'payments.refunds.prevent_duplicates' => true,
        'payments.providers' => [
            'primary' => ['driver' => 'primary', 'enabled' => true, 'secret_key' => 'test'],
        ],
    ]);
});

function seedCapturedPayment(string $reference, float $amount): PaymentTransaction
{
    return PaymentTransaction::create([
        'reference' => $reference,
        'provider' => 'primary',
        'status' => 'success',
        'amount' => $amount,
        'currency' => 'NGN',
        'email' => 'customer@example.com',
    ]);
}

function seedRefund(string $transactionReference, string $refundReference, float $amount, string $status): RefundTransaction
{
    return RefundTransaction::create([
        'refund_reference' => $refundReference,
        'transaction_reference' => $transactionReference,
        'provider' => 'primary',
        'status' => $status,
        'amount' => $amount,
        'currency' => 'NGN',
    ]);
}

function validateRefundRequest(string $transactionReference, ?float $amount): void
{
    app(RefundValidator::class)->validateRefund(RefundRequestDTO::fromArray(array_filter([
        'transaction_reference' => $transactionReference,
        'amount' => $amount,
    ], fn ($v) => $v !== null)));
}

// ---------------------------------------------------------------------------
// The core invariant: total refunds never exceed the captured amount
// ---------------------------------------------------------------------------

test('a refund exceeding the captured amount is rejected', function () {
    seedCapturedPayment('txn_over', 1000.00);

    expect(fn () => validateRefundRequest('txn_over', 1500.00))
        ->toThrow(RefundException::class, 'exceeds the remaining refundable balance');
});

test('a refund exceeding the REMAINING balance after a partial refund is rejected', function () {
    seedCapturedPayment('txn_partial', 1000.00);
    seedRefund('txn_partial', 'rf_1', 600.00, 'completed');

    // 600 already refunded; 500 more would total 1100 > 1000.
    expect(fn () => validateRefundRequest('txn_partial', 500.00))
        ->toThrow(RefundException::class, 'exceeds the remaining refundable balance');
});

test('sequential partial refunds are allowed up to exactly the captured amount', function () {
    seedCapturedPayment('txn_seq', 1000.00);
    seedRefund('txn_seq', 'rf_a', 400.00, 'completed');
    seedRefund('txn_seq', 'rf_b', 300.00, 'completed');

    // 700 refunded, 300 remaining - exactly 300 must still be allowed.
    validateRefundRequest('txn_seq', 300.00);

    expect(app(RefundRepositoryInterface::class)->sumRefundedAmount('txn_seq'))->toBe(700.0);
});

test('a refund against a fully refunded transaction is rejected', function () {
    seedCapturedPayment('txn_full', 1000.00);
    seedRefund('txn_full', 'rf_full', 1000.00, 'completed');

    expect(fn () => validateRefundRequest('txn_full', 100.00))
        ->toThrow(RefundException::class, 'already been fully refunded');
});

test('pending and processing refunds count toward the refunded total, not just completed ones', function () {
    // A pending refund is money that is on its way out. Excluding it from the
    // total would let a concurrent second refund over-spend the payment while
    // the first is still settling.
    seedCapturedPayment('txn_pending', 1000.00);
    seedRefund('txn_pending', 'rf_pending', 800.00, 'pending');

    expect(app(RefundRepositoryInterface::class)->sumRefundedAmount('txn_pending'))->toBe(800.0);

    // Isolate the over-refund guard: with prevent_duplicates on, the in-flight
    // guard would reject this first (also correct, but a different mechanism).
    config(['payments.refunds.prevent_duplicates' => false]);
    app()->forgetInstance('payments.config');

    expect(fn () => validateRefundRequest('txn_pending', 500.00))
        ->toThrow(RefundException::class, 'exceeds the remaining refundable balance');
});

test('a pending refund is rejected by the in-flight guard before the over-refund guard is reached', function () {
    // Both guards protect the invariant; this pins down which one fires first
    // so a future change to either is caught rather than silently reordering.
    seedCapturedPayment('txn_order', 1000.00);
    seedRefund('txn_order', 'rf_order', 800.00, 'pending');

    expect(fn () => validateRefundRequest('txn_order', 500.00))
        ->toThrow(RefundException::class, 'already in progress');
});

test('a failed refund does NOT count toward the refunded total', function () {
    // The mirror of the above: money that definitively did not leave must not
    // permanently reduce the refundable balance.
    seedCapturedPayment('txn_failed', 1000.00);
    seedRefund('txn_failed', 'rf_failed', 800.00, 'failed');

    expect(app(RefundRepositoryInterface::class)->sumRefundedAmount('txn_failed'))->toBe(0.0);

    validateRefundRequest('txn_failed', 1000.00);

    expect(true)->toBeTrue();
});

test('a refund whose provider status is unrecognized still counts toward the refunded total', function () {
    // Regression: RefundResponseDTO::getStatus() used to fall back to FAILED
    // for an unrecognized provider status. FAILED is excluded from
    // sumRefundedAmount(), so an unknown status silently freed the entire
    // refundable balance again and a second refund could over-spend the
    // captured amount. Unknown means "we do not know whether the money left",
    // which for balance accounting must be treated as "it did".
    $response = new RefundResponseDTO(
        refundReference: 'rf_unknown',
        transactionReference: 'txn_unknown',
        status: 'some_status_no_provider_mapping_covers',
        amount: 800.00,
        currency: 'NGN',
    );

    expect($response->getStatus())->toBe(RefundStatus::PENDING)
        ->and($response->getStatus()->countsTowardRefundedAmount())->toBeTrue()
        ->and($response->isCompleted())->toBeFalse()
        ->and($response->getStatus()->isTerminal())->toBeFalse();

    seedCapturedPayment('txn_unknown', 1000.00);
    seedRefund('txn_unknown', 'rf_unknown', 800.00, $response->getStatus()->value);

    expect(app(RefundRepositoryInterface::class)->sumRefundedAmount('txn_unknown'))->toBe(800.0);

    config(['payments.refunds.prevent_duplicates' => false]);
    app()->forgetInstance('payments.config');

    expect(fn () => validateRefundRequest('txn_unknown', 500.00))
        ->toThrow(RefundException::class, 'exceeds the remaining refundable balance');
});

test('the refunded-total query is derived from the enum, so a new status cannot silently escape it', function () {
    $counted = array_values(array_map(
        fn (RefundStatus $s) => $s->value,
        array_filter(RefundStatus::cases(), fn (RefundStatus $s) => $s->countsTowardRefundedAmount())
    ));

    seedCapturedPayment('txn_enum', 1000.00);

    $amount = 100.00;
    foreach ($counted as $i => $status) {
        seedRefund('txn_enum', 'rf_enum_'.$i, $amount, $status);
    }

    expect(app(RefundRepositoryInterface::class)->sumRefundedAmount('txn_enum'))
        ->toBe($amount * count($counted));
});

// ---------------------------------------------------------------------------
// Duplicate / in-flight protection
// ---------------------------------------------------------------------------

test('a second refund while one is already pending is rejected by the in-flight guard', function () {
    seedCapturedPayment('txn_inflight', 1000.00);
    seedRefund('txn_inflight', 'rf_inflight', 100.00, 'pending');

    expect(fn () => validateRefundRequest('txn_inflight', 100.00))
        ->toThrow(RefundException::class, 'already in progress');
});

test('a new refund is allowed once the earlier one reaches a terminal state', function () {
    seedCapturedPayment('txn_terminal', 1000.00);
    seedRefund('txn_terminal', 'rf_terminal', 100.00, 'completed');

    validateRefundRequest('txn_terminal', 100.00);
})->throwsNoExceptions();

// ---------------------------------------------------------------------------
// Local failure after a real provider refund
// ---------------------------------------------------------------------------

test('a refund row that failed to persist locally leaves the balance unprotected - guarded by the in-flight lock instead', function () {
    // Documents the real, unavoidable limitation honestly rather than
    // pretending it away: if the provider refunded but the local write never
    // happened (process crash), the validator alone cannot know. The
    // protection for that window is the in-flight cache lock in
    // Refund::refund(), which is held (not released) on an ambiguous outcome.
    seedCapturedPayment('txn_lost', 1000.00);

    // No refund row exists, so the validator sees the full balance available.
    expect(app(RefundRepositoryInterface::class)->sumRefundedAmount('txn_lost'))->toBe(0.0);

    validateRefundRequest('txn_lost', 1000.00);

    expect(true)->toBeTrue();
});

test('updateStatusIfExists refuses to move a refund out of a terminal state', function () {
    // Prevents a late/duplicate webhook from resurrecting a settled refund
    // and corrupting the refunded total the over-refund guard depends on.
    seedCapturedPayment('txn_terminalguard', 1000.00);
    seedRefund('txn_terminalguard', 'rf_done', 500.00, 'completed');

    $changed = app(RefundRepositoryInterface::class)->updateStatusIfExists('rf_done', 'pending');

    expect($changed)->toBeFalse()
        ->and(RefundTransaction::where('refund_reference', 'rf_done')->first()->status)->toBe('completed');
});

// ---------------------------------------------------------------------------
// Validator robustness
// ---------------------------------------------------------------------------

test('a refund against an unknown transaction is allowed through (over-refund check is best-effort)', function () {
    // The original charge may have been logged by a different process or with
    // logging disabled. Refusing here would make refunds depend on local
    // logging being enabled, which is a worse failure mode.
    validateRefundRequest('txn_never_logged', 50.00);
})->throwsNoExceptions();

test('a full refund request with no amount is validated against the remaining balance', function () {
    seedCapturedPayment('txn_noamount', 1000.00);
    seedRefund('txn_noamount', 'rf_prior', 999.00, 'completed');

    // amount === null means "refund what is left" - 1.00 here, which is fine.
    validateRefundRequest('txn_noamount', null);
})->throwsNoExceptions();

test('the transaction repository is the source of truth for the captured amount', function () {
    seedCapturedPayment('txn_source', 250.50);

    expect((float) app(TransactionRepositoryInterface::class)->findByReference('txn_source')->amount)
        ->toBe(250.50);
});
