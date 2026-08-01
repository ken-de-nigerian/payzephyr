<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Enums\SubscriptionStatus;

test('it can be constructed directly with all properties', function () {
    $dto = new SubscriptionResponseDTO(
        subscriptionCode: 'SUB_123',
        status: 'active',
        customer: 'CUS_123',
        plan: 'PLN_123',
        amount: 5000.0,
        currency: 'NGN',
        nextPaymentDate: '2026-08-01',
        emailToken: 'token123',
        metadata: ['foo' => 'bar'],
        provider: 'paystack',
    );

    expect($dto->subscriptionCode)->toBe('SUB_123')
        ->and($dto->status)->toBe('active')
        ->and($dto->customer)->toBe('CUS_123')
        ->and($dto->plan)->toBe('PLN_123')
        ->and($dto->amount)->toBe(5000.0)
        ->and($dto->currency)->toBe('NGN')
        ->and($dto->nextPaymentDate)->toBe('2026-08-01')
        ->and($dto->emailToken)->toBe('token123')
        ->and($dto->metadata)->toBe(['foo' => 'bar'])
        ->and($dto->provider)->toBe('paystack');
});

test('fromArray builds a DTO from a full data array', function () {
    $dto = SubscriptionResponseDTO::fromArray([
        'subscription_code' => 'SUB_456',
        'status' => 'cancelled',
        'customer' => 'CUS_456',
        'plan' => 'PLN_456',
        'amount' => '2500',
        'currency' => 'USD',
        'next_payment_date' => '2026-09-01',
        'email_token' => 'token456',
        'metadata' => ['plan_name' => 'Gold'],
        'provider' => 'stripe',
    ]);

    expect($dto->subscriptionCode)->toBe('SUB_456')
        ->and($dto->status)->toBe('cancelled')
        ->and($dto->customer)->toBe('CUS_456')
        ->and($dto->plan)->toBe('PLN_456')
        ->and($dto->amount)->toBe(2500.0)
        ->and($dto->currency)->toBe('USD')
        ->and($dto->nextPaymentDate)->toBe('2026-09-01')
        ->and($dto->emailToken)->toBe('token456')
        ->and($dto->metadata)->toBe(['plan_name' => 'Gold'])
        ->and($dto->provider)->toBe('stripe');
});

test('fromArray applies defaults for missing keys', function () {
    $dto = SubscriptionResponseDTO::fromArray([]);

    expect($dto->subscriptionCode)->toBe('')
        ->and($dto->status)->toBe('unknown')
        ->and($dto->customer)->toBe('')
        ->and($dto->plan)->toBe('')
        ->and($dto->amount)->toBe(0.0)
        ->and($dto->currency)->toBe('NGN')
        ->and($dto->nextPaymentDate)->toBeNull()
        ->and($dto->emailToken)->toBeNull()
        ->and($dto->metadata)->toBe([])
        ->and($dto->provider)->toBeNull();
});

test('toArray serializes the DTO back into an array', function () {
    $dto = new SubscriptionResponseDTO(
        subscriptionCode: 'SUB_789',
        status: 'active',
        customer: 'CUS_789',
        plan: 'PLN_789',
        amount: 1000.5,
        currency: 'GHS',
        nextPaymentDate: '2026-10-01',
        emailToken: 'tok',
        metadata: ['a' => 1],
        provider: 'flutterwave',
    );

    expect($dto->toArray())->toBe([
        'subscription_code' => 'SUB_789',
        'status' => 'active',
        'customer' => 'CUS_789',
        'plan' => 'PLN_789',
        'amount' => 1000.5,
        'currency' => 'GHS',
        'next_payment_date' => '2026-10-01',
        'email_token' => 'tok',
        'metadata' => ['a' => 1],
        'provider' => 'flutterwave',
    ]);
});

test('getStatus resolves a known status string into the matching enum', function () {
    $dto = SubscriptionResponseDTO::fromArray(['status' => 'active']);

    expect($dto->getStatus())->toBe(SubscriptionStatus::ACTIVE);
});

test('getStatus falls back to EXPIRED for an unmappable status string', function () {
    $dto = SubscriptionResponseDTO::fromArray(['status' => 'totally-unknown-status']);

    expect($dto->getStatus())->toBe(SubscriptionStatus::EXPIRED);
});

test('isActive is true for active and non-renewing statuses', function () {
    expect(SubscriptionResponseDTO::fromArray(['status' => 'active'])->isActive())->toBeTrue()
        ->and(SubscriptionResponseDTO::fromArray(['status' => 'non-renewing'])->isActive())->toBeTrue();
});

test('isActive is false for cancelled, completed and unknown statuses', function () {
    expect(SubscriptionResponseDTO::fromArray(['status' => 'cancelled'])->isActive())->toBeFalse()
        ->and(SubscriptionResponseDTO::fromArray(['status' => 'completed'])->isActive())->toBeFalse()
        ->and(SubscriptionResponseDTO::fromArray(['status' => 'nonsense'])->isActive())->toBeFalse();
});

test('isCancelled reflects the cancelled status only', function () {
    expect(SubscriptionResponseDTO::fromArray(['status' => 'cancelled'])->isCancelled())->toBeTrue()
        ->and(SubscriptionResponseDTO::fromArray(['status' => 'active'])->isCancelled())->toBeFalse();
});

test('isCompleted reflects the completed status only', function () {
    expect(SubscriptionResponseDTO::fromArray(['status' => 'completed'])->isCompleted())->toBeTrue()
        ->and(SubscriptionResponseDTO::fromArray(['status' => 'active'])->isCompleted())->toBeFalse();
});

test('canBeCancelled delegates to the status enum', function () {
    expect(SubscriptionResponseDTO::fromArray(['status' => 'active'])->canBeCancelled())->toBeTrue()
        ->and(SubscriptionResponseDTO::fromArray(['status' => 'attention'])->canBeCancelled())->toBeTrue()
        ->and(SubscriptionResponseDTO::fromArray(['status' => 'completed'])->canBeCancelled())->toBeFalse();
});

test('canBeResumed delegates to the status enum', function () {
    expect(SubscriptionResponseDTO::fromArray(['status' => 'cancelled'])->canBeResumed())->toBeTrue()
        ->and(SubscriptionResponseDTO::fromArray(['status' => 'non-renewing'])->canBeResumed())->toBeTrue()
        ->and(SubscriptionResponseDTO::fromArray(['status' => 'active'])->canBeResumed())->toBeFalse();
});
