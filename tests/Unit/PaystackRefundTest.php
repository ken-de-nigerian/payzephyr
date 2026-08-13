<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use Tests\Helpers\PaystackDriverTestHelper;

test('paystack refund succeeds with valid response for a full refund', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'message' => 'Refund has been queued for processing',
            'data' => [
                'id' => 12345,
                'status' => 'pending',
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $request = new RefundRequestDTO(transactionReference: 'txn_ref_123');

    $result = $driver->refund($request);

    expect($result->refundReference)->toBe('12345')
        ->and($result->transactionReference)->toBe('txn_ref_123')
        ->and($result->status)->toBe('pending')
        ->and($result->amount)->toBe(5000.0)
        ->and($result->currency)->toBe('NGN')
        ->and($result->provider)->toBe('paystack');
});

test('paystack refund sends the amount for a partial refund', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'id' => 12346,
                'status' => 'pending',
                'amount' => 200000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $request = new RefundRequestDTO(transactionReference: 'txn_ref_123', amount: 2000.00, reason: 'customer request');

    $result = $driver->refund($request);

    expect($result->amount)->toBe(2000.0)
        ->and($result->reason)->toBe('customer request');
});

test('paystack refund throws exception on api error', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => false,
            'message' => 'Transaction reference not found',
        ])),
    ]);

    $driver->refund(new RefundRequestDTO(transactionReference: 'invalid_ref'));
})->throws(RefundException::class, 'Transaction reference not found');

test('paystack refund throws exception on network error', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(500, [], json_encode(['status' => false, 'message' => 'Internal server error'])),
    ]);

    $driver->refund(new RefundRequestDTO(transactionReference: 'txn_ref_123'));
})->throws(RefundException::class);

test('paystack fetchRefund succeeds with valid response', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'id' => 12345,
                'status' => 'processed',
                'amount' => 500000,
                'currency' => 'NGN',
                'transaction' => ['reference' => 'txn_ref_123'],
            ],
        ])),
    ]);

    $result = $driver->fetchRefund('12345');

    expect($result->refundReference)->toBe('12345')
        ->and($result->transactionReference)->toBe('txn_ref_123')
        ->and($result->status)->toBe('processed')
        ->and($result->amount)->toBe(5000.0);
});

test('paystack fetchRefund throws exception when refund not found', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => false,
            'message' => 'Refund not found',
        ])),
    ]);

    $driver->fetchRefund('nonexistent');
})->throws(RefundException::class, 'Refund not found');
