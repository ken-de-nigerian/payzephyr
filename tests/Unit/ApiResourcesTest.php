<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Http\Resources\ChargeResource;
use KenDeNigerian\PayZephyr\Http\Resources\PlanResource;
use KenDeNigerian\PayZephyr\Http\Resources\VerificationResource;

test('charge resource transforms dto correctly', function (): void {
    $dto = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://paystack.com/checkout/ref_123',
        accessCode: 'access_123',
        status: 'pending',
        metadata: [
            'amount' => 10000,
            'currency' => 'NGN',
        ]
    );

    $resource = new ChargeResource($dto);
    $array = $resource->toArray(new Request);

    expect($array)->toHaveKeys(['reference', 'authorization_url', 'status', 'amount', 'metadata', 'created_at'])
        ->and($array['reference'])->toBe('ref_123')
        ->and($array['authorization_url'])->toBe('https://paystack.com/checkout/ref_123')
        ->and($array['status'])->toBe('pending')
        ->and($array['amount'])->toBeArray()
        ->and($array['amount']['value'])->toBe(10000)
        ->and($array['amount']['currency'])->toBe('NGN');
});

test('verification resource transforms dto correctly', function (): void {
    $dto = new VerificationResponseDTO(
        reference: 'ref_123',
        status: 'success',
        amount: 10000.0,
        currency: 'NGN',
        paidAt: now()->toIso8601String(),
        metadata: [
            'amount' => 10000,
            'currency' => 'NGN',
        ],
        channel: 'card'
    );

    $resource = new VerificationResource($dto);
    $array = $resource->toArray(new Request);

    expect($array)->toHaveKeys(['reference', 'status', 'channel', 'amount', 'paid_at', 'metadata', 'verified_at'])
        ->and($array['reference'])->toBe('ref_123')
        ->and($array['status'])->toBe('success')
        ->and($array['channel'])->toBe('card')
        ->and($array['paid_at'])->not->toBeNull()
        ->and($array['verified_at'])->not->toBeNull();
});

test('verification resource handles null paid_at', function (): void {
    $dto = new VerificationResponseDTO(
        reference: 'ref_123',
        status: 'pending',
        amount: 0.0,
        currency: 'NGN',
        metadata: [],
        channel: 'card'
    );

    $resource = new VerificationResource($dto);
    $array = $resource->toArray(new Request);

    expect($array['paid_at'])->toBeNull();
});

test('charge resource includes provider when available', function (): void {
    $dto = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://paystack.com/checkout/ref_123',
        accessCode: 'access_123',
        status: 'pending',
        metadata: [],
        provider: 'paystack'
    );

    $resource = new ChargeResource($dto);
    $array = $resource->toArray(new Request);

    expect($array['provider'])->toBe('paystack');
});

test('charge resource handles missing amount in metadata', function (): void {
    $dto = new ChargeResponseDTO(
        reference: 'ref_123',
        authorizationUrl: 'https://paystack.com/checkout/ref_123',
        accessCode: 'access_123',
        status: 'pending',
        metadata: []
    );

    $resource = new ChargeResource($dto);
    $array = $resource->toArray(new Request);

    expect($array['amount']['value'])->toBeNull()
        ->and($array['amount']['currency'])->toBeNull();
});

test('plan resource transforms dto correctly', function (): void {
    $dto = new PlanResponseDTO(
        planCode: 'PLN_abc123xyz',
        name: 'Monthly Premium',
        amount: 5000.0,
        interval: 'monthly',
        currency: 'NGN',
        description: 'Premium monthly subscription',
        invoiceLimit: 12,
        metadata: ['key' => 'value'],
        provider: 'paystack'
    );

    $resource = new PlanResource($dto);
    $array = $resource->toArray(new Request);

    expect($array)->toHaveKeys(['plan_code', 'name', 'amount', 'interval', 'description', 'invoice_limit', 'metadata', 'provider', 'created_at'])
        ->and($array['plan_code'])->toBe('PLN_abc123xyz')
        ->and($array['name'])->toBe('Monthly Premium')
        ->and($array['interval'])->toBe('monthly')
        ->and($array['amount'])->toBeArray()
        ->and($array['amount']['value'])->toBe(5000.0)
        ->and($array['amount']['currency'])->toBe('NGN')
        ->and($array['description'])->toBe('Premium monthly subscription')
        ->and($array['invoice_limit'])->toBe(12)
        ->and($array['provider'])->toBe('paystack');
});

test('plan resource handles null values', function (): void {
    $dto = new PlanResponseDTO(
        planCode: 'PLN_abc123xyz',
        name: 'Basic Plan',
        amount: 1000.0,
        interval: 'monthly',
        currency: 'NGN',
        metadata: []
    );

    $resource = new PlanResource($dto);
    $array = $resource->toArray(new Request);

    expect($array['description'])->toBeNull()
        ->and($array['invoice_limit'])->toBeNull()
        ->and($array['provider'])->toBeNull();
});
