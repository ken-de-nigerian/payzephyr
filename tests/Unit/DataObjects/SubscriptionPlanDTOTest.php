<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;

test('it can be constructed directly with all properties', function () {
    $dto = new SubscriptionPlanDTO(
        name: 'Gold Plan',
        amount: 50.0,
        interval: 'monthly',
        currency: 'USD',
        description: 'A great plan',
        invoiceLimit: 12,
        sendInvoices: false,
        sendSms: false,
        metadata: ['tier' => 'gold'],
    );

    expect($dto->name)->toBe('Gold Plan')
        ->and($dto->amount)->toBe(50.0)
        ->and($dto->interval)->toBe('monthly')
        ->and($dto->currency)->toBe('USD')
        ->and($dto->description)->toBe('A great plan')
        ->and($dto->invoiceLimit)->toBe(12)
        ->and($dto->sendInvoices)->toBeFalse()
        ->and($dto->sendSms)->toBeFalse()
        ->and($dto->metadata)->toBe(['tier' => 'gold']);
});

test('it applies default currency and flags', function () {
    $dto = new SubscriptionPlanDTO(name: 'Basic', amount: 10.0, interval: 'weekly');

    expect($dto->currency)->toBe('NGN')
        ->and($dto->sendInvoices)->toBeTrue()
        ->and($dto->sendSms)->toBeTrue()
        ->and($dto->metadata)->toBe([]);
});

test('it rejects an empty name', function () {
    new SubscriptionPlanDTO(name: '', amount: 10.0, interval: 'monthly');
})->throws(InvalidArgumentException::class, 'Plan name is required');

test('it rejects a zero or negative amount', function () {
    new SubscriptionPlanDTO(name: 'Plan', amount: 0.0, interval: 'monthly');
})->throws(InvalidArgumentException::class, 'Amount must be greater than zero');

test('it rejects a negative amount', function () {
    new SubscriptionPlanDTO(name: 'Plan', amount: -5.0, interval: 'monthly');
})->throws(InvalidArgumentException::class, 'Amount must be greater than zero');

test('it rejects an invalid interval', function () {
    new SubscriptionPlanDTO(name: 'Plan', amount: 10.0, interval: 'yearly');
})->throws(InvalidArgumentException::class);

test('it accepts every valid interval', function (string $interval) {
    $dto = new SubscriptionPlanDTO(name: 'Plan', amount: 10.0, interval: $interval);

    expect($dto->interval)->toBe($interval);
})->with(['daily', 'weekly', 'monthly', 'annually']);

test('getAmountInMinorUnits converts and rounds the amount', function () {
    $dto = new SubscriptionPlanDTO(name: 'Plan', amount: 10.005, interval: 'monthly');

    expect($dto->getAmountInMinorUnits())->toBe(1001);
});

test('getAmountInMinorUnits handles whole numbers cleanly', function () {
    $dto = new SubscriptionPlanDTO(name: 'Plan', amount: 25.0, interval: 'monthly');

    expect($dto->getAmountInMinorUnits())->toBe(2500);
});

test('fromArray builds a DTO from a full data array', function () {
    $dto = SubscriptionPlanDTO::fromArray([
        'name' => 'Enterprise',
        'amount' => '99.99',
        'interval' => 'annually',
        'currency' => 'USD',
        'description' => 'Big plan',
        'invoice_limit' => 24,
        'send_invoices' => false,
        'send_sms' => false,
        'metadata' => ['level' => 'enterprise'],
    ]);

    expect($dto->name)->toBe('Enterprise')
        ->and($dto->amount)->toBe(99.99)
        ->and($dto->interval)->toBe('annually')
        ->and($dto->currency)->toBe('USD')
        ->and($dto->description)->toBe('Big plan')
        ->and($dto->invoiceLimit)->toBe(24)
        ->and($dto->sendInvoices)->toBeFalse()
        ->and($dto->sendSms)->toBeFalse()
        ->and($dto->metadata)->toBe(['level' => 'enterprise']);
});

test('fromArray applies defaults for missing optional keys', function () {
    $dto = SubscriptionPlanDTO::fromArray(['name' => 'Default Plan', 'amount' => 15]);

    expect($dto->name)->toBe('Default Plan')
        ->and($dto->amount)->toBe(15.0)
        ->and($dto->interval)->toBe('monthly')
        ->and($dto->currency)->toBe('NGN')
        ->and($dto->description)->toBeNull()
        ->and($dto->invoiceLimit)->toBeNull()
        ->and($dto->sendInvoices)->toBeTrue()
        ->and($dto->sendSms)->toBeTrue()
        ->and($dto->metadata)->toBe([]);
});

test('fromArray still enforces validation rules', function () {
    SubscriptionPlanDTO::fromArray(['name' => '', 'amount' => 10]);
})->throws(InvalidArgumentException::class, 'Plan name is required');

test('toArray filters out null values but keeps false and empty array values', function () {
    $dto = new SubscriptionPlanDTO(
        name: 'Plan X',
        amount: 20.0,
        interval: 'monthly',
        currency: 'NGN',
        description: null,
        invoiceLimit: null,
        sendInvoices: false,
        sendSms: false,
        metadata: [],
    );

    $array = $dto->toArray();

    expect($array)->toHaveKey('name', 'Plan X')
        ->and($array)->toHaveKey('amount', 2000)
        ->and($array)->toHaveKey('interval', 'monthly')
        ->and($array)->toHaveKey('currency', 'NGN')
        ->and($array)->not->toHaveKey('description')
        ->and($array)->not->toHaveKey('invoice_limit')
        ->and($array)->toHaveKey('send_invoices', false)
        ->and($array)->toHaveKey('send_sms', false);
});

test('toArray includes all fields when everything is populated', function () {
    $dto = new SubscriptionPlanDTO(
        name: 'Plan Y',
        amount: 30.5,
        interval: 'weekly',
        currency: 'USD',
        description: 'Weekly plan',
        invoiceLimit: 4,
        sendInvoices: true,
        sendSms: true,
        metadata: ['a' => 1],
    );

    expect($dto->toArray())->toBe([
        'name' => 'Plan Y',
        'amount' => 3050,
        'interval' => 'weekly',
        'currency' => 'USD',
        'description' => 'Weekly plan',
        'invoice_limit' => 4,
        'send_invoices' => true,
        'send_sms' => true,
        'metadata' => ['a' => 1],
    ]);
});
