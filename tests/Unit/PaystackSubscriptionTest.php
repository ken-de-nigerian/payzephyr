<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionActionDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\Exceptions\PlanException;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;
use Tests\Helpers\PaystackDriverTestHelper;

// ==================== Plan Operations Tests ====================

test('paystack createPlan succeeds with valid response', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'plan_code' => 'PLN_test123',
                'name' => 'Monthly Plan',
                'amount' => 500000,
                'interval' => 'monthly',
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $plan = new SubscriptionPlanDTO(
        name: 'Monthly Plan',
        amount: 5000.00,
        interval: 'monthly',
        currency: 'NGN',
        description: 'Monthly subscription plan'
    );

    $result = $driver->createPlan($plan);

    expect($result->planCode)->toBe('PLN_test123')
        ->and($result->name)->toBe('Monthly Plan')
        ->and($result->amount)->toBe(5000.0); // Converted from 500000 kobo to 5000.0 naira
});

test('paystack createPlan throws exception on api error', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => false,
            'message' => 'Invalid plan data',
        ])),
    ]);

    $plan = new SubscriptionPlanDTO(
        name: 'Test Plan',
        amount: 1000.00,
        interval: 'monthly'
    );

    $driver->createPlan($plan);
})->throws(PlanException::class, 'Invalid plan data');

test('paystack createPlan handles network errors', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(500, [], json_encode([
            'status' => false,
            'message' => 'Internal server error',
        ])),
    ]);

    $plan = new SubscriptionPlanDTO(
        name: 'Test Plan',
        amount: 1000.00,
        interval: 'monthly'
    );

    $driver->createPlan($plan);
})->throws(PlanException::class);

test('paystack updatePlan succeeds', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'plan_code' => 'PLN_test123',
                'name' => 'Updated Plan',
                'amount' => 600000,
            ],
        ])),
    ]);

    $result = $driver->updatePlan('PLN_test123', ['name' => 'Updated Plan']);

    expect($result->planCode)->toBe('PLN_test123')
        ->and($result->name)->toBe('Updated Plan');
});

test('paystack updatePlan throws exception on error', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => false,
            'message' => 'Plan not found',
        ])),
    ]);

    $driver->updatePlan('PLN_nonexistent', ['name' => 'Test']);
})->throws(PlanException::class, 'Plan not found');

test('paystack getPlan succeeds', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'plan_code' => 'PLN_test123',
                'name' => 'Monthly Plan',
                'amount' => 500000,
                'interval' => 'monthly',
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $driver->fetchPlan('PLN_test123');

    expect($result->planCode)->toBe('PLN_test123')
        ->and($result->name)->toBe('Monthly Plan')
        ->and($result->interval)->toBe('monthly');
});

test('paystack getPlan throws exception when plan not found', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(404, [], json_encode([
            'status' => false,
            'message' => 'Plan not found',
        ])),
    ]);

    $driver->fetchPlan('PLN_nonexistent');
})->throws(PlanException::class);

test('paystack listPlans succeeds', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                [
                    'plan_code' => 'PLN_001',
                    'name' => 'Plan 1',
                ],
                [
                    'plan_code' => 'PLN_002',
                    'name' => 'Plan 2',
                ],
            ],
        ])),
    ]);

    $result = $driver->listPlans(50, 1);

    expect($result)->toBeArray()
        ->and(count($result))->toBe(2)
        ->and($result[0]['plan_code'])->toBe('PLN_001');
});

test('paystack listPlans with pagination', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                ['plan_code' => 'PLN_003', 'name' => 'Plan 3'],
            ],
        ])),
    ]);

    $result = $driver->listPlans(10, 2);

    expect($result)->toBeArray()
        ->and(count($result))->toBe(1);
});

test('paystack listPlans throws exception on error', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(500, [], json_encode([
            'status' => false,
            'message' => 'Internal server error',
        ])),
    ]);

    $driver->listPlans();
})->throws(PlanException::class);

// ==================== Subscription Operations Tests ====================

