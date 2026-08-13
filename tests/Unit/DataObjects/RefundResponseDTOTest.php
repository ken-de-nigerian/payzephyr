<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Enums\RefundStatus;

function makeRefundResponseDTO(string $status): RefundResponseDTO
{
    return new RefundResponseDTO(
        refundReference: 'RE_1',
        transactionReference: 'TXN_1',
        status: $status,
        amount: 50.0,
        currency: 'USD',
    );
}

test('isCompleted is true only for a completed-shaped status', function () {
    expect(makeRefundResponseDTO('completed')->isCompleted())->toBeTrue()
        ->and(makeRefundResponseDTO('succeeded')->isCompleted())->toBeTrue()
        ->and(makeRefundResponseDTO('processed')->isCompleted())->toBeTrue()
        ->and(makeRefundResponseDTO('pending')->isCompleted())->toBeFalse()
        ->and(makeRefundResponseDTO('failed')->isCompleted())->toBeFalse();
});

test('isPending is true for pending and processing statuses only', function () {
    expect(makeRefundResponseDTO('pending')->isPending())->toBeTrue()
        ->and(makeRefundResponseDTO('processing')->isPending())->toBeTrue()
        ->and(makeRefundResponseDTO('completed')->isPending())->toBeFalse()
        ->and(makeRefundResponseDTO('failed')->isPending())->toBeFalse();
});

test('isFailed is true for failed and cancelled statuses', function () {
    expect(makeRefundResponseDTO('failed')->isFailed())->toBeTrue()
        ->and(makeRefundResponseDTO('declined')->isFailed())->toBeTrue()
        ->and(makeRefundResponseDTO('cancelled')->isFailed())->toBeTrue()
        ->and(makeRefundResponseDTO('completed')->isFailed())->toBeFalse();
});

test('isFailed recognizes Square\'s "rejected" refund status', function () {
    // Verified against Square's Refunds API docs: a PaymentRefund's status
    // can be PENDING, COMPLETED, REJECTED, or FAILED - "rejected" wasn't in
    // RefundStatus::fromString()'s failed-alias list, so it previously only
    // ended up FAILED via the unrecognized-status fallback rather than
    // explicit recognition.
    expect(makeRefundResponseDTO('rejected')->isFailed())->toBeTrue()
        ->and(makeRefundResponseDTO('REJECTED')->getStatus())->toBe(RefundStatus::FAILED);
});

test('an unrecognized provider status is never trusted as success, and still counts toward the refunded total', function () {
    // Fail-closed on the question that matters: a status string this package
    // has never seen (a new provider status added after this release) must not
    // be misinterpreted as a completed refund.
    //
    // It falls back to PENDING rather than FAILED, though. FAILED is excluded
    // from RefundStatus::countsTowardRefundedAmount(), which drives the
    // over-refund guard - so treating an unknown status as FAILED silently
    // released the entire refundable balance and let a second refund
    // over-spend the captured amount. "Unknown" means we do not know whether
    // the money left, and for balance accounting the safe assumption is that
    // it did. PENDING is also non-terminal, so a later webhook can still
    // resolve it to the real outcome.
    $response = makeRefundResponseDTO('some_brand_new_provider_status_2027');

    expect($response->getStatus())->toBe(RefundStatus::PENDING)
        ->and($response->isCompleted())->toBeFalse()
        ->and($response->isFailed())->toBeFalse()
        ->and($response->getStatus()->countsTowardRefundedAmount())->toBeTrue()
        ->and($response->getStatus()->isTerminal())->toBeFalse();
});

test('getStatus is case-insensitive and trims whitespace', function () {
    expect(makeRefundResponseDTO('  COMPLETED  ')->getStatus())->toBe(RefundStatus::COMPLETED);
});

test('fromArray maps snake_case provider fields', function () {
    $response = RefundResponseDTO::fromArray([
        'refund_reference' => 'RE_2',
        'transaction_reference' => 'TXN_2',
        'status' => 'pending',
        'amount' => 25.5,
        'currency' => 'EUR',
        'reason' => 'duplicate',
        'metadata' => ['x' => 1],
        'provider' => 'stripe',
    ]);

    expect($response->refundReference)->toBe('RE_2')
        ->and($response->transactionReference)->toBe('TXN_2')
        ->and($response->amount)->toBe(25.5)
        ->and($response->currency)->toBe('EUR')
        ->and($response->reason)->toBe('duplicate')
        ->and($response->metadata)->toBe(['x' => 1])
        ->and($response->provider)->toBe('stripe');
});

test('fromArray defaults missing fields safely', function () {
    $response = RefundResponseDTO::fromArray([]);

    expect($response->refundReference)->toBe('')
        ->and($response->transactionReference)->toBe('')
        ->and($response->status)->toBe('unknown')
        ->and($response->amount)->toBe(0.0)
        ->and($response->currency)->toBe('NGN')
        ->and($response->reason)->toBeNull()
        ->and($response->metadata)->toBe([])
        ->and($response->provider)->toBeNull();
});

test('an unknown status from fromArray is never reported as completed', function () {
    // fromArray()'s own 'unknown' default hits the same fallback as any
    // unrecognized provider status - not success, and counted toward the
    // refunded total so it cannot free up refundable balance.
    $response = RefundResponseDTO::fromArray(['status' => 'unknown']);

    expect($response->isCompleted())->toBeFalse()
        ->and($response->getStatus()->countsTowardRefundedAmount())->toBeTrue();
});

test('toArray round-trips the snake_case shape used by fromArray', function () {
    $response = makeRefundResponseDTO('completed');

    expect($response->toArray())->toBe([
        'refund_reference' => 'RE_1',
        'transaction_reference' => 'TXN_1',
        'status' => 'completed',
        'amount' => 50.0,
        'currency' => 'USD',
        'reason' => null,
        'metadata' => [],
        'provider' => null,
    ]);
});
