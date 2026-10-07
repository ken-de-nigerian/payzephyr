<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;

afterEach(function (): void {
    config(['payments.logging.table' => null]);
    app()->forgetInstance('payments.config');
});

test('getTable returns the configured table name when it is valid', function (): void {
    config(['payments.logging.table' => 'custom_payment_transactions']);
    app()->forgetInstance('payments.config');

    $model = new PaymentTransaction;

    expect($model->getTable())->toBe('custom_payment_transactions');
});

test('getTable falls back to the default and logs a warning when the configured name contains invalid characters', function (): void {
    config(['payments.logging.table' => 'bad-table-name!']);
    app()->forgetInstance('payments.config');

    Log::shouldReceive('channel')->once()->with('payments')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with(
        'Invalid table name in config, using default',
        ['attempted_table' => 'bad-table-name!']
    );

    $model = new PaymentTransaction;

    expect($model->getTable())->toBe('payment_transactions');
});

test('getTable falls back to the default and logs a warning when the configured name starts with a digit', function (): void {
    config(['payments.logging.table' => '1payment_transactions']);
    app()->forgetInstance('payments.config');

    Log::shouldReceive('channel')->once()->with('payments')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with(
        'Invalid table name in config, using default',
        ['attempted_table' => '1payment_transactions']
    );

    $model = new PaymentTransaction;

    expect($model->getTable())->toBe('payment_transactions');
});

test('getTable falls back to the default and logs a warning when the configured name is too long', function (): void {
    $tooLong = str_repeat('a', 65);
    config(['payments.logging.table' => $tooLong]);
    app()->forgetInstance('payments.config');

    Log::shouldReceive('channel')->once()->with('payments')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with(
        'Invalid table name in config, using default',
        ['attempted_table' => $tooLong]
    );

    $model = new PaymentTransaction;

    expect($model->getTable())->toBe('payment_transactions');
});

test('getTable falls back to the default when the configured name is not a string, instead of raising a TypeError', function (): void {
    config(['payments.logging.table' => ['payment_transactions']]);
    app()->forgetInstance('payments.config');

    Log::shouldReceive('channel')->once()->with('payments')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with(
        'Invalid table name in config, using default',
        ['attempted_table' => ['payment_transactions']]
    );

    expect((new PaymentTransaction)->getTable())->toBe('payment_transactions');
});