test('paystack createSubscription succeeds', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_test123',
                'status' => 'active',
                'customer' => [
                    'email' => 'customer@example.com',
                    'customer_code' => 'CUS_test123',
                ],
                'plan' => [
                    'plan_code' => 'PLN_test123',
                    'name' => 'Monthly Plan',
                ],
                'amount' => 500000,
                'currency' => 'NGN',
                'next_payment_date' => '2024-02-01T00:00:00.000Z',
                'email_token' => 'token_abc123',
                'metadata' => ['order_id' => 12345],
            ],
        ])),
    ]);

    $request = new SubscriptionRequestDTO(
        customer: 'customer@example.com',
        plan: 'PLN_test123',
        quantity: 1,
        metadata: ['order_id' => 12345]
    );

    $result = $driver->createSubscription($request);

    expect($result->subscriptionCode)->toBe('SUB_test123')
        ->and($result->status)->toBe('active')
        ->and($result->customer)->toBe('customer@example.com')
        ->and($result->plan)->toBe('Monthly Plan')
        ->and($result->amount)->toBe(5000.0)
        ->and($result->currency)->toBe('NGN')
        ->and($result->emailToken)->toBe('token_abc123')
        ->and($result->isActive())->toBeTrue();
});

test('paystack createSubscription converts amount from kobo to naira', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_test123',
                'status' => 'active',
                'customer' => ['email' => 'customer@example.com'],
                'plan' => ['name' => 'Monthly Plan'],
                'amount' => 1000000, // 10,000 NGN in kobo
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $request = new SubscriptionRequestDTO(
        customer: 'customer@example.com',
        plan: 'PLN_test123'
    );

    $result = $driver->createSubscription($request);

    expect($result->amount)->toBe(10000.0);
});

test('paystack createSubscription throws exception on api error', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => false,
            'message' => 'Invalid customer or plan',
        ])),
    ]);

    $request = new SubscriptionRequestDTO(
        customer: 'invalid@example.com',
        plan: 'PLN_invalid'
    );

    $driver->createSubscription($request);
})->throws(SubscriptionException::class, 'Invalid customer or plan');

test('paystack fetchSubscription succeeds', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_test123',
                'status' => 'active',
                'customer' => [
                    'email' => 'customer@example.com',
                ],
                'plan' => [
                    'name' => 'Monthly Plan',
                ],
                'amount' => 500000,
                'currency' => 'NGN',
                'next_payment_date' => '2024-02-01T00:00:00.000Z',
                'email_token' => 'token_abc123',
                'metadata' => [],
            ],
        ])),
    ]);

    $result = $driver->fetchSubscription('SUB_test123');

    expect($result->subscriptionCode)->toBe('SUB_test123')
        ->and($result->status)->toBe('active')
        ->and($result->customer)->toBe('customer@example.com')
        ->and($result->nextPaymentDate)->toBe('2024-02-01T00:00:00.000Z')
        ->and($result->isActive())->toBeTrue();
});

test('paystack fetchSubscription handles cancelled status', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_test123',
                'status' => 'cancelled',
                'customer' => ['email' => 'customer@example.com'],
                'plan' => ['name' => 'Monthly Plan'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $driver->fetchSubscription('SUB_test123');

    expect($result->status)->toBe('cancelled')
        ->and($result->isCancelled())->toBeTrue()
        ->and($result->isActive())->toBeFalse();
});

test('paystack fetchSubscription throws exception when not found', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(404, [], json_encode([
            'status' => false,
            'message' => 'Subscription not found',
        ])),
    ]);

    $driver->fetchSubscription('SUB_nonexistent');
})->throws(SubscriptionException::class);

test('paystack cancelSubscription succeeds', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        // Response from disable endpoint
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_test123',
            ],
        ])),
        // Response from fetchSubscription call
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_test123',
                'status' => 'cancelled',
                'customer' => ['email' => 'customer@example.com'],
                'plan' => ['name' => 'Monthly Plan'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $driver->cancelSubscription(new SubscriptionActionDTO('SUB_test123', ['token' => 'token_abc123']));

    expect($result->subscriptionCode)->toBe('SUB_test123')
        ->and($result->status)->toBe('cancelled')
        ->and($result->isCancelled())->toBeTrue();
});

test('paystack cancelSubscription throws exception on error', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => false,
            'message' => 'Invalid token',
        ])),
    ]);

    $driver->cancelSubscription(new SubscriptionActionDTO('SUB_test123', ['token' => 'invalid_token']));
})->throws(SubscriptionException::class, 'Invalid token');

test('paystack enableSubscription succeeds', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        // Response from enable endpoint
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_test123',
            ],
        ])),
        // Response from fetchSubscription call
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_test123',
                'status' => 'active',
                'customer' => ['email' => 'customer@example.com'],
                'plan' => ['name' => 'Monthly Plan'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $driver->enableSubscription(new SubscriptionActionDTO('SUB_test123', ['token' => 'token_abc123']));

    expect($result->subscriptionCode)->toBe('SUB_test123')
        ->and($result->status)->toBe('active')
        ->and($result->isActive())->toBeTrue();
});

