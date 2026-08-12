<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Contracts\RefundRepositoryInterface;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Drivers\AbstractDriver;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Models\RefundTransaction;
use Tests\Helpers\RefundTestHelper;

/**
 * PaystackDriver pulls in LogsRefundTransactions transitively via
 * PaystackRefundMethods, so it's used here to exercise the protected
 * logRefundFromResponse() method directly via reflection.
 */
function makeRefundLoggingDriver(): AbstractDriver
{
    return new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['NGN'],
    ]);
}

function makeRefundResponse(): RefundResponseDTO
{
    return new RefundResponseDTO(
        refundReference: 'REF_LOG_TEST',
        transactionReference: 'TXN_LOG_TEST',
        status: 'pending',
        amount: 5000.0,
        currency: 'NGN',
    );
}

afterEach(function () {
    app()->forgetInstance('payments.config');
});

test('logRefundFromResponse returns early without touching the repository when logging is disabled', function () {
    app()->forgetInstance('payments.config');
    config(['payments.refunds.logging.enabled' => false]);

    $repository = Mockery::mock(RefundRepositoryInterface::class);
    $repository->shouldNotReceive('updateOrCreateAtomic');

    $driver = makeRefundLoggingDriver();
    $driver->setRefundRepository($repository);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('logRefundFromResponse');

    $method->invoke($driver, makeRefundResponse());

    expect(true)->toBeTrue();
});

test('logRefundFromResponse falls back to the top-level logging.enabled flag when refunds.logging is not set', function () {
    app()->forgetInstance('payments.config');
    config([
        'payments.refunds' => [],
        'payments.logging.enabled' => false,
    ]);

    $repository = Mockery::mock(RefundRepositoryInterface::class);
    $repository->shouldNotReceive('updateOrCreateAtomic');

    $driver = makeRefundLoggingDriver();
    $driver->setRefundRepository($repository);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('logRefundFromResponse');

    $method->invoke($driver, makeRefundResponse());

    expect(true)->toBeTrue();
});

test('logRefundFromResponse logs and swallows an exception raised by the repository', function () {
    app()->forgetInstance('payments.config');
    config(['payments.refunds.logging.enabled' => true]);

    $repository = Mockery::mock(RefundRepositoryInterface::class);
    $repository->shouldReceive('updateOrCreateAtomic')
        ->once()
        ->andThrow(new RuntimeException('refund table unavailable'));

    $driver = makeRefundLoggingDriver();
    $driver->setRefundRepository($repository);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('logRefundFromResponse');

    // Should not throw - the exception is caught, logged, and swallowed.
    $method->invoke($driver, makeRefundResponse());

    expect(true)->toBeTrue();
});

test('refund metadata is sanitized before being persisted to refund_transactions', function () {
    // Mirrors the regression this guards against for subscriptions
    // (RELEASE_AUDIT_2026-07-31.md, finding C-3): the assertion must be on
    // the persisted database row, not just the returned DTO's type, or this
    // test would pass identically whether sanitization ever ran.
    $refund = RefundTestHelper::createWithMock([
        RefundTestHelper::refundMock(99999, ['status' => 'pending', 'amount' => 500000]),
    ]);

    $refund->transaction('txn_ref_xss')
        ->metadata([
            'xss' => '<script>alert("xss")</script>',
            'html' => '<img src=x onerror=alert(1)>',
        ])
        ->refund();

    $logged = RefundTransaction::where('transaction_reference', 'txn_ref_xss')->first();

    expect($logged)->not->toBeNull();
    $metadata = $logged->metadata instanceof ArrayObject ? $logged->metadata->getArrayCopy() : (array) $logged->metadata;

    expect($metadata['xss'] ?? '')->not->toContain('<script>')
        ->and($metadata['html'] ?? '')->not->toContain('onerror=');
});

test('refund reason is sanitized before being persisted to refund_transactions', function () {
    // reason is free text supplied via Refund::reason() (e.g. from a
    // merchant's own customer-facing "why are you refunding this" field)
    // and is also frequently echoed back by providers (Paystack
    // customer_note, PayPal note_to_payer) - it must get the same
    // treatment as metadata, not persisted raw. Asserting on the persisted
    // row, not the DTO, per the same C-3 lesson as the metadata test above.
    $refund = RefundTestHelper::createWithMock([
        RefundTestHelper::refundMock(99998, ['status' => 'pending', 'amount' => 500000]),
    ]);

    $refund->transaction('txn_ref_reason_xss')
        ->reason('<script>alert("xss")</script>legit refund reason')
        ->refund();

    $logged = RefundTransaction::where('transaction_reference', 'txn_ref_reason_xss')->first();

    expect($logged)->not->toBeNull()
        ->and($logged->reason)->not->toContain('<script>')
        ->and($logged->reason)->toContain('legit refund reason');
});
