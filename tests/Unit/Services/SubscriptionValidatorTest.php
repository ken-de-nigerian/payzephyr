<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Contracts\HasNoSubscriptionListing;
use KenDeNigerian\PayZephyr\Contracts\SubscriptionRepositoryInterface;
use KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface;
use KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
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

beforeEach(function (): void {
    config(['payments.subscriptions.prevent_duplicates' => false]);
    app()->forgetInstance('payments.config');
    $this->validator = new SubscriptionValidator;
});

test('validateCreation passes when the plan is active and duplicate prevention is disabled', function (): void {
    $request = new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1');

    $driver = Mockery::mock(SupportsSubscriptionsInterface::class);
    $driver->shouldReceive('fetchPlan')->with('PLN_1')->andReturn(activePlan());

    $this->validator->validateCreation($request, $driver);
})->throwsNoExceptions();

test('validateCreation throws when the plan is inactive', function (): void {
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

test('validateCreation wraps a fetchPlan failure in a SubscriptionException', function (): void {
    $request = new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1');

    $driver = Mockery::mock(SupportsSubscriptionsInterface::class);
    $driver->shouldReceive('fetchPlan')->with('PLN_1')->andThrow(new RuntimeException('network error'));

    $this->validator->validateCreation($request, $driver);
})->throws(SubscriptionException::class, 'Failed to verify plan');

test('validateCreation throws when the authorization code is shorter than 10 characters', function (): void {
    $request = new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1', authorization: 'short');

    $driver = Mockery::mock(SupportsSubscriptionsInterface::class);
    $driver->shouldReceive('fetchPlan')->with('PLN_1')->andReturn(activePlan());

    $this->validator->validateCreation($request, $driver);
})->throws(SubscriptionException::class, 'Invalid authorization code format');

test('validateCreation passes when the authorization code is at least 10 characters', function (): void {
    $request = new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1', authorization: 'AUTH_1234567890');

    $driver = Mockery::mock(SupportsSubscriptionsInterface::class);
    $driver->shouldReceive('fetchPlan')->with('PLN_1')->andReturn(activePlan());

    $this->validator->validateCreation($request, $driver);
})->throwsNoExceptions();

test('validateCreation throws when duplicate prevention is enabled and an active subscription to the same plan exists', function (): void {
    config(['payments.subscriptions.prevent_duplicates' => true]);
    app()->forgetInstance('payments.config');

    $request = new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1');

    $driver = Mockery::mock(SupportsSubscriptionsInterface::class);
    $driver->shouldReceive('fetchPlan')->with('PLN_1')->andReturn(activePlan());
    $driver->shouldReceive('listSubscriptions')->andReturn([
        'data' => [listedSubscription('PLN_1', 'active')],
    ]);

    $this->validator->validateCreation($request, $driver);
})->throws(SubscriptionException::class, 'already has an active subscription');

test('validateCreation passes when duplicate prevention is enabled but no existing subscription matches the plan', function (): void {
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

test('validateCreation swallows a non-fatal failure while checking for duplicate subscriptions', function (): void {
    config(['payments.subscriptions.prevent_duplicates' => true]);
    app()->forgetInstance('payments.config');

    $request = new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1');

    $driver = Mockery::mock(SupportsSubscriptionsInterface::class);
    $driver->shouldReceive('fetchPlan')->with('PLN_1')->andReturn(activePlan());
    $driver->shouldReceive('listSubscriptions')->andThrow(new RuntimeException('provider unavailable'));

    $this->validator->validateCreation($request, $driver);
})->throwsNoExceptions();

test('validateCancellation throws when the subscription is already in a terminal state', function (): void {
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

test('validateCancellation passes when the subscription is still active', function (): void {
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
 * Every driver lists subscriptions as SubscriptionResponseDTOs. The duplicate
 * check used to index each entry as an array; on a DTO that is an Error,
 * which it logged as "failed to check" - and then let the duplicate through.
 * prevent_duplicates did nothing on Stripe, Square, Mollie or Flutterwave.
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

test('an active subscription listed as a DTO blocks a duplicate', function (array $listing): void {
    $request = new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1');

    expect(fn () => $this->validator->validateCreation($request, duplicateCheckingDriver($listing)))
        ->toThrow(SubscriptionException::class, 'Customer already has an active subscription to plan PLN_1');
})->with([
    'under data, as the drivers return it' => [['data' => [listedSubscription('PLN_1', 'active')], 'has_more' => false]],
    'as a bare list' => [[listedSubscription('PLN_2', 'active'), listedSubscription('PLN_1', 'non-renewing')]],
    'with a status in capitals' => [['data' => [listedSubscription('PLN_1', 'Active')]]],
]);

test('a listed DTO for another plan, or one that has ended, does not block', function (): void {
    $request = new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1');
    $driver = duplicateCheckingDriver(['data' => [
        listedSubscription('PLN_2', 'active'),
        listedSubscription('PLN_1', 'cancelled'),
        // A custom driver's raw rows are not read as subscriptions.
        'not a subscription',
        ['plan' => 'PLN_1', 'status' => 'active'],
    ]]);

    $this->validator->validateCreation($request, $driver);
})->throwsNoExceptions();

test('duplicate prevention switched off with the string an env file produces is off', function (): void {
    config(['payments.subscriptions.prevent_duplicates' => 'false']);
    app()->forgetInstance('payments.config');

    $driver = Mockery::mock(SupportsSubscriptionsInterface::class);
    $driver->shouldReceive('fetchPlan')->with('PLN_1')->andReturn(activePlan());
    $driver->shouldNotReceive('listSubscriptions');

    $this->validator->validateCreation(new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'PLN_1'), $driver);
});

test('a provider that cannot list subscriptions stops the subscribe, and the error names the setting', function (): void {
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

/*
 * PayPal has no API to list subscriptions, so prevent_duplicates used to
 * refuse every PayPal subscribe. It reads the candidates from the
 * subscription log now, and asks PayPal for each one's current status: the
 * log holds the status at create - approval pending - and nothing updates it
 * when the customer approves.
 */

function unlistableDriver(array $statuses): HasNoSubscriptionListing
{
    config(['payments.subscriptions.prevent_duplicates' => true]);
    app()->forgetInstance('payments.config');

    $driver = Mockery::mock(HasNoSubscriptionListing::class);
    $driver->shouldReceive('getName')->andReturn('paypal');
    $driver->shouldReceive('fetchPlan')->with('P-1')->andReturn(activePlan('P-1'));
    $driver->shouldNotReceive('listSubscriptions');

    foreach ($statuses as $code => $status) {
        $driver->shouldReceive('fetchSubscription')->with($code)->andReturn(new SubscriptionResponseDTO(
            subscriptionCode: $code, status: $status, customer: 'a@b.com', plan: 'P-1', amount: 10.0, currency: 'USD',
        ));
    }

    return $driver;
}

function loggedCodes(array $codes): SubscriptionRepositoryInterface
{
    $repository = Mockery::mock(SubscriptionRepositoryInterface::class);
    $repository->shouldReceive('openSubscriptionCodes')->with('paypal', 'a@b.com', 'P-1')->andReturn($codes);

    return $repository;
}

test('a logged subscription the provider reports active blocks a duplicate on a provider that cannot list', function (string $status): void {
    $validator = new SubscriptionValidator(loggedCodes(['I-PENDING', 'I-LIVE']));
    $driver = unlistableDriver(['I-PENDING' => 'attention', 'I-LIVE' => $status]);

    expect(fn () => $validator->validateCreation(new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'P-1'), $driver))
        ->toThrow(SubscriptionException::class, 'Customer already has an active subscription to plan P-1');
})->with(['active', 'non-renewing', 'Active']);

test('the check stops at the first active subscription it fetches', function (): void {
    $validator = new SubscriptionValidator(loggedCodes(['I-LIVE', 'I-NEVER-FETCHED']));
    $driver = unlistableDriver(['I-LIVE' => 'active']);
    $driver->shouldNotReceive('fetchSubscription')->with('I-NEVER-FETCHED');

    expect(fn () => $validator->validateCreation(new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'P-1'), $driver))
        ->toThrow(SubscriptionException::class, 'already has an active subscription');
});

test('logged subscriptions the provider reports pending or ended do not block', function (): void {
    $validator = new SubscriptionValidator(loggedCodes(['I-PENDING', 'I-GONE']));

    $driver = unlistableDriver(['I-PENDING' => 'attention', 'I-GONE' => 'cancelled']);

    expect(fn () => $validator->validateCreation(new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'P-1'), $driver))
        ->not->toThrow(Throwable::class);
});

test('the duplicate check reads the log through the bound repository when none is given', function (): void {
    app(SubscriptionRepositoryInterface::class)->updateOrCreateAtomic('I-LOGGED', [
        'provider' => 'paypal', 'status' => 'attention', 'plan_code' => 'P-1', 'customer_email' => 'a@b.com', 'currency' => 'USD',
    ]);

    expect(fn () => (new SubscriptionValidator)->validateCreation(
        new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'P-1'),
        unlistableDriver(['I-LOGGED' => 'active']),
    ))->toThrow(SubscriptionException::class, 'already has an active subscription');
});

test('a provider that cannot list stops the subscribe when its subscription cannot be fetched', function (): void {
    $validator = new SubscriptionValidator(loggedCodes(['I-BROKEN']));
    $driver = unlistableDriver([]);
    $driver->shouldReceive('fetchSubscription')->with('I-BROKEN')->andThrow(new SubscriptionException('PayPal is down'));

    expect(fn () => $validator->validateCreation(new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'P-1'), $driver))->toThrow(
        SubscriptionException::class,
        'Could not check for an existing subscription to plan P-1, which payments.subscriptions.prevent_duplicates requires: PayPal is down'
    );
});

test('a provider that cannot list stops the subscribe when the subscription log is off', function (array $logging): void {
    $repository = Mockery::mock(SubscriptionRepositoryInterface::class);
    $repository->shouldNotReceive('openSubscriptionCodes');
    $driver = unlistableDriver([]);
    config($logging);
    app()->forgetInstance('payments.config');

    expect(fn () => (new SubscriptionValidator($repository))->validateCreation(new SubscriptionRequestDTO(customer: 'a@b.com', plan: 'P-1'), $driver))->toThrow(
        SubscriptionException::class,
        'Could not check for an existing subscription to plan P-1: paypal cannot list subscriptions, so '.
        'payments.subscriptions.prevent_duplicates reads the subscription log, and payments.subscriptions.logging.enabled is off.'
    );
})->with([
    'subscription logging off' => [['payments.subscriptions.logging.enabled' => false]],
    'all logging off, subscription logging unset' => [['payments.logging.enabled' => false, 'payments.subscriptions.logging' => ['table' => 'subscription_transactions']]],
]);

test('paypal is a provider that cannot list subscriptions', function (): void {
    expect(new PayPalDriver(['client_id' => 'id', 'client_secret' => 'secret', 'currencies' => ['USD']]))->toBeInstanceOf(HasNoSubscriptionListing::class);
});