test('paystack enableSubscription throws exception on error', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Invalid token',
        ])),
    ]);

    $driver->enableSubscription(new SubscriptionActionDTO('SUB_test123', ['token' => 'invalid_token']));
})->throws(SubscriptionException::class);

test('paystack listSubscriptions succeeds', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                [
                    'subscription_code' => 'SUB_001',
                    'status' => 'active',
                ],
                [
                    'subscription_code' => 'SUB_002',
                    'status' => 'cancelled',
                ],
            ],
        ])),
    ]);

    $result = $driver->listSubscriptions(50, 1);

    expect($result)->toBeArray()
        ->and(count($result))->toBe(2)
        ->and($result[0]['subscription_code'])->toBe('SUB_001');
});

test('paystack listSubscriptions with customer filter', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                [
                    'subscription_code' => 'SUB_001',
                    'status' => 'active',
                ],
            ],
        ])),
    ]);

    $result = $driver->listSubscriptions(50, 1, 'customer@example.com');

    expect($result)->toBeArray()
        ->and(count($result))->toBe(1)
        ->and($result[0]['subscription_code'])->toBe('SUB_001');
});

test('paystack listSubscriptions with pagination', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                ['subscription_code' => 'SUB_003', 'status' => 'active'],
            ],
        ])),
    ]);

    $result = $driver->listSubscriptions(10, 2);

    expect($result)->toBeArray()
        ->and(count($result))->toBe(1);
});

test('paystack listSubscriptions throws exception on error', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(500, [], json_encode([
            'status' => false,
            'message' => 'Internal server error',
        ])),
    ]);

    $driver->listSubscriptions();
})->throws(SubscriptionException::class);

// ==================== Edge Cases and Error Handling ====================

test('paystack createPlan handles missing plan_code in response', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'name' => 'Test Plan',
                'amount' => 100000,
            ],
        ])),
    ]);

    $plan = new SubscriptionPlanDTO(
        name: 'Test Plan',
        amount: 1000.00,
        interval: 'monthly'
    );

    $result = $driver->createPlan($plan);

    expect($result)->toBeInstanceOf(\KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO::class)
        ->and($result->name)->toBe('Test Plan');
});

test('paystack createSubscription handles missing customer email in response', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_test123',
                'status' => 'active',
                'customer' => [],
                'plan' => ['name' => 'Monthly Plan'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $request = new SubscriptionRequestDTO(
        customer: 'customer@example.com',
        plan: 'PLN_test123'
    );

    $result = $driver->createSubscription($request);

    // Should fallback to request customer email
    expect($result->customer)->toBe('customer@example.com');
});

test('paystack fetchSubscription handles missing optional fields', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_test123',
                'status' => 'active',
                'customer' => ['email' => 'customer@example.com'],
                'plan' => ['name' => 'Monthly Plan'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $driver->fetchSubscription('SUB_test123');

    expect($result->nextPaymentDate)->toBeNull()
        ->and($result->emailToken)->toBeNull()
        ->and($result->metadata)->toBe([]);
});

test('paystack createPlan validates plan DTO', function () {
    expect(fn () => new SubscriptionPlanDTO(
        name: '',
        amount: 1000.00,
        interval: 'monthly'
    ))->toThrow(InvalidArgumentException::class, 'Plan name is required');

    expect(fn () => new SubscriptionPlanDTO(
        name: 'Test Plan',
        amount: -100,
        interval: 'monthly'
    ))->toThrow(InvalidArgumentException::class, 'Amount must be greater than zero');

    expect(fn () => new SubscriptionPlanDTO(
        name: 'Test Plan',
        amount: 1000.00,
        interval: 'invalid'
    ))->toThrow(InvalidArgumentException::class, 'Interval must be one of');
});

test('paystack createSubscription validates request DTO', function () {
    expect(fn () => new SubscriptionRequestDTO(
        customer: '',
        plan: 'PLN_test123'
    ))->toThrow(InvalidArgumentException::class, 'Customer is required');

    expect(fn () => new SubscriptionRequestDTO(
        customer: 'customer@example.com',
        plan: ''
    ))->toThrow(InvalidArgumentException::class, 'Plan is required');

    expect(fn () => new SubscriptionRequestDTO(
        customer: 'customer@example.com',
        plan: 'PLN_test123',
        quantity: 0
    ))->toThrow(InvalidArgumentException::class, 'Quantity must be at least 1');
});
