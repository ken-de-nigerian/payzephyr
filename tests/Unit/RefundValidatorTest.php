<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Contracts\RefundRepositoryInterface;
use KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\Services\RefundValidator;

function makeRefundValidator(
    ?PaymentTransaction $transaction,
    float $alreadyRefunded = 0.0,
    bool $lookupThrows = false,
    bool $hasInFlightRefund = false,
): RefundValidator {
    $transactionRepository = Mockery::mock(TransactionRepositoryInterface::class);

    if ($lookupThrows) {
        $transactionRepository->shouldReceive('findByReference')->andThrow(new RuntimeException('db unavailable'));
    } else {
        $transactionRepository->shouldReceive('findByReference')->andReturn($transaction);
    }

    $refundRepository = Mockery::mock(RefundRepositoryInterface::class);
    $refundRepository->shouldReceive('sumRefundedAmount')->andReturn($alreadyRefunded);
    $refundRepository->shouldReceive('hasInFlightRefund')->andReturn($hasInFlightRefund);

    return new RefundValidator($refundRepository, $transactionRepository);
}

afterEach(function () {
    app()->forgetInstance('payments.config');
});

test('validateRefund passes silently when the original transaction cannot be found', function () {
    $validator = makeRefundValidator(null);

    $validator->validateRefund(new RefundRequestDTO(transactionReference: 'txn_unknown', amount: 100.0));

    expect(true)->toBeTrue();
});

test('validateRefund passes silently when the transaction lookup itself throws', function () {
    $validator = makeRefundValidator(null, lookupThrows: true);

    $validator->validateRefund(new RefundRequestDTO(transactionReference: 'txn_unknown', amount: 100.0));

    expect(true)->toBeTrue();
});

test('validateRefund allows a full refund within the remaining balance', function () {
    $transaction = new PaymentTransaction(['amount' => 100.0]);
    $validator = makeRefundValidator($transaction, alreadyRefunded: 0.0);

    $validator->validateRefund(new RefundRequestDTO(transactionReference: 'txn_1'));

    expect(true)->toBeTrue();
});

test('validateRefund allows a partial refund within the remaining balance', function () {
    $transaction = new PaymentTransaction(['amount' => 100.0]);
    $validator = makeRefundValidator($transaction, alreadyRefunded: 30.0);

    $validator->validateRefund(new RefundRequestDTO(transactionReference: 'txn_1', amount: 50.0));

    expect(true)->toBeTrue();
});

test('validateRefund rejects a refund exceeding the remaining balance', function () {
    $transaction = new PaymentTransaction(['amount' => 100.0]);
    $validator = makeRefundValidator($transaction, alreadyRefunded: 30.0);

    $validator->validateRefund(new RefundRequestDTO(transactionReference: 'txn_1', amount: 90.0));
})->throws(RefundException::class, 'exceeds the remaining refundable balance');

test('validateRefund rejects any refund once the transaction is already fully refunded', function () {
    $transaction = new PaymentTransaction(['amount' => 100.0]);
    $validator = makeRefundValidator($transaction, alreadyRefunded: 100.0);

    $validator->validateRefund(new RefundRequestDTO(transactionReference: 'txn_1', amount: 1.0));
})->throws(RefundException::class, 'already been fully refunded');

test('validateRefund rejects a second refund attempt while an earlier one is still in flight, by default', function () {
    // Guards against a double-clicked "refund" button or an accidental
    // duplicate ->refund() call firing two requests for the same
    // transaction before the first one's webhook confirms - independent of
    // the amount math, since the first attempt's final amount isn't known
    // yet. config('payments.refunds.prevent_duplicates') defaults to true.
    $transaction = new PaymentTransaction(['amount' => 100.0]);
    $validator = makeRefundValidator($transaction, alreadyRefunded: 0.0, hasInFlightRefund: true);

    $validator->validateRefund(new RefundRequestDTO(transactionReference: 'txn_1', amount: 50.0));
})->throws(RefundException::class, 'already in progress');

test('validateRefund allows a new refund once prior refunds on the transaction have all resolved', function () {
    // hasInFlightRefund() only reports true for pending/processing rows -
    // once a prior refund is completed/failed/cancelled, a genuinely new
    // (sequential, not concurrent) partial refund must still be allowed.
    $transaction = new PaymentTransaction(['amount' => 100.0]);
    $validator = makeRefundValidator($transaction, alreadyRefunded: 30.0, hasInFlightRefund: false);

    $validator->validateRefund(new RefundRequestDTO(transactionReference: 'txn_1', amount: 20.0));

    expect(true)->toBeTrue();
});

test('validateRefund skips the in-flight duplicate guard when prevent_duplicates is disabled', function () {
    app()->forgetInstance('payments.config');
    config(['payments.refunds.prevent_duplicates' => false]);

    $transaction = new PaymentTransaction(['amount' => 100.0]);
    $validator = makeRefundValidator($transaction, alreadyRefunded: 0.0, hasInFlightRefund: true);

    $validator->validateRefund(new RefundRequestDTO(transactionReference: 'txn_1', amount: 50.0));

    expect(true)->toBeTrue();
});
