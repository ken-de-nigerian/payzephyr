<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Exceptions\PaymentException;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Refund;
use KenDeNigerian\PayZephyr\Tests\Fixtures\CustomTestDriver;
use Tests\Helpers\RefundTestHelper;

test('refund() builds a request and issues a full refund through the driver', function () {
    $refund = RefundTestHelper::createWithMock([
        RefundTestHelper::refundMock(12345, ['status' => 'pending', 'amount' => 500000]),
    ]);

    $result = $refund->transaction('txn_ref_123')->refund();

    expect($result->refundReference)->toBe('12345')
        ->and($result->transactionReference)->toBe('txn_ref_123')
        ->and($result->status)->toBe('pending')
        ->and($result->amount)->toBe(5000.0);
});

test('refund() sends a partial amount and reason through the chain', function () {
    $refund = RefundTestHelper::createWithMock([
        RefundTestHelper::refundMock(12346, ['status' => 'pending', 'amount' => 200000]),
    ]);

    $result = $refund->transaction('txn_ref_123')
        ->amount(2000.00)
        ->reason('customer requested')
        ->refund();

    expect($result->amount)->toBe(2000.0)
        ->and($result->reason)->toBe('customer requested');
});

test('fetch() retrieves refund details through the driver', function () {
    $refund = RefundTestHelper::createWithMock([
        RefundTestHelper::refundMock(12345, [
            'status' => 'processed',
            'amount' => 500000,
            'transaction' => ['reference' => 'txn_ref_123'],
        ]),
    ]);

    $result = $refund->fetch('12345');

    expect($result->refundReference)->toBe('12345')
        ->and($result->status)->toBe('processed');
});

test('refund() throws PaymentException for a provider that does not support refunds', function () {
    $driver = new CustomTestDriver(['api_key' => 'test_api_key', 'currencies' => ['NGN']]);

    $manager = new PaymentManager;
    $reflection = new ReflectionClass($manager);

    $configProperty = $reflection->getProperty('config');
    $configProperty->setAccessible(true);
    $config = $configProperty->getValue($manager);
    $config['providers']['custom_test'] = ['enabled' => true];
    $configProperty->setValue($manager, $config);

    $driversProperty = $reflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $drivers = $driversProperty->getValue($manager);
    $drivers['custom_test'] = $driver;
    $driversProperty->setValue($manager, $drivers);

    $refund = new Refund($manager);
    $refund->with('custom_test')->transaction('txn_ref_123')->refund();
})->throws(PaymentException::class, 'does not support refunds');
