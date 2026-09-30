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

/*
 * Every driver but Paystack lists subscriptions as SubscriptionResponseDTOs.
 * The duplicate check indexed each entry as an array; on a DTO that is an
 * Error, which it logged as "failed to check" - and then let the duplicate
 * through. prevent_duplicates did nothing on Stripe, Square, Mollie or
 * Flutterwave.
 */

function listedSubscription(string $plan, string $status): SubscriptionResponseDTO
{
    return new SubscriptionResponseDTO(
        subscriptionCode: 'SUB_EXISTING',
        status: $status,
        customer: 'a@b.com',
        plan: $plan,
        amount: 10.0,
        currency: 'USD',
    );
}

function duplicateCheckingDriver(array $listing): SupportsSubscriptionsInterface
{
    config(['payments.subscriptions.prevent_duplicates' => true]);
    app()->forgetInstance('payments.config');

    $driver = Mockery::mock(SupportsSubscriptionsInterface::class);
    $driver->shouldReceive('fetchPlan')->with('PLN_1')->andReturn(activePlan());
    $driver->shouldReceive('listSubscriptions')->andReturn($listing);

    return $driver;
}

test('an active subscription listed as a DTO blocks a duplicate', function (array $listing) {
    $request = new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1');

    expect(fn () => $this->validator->validateCreation($request, duplicateCheckingDriver($listing)))
        ->toThrow(SubscriptionException::class, 'Customer already has an active subscription to plan PLN_1');
})->with([
    'under data, as the drivers return it' => [['data' => [listedSubscription('PLN_1', 'active')], 'has_more' => false]],
    'as a bare list' => [[listedSubscription('PLN_2', 'active'), listedSubscription('PLN_1', 'non-renewing')]],
    'a raw row naming its plan by code' => [['data' => [['plan' => 'PLN_1', 'status' => 'Active']]]],
    'a raw row with a top-level plan_code' => [['data' => [['plan_code' => 'PLN_1', 'status' => 'active']]]],
]);

test('a listed DTO for another plan, or one that has ended, does not block', function () {
    $request = new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1');
    $driver = duplicateCheckingDriver(['data' => [
        listedSubscription('PLN_2', 'active'),
        listedSubscription('PLN_1', 'cancelled'),
        'not a subscription',
        ['status' => 'active'],
    ]]);

    $this->validator->validateCreation($request, $driver);
})->throwsNoExceptions();

test('duplicate prevention switched off with the string an env file produces is off', function () {
    config(['payments.subscriptions.prevent_duplicates' => 'false']);
    app()->forgetInstance('payments.config');

    $driver = Mockery::mock(SupportsSubscriptionsInterface::class);
    $driver->shouldReceive('fetchPlan')->with('PLN_1')->andReturn(activePlan());
    $driver->shouldNotReceive('listSubscriptions');

    $this->validator->validateCreation(new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1'), $driver);
});

test('a provider that cannot list subscriptions stops the subscribe, and the error names the setting', function () {
    config(['payments.subscriptions.prevent_duplicates' => true]);
    app()->forgetInstance('payments.config');

    $driver = Mockery::mock(SupportsSubscriptionsInterface::class);
    $driver->shouldReceive('fetchPlan')->with('PLN_1')->andReturn(activePlan());
    $driver->shouldReceive('listSubscriptions')->andThrow(new SubscriptionException('PayPal does not provide an API to list subscriptions'));

    $request = new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1');

    expect(fn () => $this->validator->validateCreation($request, $driver))->toThrow(
        SubscriptionException::class,
        'Could not check for an existing subscription to plan PLN_1, which payments.subscriptions.prevent_duplicates requires: PayPal does not provide an API to list subscriptions'
    );
});
