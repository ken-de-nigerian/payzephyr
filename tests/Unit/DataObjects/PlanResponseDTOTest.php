<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO;

test('it can be constructed directly with all properties', function () {
    $dto = new PlanResponseDTO(
        planCode: 'PLN_123',
        name: 'Gold Plan',
        amount: 50.0,
        interval: 'monthly',
        currency: 'NGN',
        description: 'A great plan',
        invoiceLimit: 12,
        metadata: ['tier' => 'gold'],
        provider: 'paystack',
    );

    expect($dto->planCode)->toBe('PLN_123')
        ->and($dto->name)->toBe('Gold Plan')
        ->and($dto->amount)->toBe(50.0)
        ->and($dto->interval)->toBe('monthly')
        ->and($dto->currency)->toBe('NGN')
        ->and($dto->description)->toBe('A great plan')
        ->and($dto->invoiceLimit)->toBe(12)
        ->and($dto->metadata)->toBe(['tier' => 'gold'])
        ->and($dto->provider)->toBe('paystack');
});

test('fromArray converts minor units to major units and maps fields', function () {
    $dto = PlanResponseDTO::fromArray([
        'plan_code' => 'PLN_456',
        'name' => 'Silver Plan',
        'amount' => '500000',
        'interval' => 'annually',
        'currency' => 'USD',
        'description' => 'Silver tier',
        'invoice_limit' => 6,
        'metadata' => ['status' => 'active'],
        'provider' => 'stripe',
    ]);

    expect($dto->planCode)->toBe('PLN_456')
        ->and($dto->name)->toBe('Silver Plan')
        ->and($dto->amount)->toBe(5000.0)
        ->and($dto->interval)->toBe('annually')
        ->and($dto->currency)->toBe('USD')
        ->and($dto->description)->toBe('Silver tier')
        ->and($dto->invoiceLimit)->toBe(6)
        ->and($dto->metadata)->toBe(['status' => 'active'])
        ->and($dto->provider)->toBe('stripe');
});

test('fromArray falls back to id when plan_code is missing', function () {
    $dto = PlanResponseDTO::fromArray(['id' => 'PLN_789']);

    expect($dto->planCode)->toBe('PLN_789');
});

test('fromArray applies defaults for missing keys', function () {
    $dto = PlanResponseDTO::fromArray([]);

    expect($dto->planCode)->toBe('')
        ->and($dto->name)->toBe('')
        ->and($dto->amount)->toBe(0.0)
        ->and($dto->interval)->toBe('monthly')
        ->and($dto->currency)->toBe('NGN')
        ->and($dto->description)->toBeNull()
        ->and($dto->invoiceLimit)->toBeNull()
        ->and($dto->metadata)->toBe([])
        ->and($dto->provider)->toBeNull();
});

test('toArray serializes the DTO back into an array', function () {
    $dto = new PlanResponseDTO(
        planCode: 'PLN_999',
        name: 'Bronze Plan',
        amount: 10.0,
        interval: 'weekly',
        currency: 'GHS',
        description: null,
        invoiceLimit: null,
        metadata: [],
        provider: null,
    );

    expect($dto->toArray())->toBe([
        'plan_code' => 'PLN_999',
        'name' => 'Bronze Plan',
        'amount' => 10.0,
        'interval' => 'weekly',
        'currency' => 'GHS',
        'description' => null,
        'invoice_limit' => null,
        'metadata' => [],
        'provider' => null,
    ]);
});

test('isActive is true when metadata status is active or absent', function () {
    $withStatus = new PlanResponseDTO('PLN', 'Name', 10.0, 'monthly', 'NGN', metadata: ['status' => 'active']);
    $withoutStatus = new PlanResponseDTO('PLN', 'Name', 10.0, 'monthly', 'NGN');

    expect($withStatus->isActive())->toBeTrue()
        ->and($withoutStatus->isActive())->toBeTrue();
});

test('isActive is false when metadata status is not active', function () {
    $dto = new PlanResponseDTO('PLN', 'Name', 10.0, 'monthly', 'NGN', metadata: ['status' => 'inactive']);

    expect($dto->isActive())->toBeFalse();
});

test('getAmountInMajorUnits returns the amount as-is', function () {
    $dto = new PlanResponseDTO('PLN', 'Name', 123.45, 'monthly', 'NGN');

    expect($dto->getAmountInMajorUnits())->toBe(123.45);
});

test('jsonSerialize returns the same shape as toArray', function () {
    $dto = new PlanResponseDTO('PLN_1', 'Plan One', 20.0, 'daily', 'NGN', 'desc', 3, ['k' => 'v'], 'square');

    expect($dto->jsonSerialize())->toBe($dto->toArray());
});

test('json_encode uses JsonSerializable to produce the expected payload', function () {
    $dto = new PlanResponseDTO('PLN_2', 'Plan Two', 15.5, 'monthly', 'USD');

    $encoded = json_encode($dto);
    $decoded = json_decode($encoded, true);

    expect($decoded)->toBe($dto->toArray());
});
