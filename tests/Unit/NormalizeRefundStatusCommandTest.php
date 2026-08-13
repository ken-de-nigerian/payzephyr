<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use KenDeNigerian\PayZephyr\Console\NormalizeRefundStatusCommand;
use KenDeNigerian\PayZephyr\Models\RefundTransaction;

test('normalize-status command is registered', function () {
    expect(Artisan::all())->toHaveKey('payzephyr:refunds:normalize-status');
});

test('normalizes raw uppercase provider statuses to canonical values', function () {
    RefundTransaction::create([
        'refund_reference' => 'REF_UPPER', 'transaction_reference' => 'TXN_1',
        'provider' => 'square', 'status' => 'PENDING', 'amount' => 100, 'currency' => 'USD',
    ]);
    RefundTransaction::create([
        'refund_reference' => 'REF_WORD', 'transaction_reference' => 'TXN_2',
        'provider' => 'paystack', 'status' => 'processed', 'amount' => 200, 'currency' => 'NGN',
    ]);
    RefundTransaction::create([
        'refund_reference' => 'REF_ALREADY_CANONICAL', 'transaction_reference' => 'TXN_3',
        'provider' => 'stripe', 'status' => 'completed', 'amount' => 300, 'currency' => 'USD',
    ]);

    $exitCode = Artisan::call('payzephyr:refunds:normalize-status');

    expect($exitCode)->toBe(NormalizeRefundStatusCommand::SUCCESS)
        ->and(RefundTransaction::where('refund_reference', 'REF_UPPER')->first()->status)->toBe('pending')
        ->and(RefundTransaction::where('refund_reference', 'REF_WORD')->first()->status)->toBe('completed')
        ->and(RefundTransaction::where('refund_reference', 'REF_ALREADY_CANONICAL')->first()->status)->toBe('completed');
});

test('--dry-run reports changes without writing them', function () {
    RefundTransaction::create([
        'refund_reference' => 'REF_DRY', 'transaction_reference' => 'TXN_1',
        'provider' => 'square', 'status' => 'COMPLETED', 'amount' => 100, 'currency' => 'USD',
    ]);

    Artisan::call('payzephyr:refunds:normalize-status', ['--dry-run' => true]);

    expect(Artisan::output())->toContain('Dry run')
        ->and(RefundTransaction::where('refund_reference', 'REF_DRY')->first()->status)->toBe('COMPLETED');
});

test('an unmappable status is reported and left untouched, not guessed at', function () {
    RefundTransaction::create([
        'refund_reference' => 'REF_UNKNOWN', 'transaction_reference' => 'TXN_1',
        'provider' => 'stripe', 'status' => 'some_future_status', 'amount' => 100, 'currency' => 'USD',
    ]);

    Artisan::call('payzephyr:refunds:normalize-status');

    expect(Artisan::output())->toContain('cannot be mapped')
        ->and(RefundTransaction::where('refund_reference', 'REF_UNKNOWN')->first()->status)->toBe('some_future_status');
});

test('running it twice is idempotent', function () {
    RefundTransaction::create([
        'refund_reference' => 'REF_TWICE', 'transaction_reference' => 'TXN_1',
        'provider' => 'monnify', 'status' => 'PENDING', 'amount' => 100, 'currency' => 'NGN',
    ]);

    Artisan::call('payzephyr:refunds:normalize-status');
    $afterFirst = RefundTransaction::where('refund_reference', 'REF_TWICE')->first()->status;

    Artisan::call('payzephyr:refunds:normalize-status');

    expect($afterFirst)->toBe('pending')
        ->and(Artisan::output())->toContain('nothing to do');
});

test('reports nothing to do when the refund_transactions table has no rows', function () {
    $exitCode = Artisan::call('payzephyr:refunds:normalize-status');

    expect($exitCode)->toBe(NormalizeRefundStatusCommand::SUCCESS)
        ->and(Artisan::output())->toContain('nothing to do');
});
