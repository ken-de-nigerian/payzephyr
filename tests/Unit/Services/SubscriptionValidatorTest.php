<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface;
use KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;
use KenDeNigerian\PayZephyr\Services\SubscriptionValidator;

function activePlan(string $planCode = 'PLN_1'): PlanResponseDTO
{
    return new PlanResponseDTO(
        planCode: $planCode,
        name: 'Test Plan',
        amount: 5000.0,
        interval: 'monthly',
        currency: 'NGN',
    );
}

beforeEach(function () {
    config(['payments.subscriptions.prevent_duplicates' => false]);
    app()->forgetInstance('payments.config');
    $this->validator = new SubscriptionValidator;
});

test('validateCreation passes when the plan is active and duplicate prevention is disabled', function () {
    $request = new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1');

    $driver = Mockery::mock(SupportsSubscriptionsInterface::class);
    $driver->shouldReceive('fetchPlan')->with('PLN_1')->andReturn(activePlan());

    $this->validator->validateCreation($request, $driver);
})->throwsNoExceptions();

test('validateCreation throws when the plan is inactive', function () {
    $request = new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1');

    $inactivePlan = new PlanResponseDTO(
        planCode: 'PLN_1',
        name: 'Test Plan',
        amount: 5000.0,
        interval: 'monthly',
        currency: 'NGN',
        metadata: ['status' => 'inactive'],
    );

    $driver = Mockery::mock(SupportsSubscriptionsInterface::class);
    $driver->shouldReceive('fetchPlan')->with('PLN_1')->andReturn($inactivePlan);

    $this->validator->validateCreation($request, $driver);
})->throws(SubscriptionException::class, 'is not active');

test('validateCreation wraps a fetchPlan failure in a SubscriptionException', function () {
    $request = new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1');

    $driver = Mockery::mock(SupportsSubscriptionsInterface::class);
    $driver->shouldReceive('fetchPlan')->with('PLN_1')->andThrow(new RuntimeException('network error'));

    $this->validator->validateCreation($request, $driver);
})->throws(SubscriptionException::class, 'Failed to verify plan');

test('validateCreation throws when the authorization code is shorter than 10 characters', function () {
    $request = new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1', authorization: 'short');

    $driver = Mockery::mock(SupportsSubscriptionsInterface::class);
    $driver->shouldReceive('fetchPlan')->with('PLN_1')->andReturn(activePlan());

    $this->validator->validateCreation($request, $driver);
})->throws(SubscriptionException::class, 'Invalid authorization code format');

test('validateCreation passes when the authorization code is at least 10 characters', function () {
    $request = new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1', authorization: 'AUTH_1234567890');

    $driver = Mockery::mock(SupportsSubscriptionsInterface::class);
    $driver->shouldReceive('fetchPlan')->with('PLN_1')->andReturn(activePlan());

    $this->validator->validateCreation($request, $driver);
})->throwsNoExceptions();

test('validateCreation throws when duplicate prevention is enabled and an active subscription to the same plan exists', function () {
    config(['payments.subscriptions.prevent_duplicates' => true]);
    app()->forgetInstance('payments.config');

    $request = new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1');

    $driver = Mockery::mock(SupportsSubscriptionsInterface::class);
    $driver->shouldReceive('fetchPlan')->with('PLN_1')->andReturn(activePlan());
    $driver->shouldReceive('listSubscriptions')->andReturn([
        'data' => [
            ['plan' => ['plan_code' => 'PLN_1'], 'status' => 'active'],
        ],
    ]);

    $this->validator->validateCreation($request, $driver);
})->throws(SubscriptionException::class, 'already has an active subscription');

test('validateCreation passes when duplicate prevention is enabled but no existing subscription matches the plan', function () {
    config(['payments.subscriptions.prevent_duplicates' => true]);
    app()->forgetInstance('payments.config');

    $request = new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1');

    $driver = Mockery::mock(SupportsSubscriptionsInterface::class);
    $driver->shouldReceive('fetchPlan')->with('PLN_1')->andReturn(activePlan());
    $driver->shouldReceive('listSubscriptions')->andReturn([
        'data' => [
            ['plan' => ['plan_code' => 'PLN_2'], 'status' => 'active'],
            ['plan' => ['plan_code' => 'PLN_1'], 'status' => 'cancelled'],
        ],
    ]);

    $this->validator->validateCreation($request, $driver);
})->throwsNoExceptions();

test('validateCreation swallows a non-fatal failure while checking for duplicate subscriptions', function () {
    config(['payments.subscriptions.prevent_duplicates' => true]);
    app()->forgetInstance('payments.config');

    $request = new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1');

    $driver = Mockery::mock(SupportsSubscriptionsInterface::class);
    $driver->shouldReceive('fetchPlan')->with('PLN_1')->andReturn(activePlan());
    $driver->shouldReceive('listSubscriptions')->andThrow(new RuntimeException('provider unavailable'));

    $this->validator->validateCreation($request, $driver);
})->throwsNoExceptions();

test('validateCancellation throws when the subscription is already in a terminal state', function () {
    $subscription = new SubscriptionResponseDTO(
        subscriptionCode: 'SUB_1',
        status: 'cancelled',
        customer: 'a@b.com',
        plan: 'PLN_1',
        amount: 10.0,
        currency: 'USD',
    );

    $driver = Mockery::mock(SupportsSubscriptionsInterface::class);
    $driver->shouldReceive('fetchSubscription')->with('SUB_1')->andReturn($subscription);

    $this->validator->validateCancellation('SUB_1', $driver);
})->throws(SubscriptionException::class, 'already in terminal state');

test('validateCancellation passes when the subscription is still active', function () {
    $subscription = new SubscriptionResponseDTO(
        subscriptionCode: 'SUB_1',
        status: 'active',
        customer: 'a@b.com',
        plan: 'PLN_1',
        amount: 10.0,
        currency: 'USD',
    );

    $driver = Mockery::mock(SupportsSubscriptionsInterface::class);
    $driver->shouldReceive('fetchSubscription')->with('SUB_1')->andReturn($subscription);

    $this->validator->validateCancellation('SUB_1', $driver);
})->throwsNoExceptions();
