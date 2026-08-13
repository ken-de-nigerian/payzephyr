<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use KenDeNigerian\PayZephyr\Models\RefundTransaction;

function refundTransactionData(array $overrides = []): array
{
    return array_merge([
        'refund_reference' => 'REF_'.uniqid(),
        'transaction_reference' => 'TXN_123',
        'provider' => 'paystack',
        'status' => 'pending',
        'amount' => 5000,
        'currency' => 'NGN',
    ], $overrides);
}

test('it uses the configured refunds table name', function () {
    app()->forgetInstance('payments.config');

    config(['payments.refunds.logging.table' => 'custom_refund_transactions']);

    $model = new RefundTransaction;

    expect($model->getTable())->toBe('custom_refund_transactions');
});

test('it defaults to refund_transactions table if config is missing', function () {
    app()->forgetInstance('payments.config');
    config(['payments.refunds.logging.table' => null]);

    $model = new RefundTransaction;

    expect($model->getTable())->toBe('refund_transactions');
});

test('it casts attributes correctly', function () {
    $transaction = RefundTransaction::create(refundTransactionData([
        'amount' => 5000.50,
        'metadata' => ['note' => 'customer request'],
    ]));

    $amount = $transaction->amount;
    expect(is_string($amount) ? $amount : (string) number_format((float) $amount, 2, '.', ''))->toBe('5000.50')
        ->and($transaction->created_at)->toBeInstanceOf(Carbon::class)
        ->and($transaction->metadata)->toBeInstanceOf(ArrayObject::class)
        ->and($transaction->metadata['note'])->toBe('customer request');
});

test('it uses the testing connection while running under the testing environment', function () {
    $model = new RefundTransaction;

    expect($model->getConnectionName())->toBe('testing');
});

test('scope pending filters pending and processing refunds', function () {
    RefundTransaction::create(refundTransactionData(['status' => 'pending']));
    RefundTransaction::create(refundTransactionData(['status' => 'processing']));
    RefundTransaction::create(refundTransactionData(['status' => 'completed']));

    $pending = RefundTransaction::pending()->get();

    expect($pending)->toHaveCount(2)
        ->and($pending->pluck('status')->toArray())->toEqualCanonicalizing(['pending', 'processing']);
});

test('scope completed filters only completed refunds', function () {
    RefundTransaction::create(refundTransactionData(['status' => 'completed']));
    RefundTransaction::create(refundTransactionData(['status' => 'pending']));

    $completed = RefundTransaction::completed()->get();

    expect($completed)->toHaveCount(1)
        ->and($completed->first()->status)->toBe('completed');
});

test('scope forTransaction filters by transaction reference', function () {
    RefundTransaction::create(refundTransactionData(['transaction_reference' => 'TXN_A']));
    RefundTransaction::create(refundTransactionData(['transaction_reference' => 'TXN_B']));

    $result = RefundTransaction::forTransaction('TXN_A')->get();

    expect($result)->toHaveCount(1)
        ->and($result->first()->transaction_reference)->toBe('TXN_A');
});

test('scopes can be chained together', function () {
    RefundTransaction::create(refundTransactionData(['status' => 'pending', 'transaction_reference' => 'TXN_COMBO']));
    RefundTransaction::create(refundTransactionData(['status' => 'completed', 'transaction_reference' => 'TXN_COMBO']));

    $result = RefundTransaction::pending()->forTransaction('TXN_COMBO')->get();

    expect($result)->toHaveCount(1)
        ->and($result->first()->status)->toBe('pending');
});
