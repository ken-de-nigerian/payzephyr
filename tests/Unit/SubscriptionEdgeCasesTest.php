<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
use Tests\Helpers\SubscriptionTestHelper;

// ==================== Boundary Value Tests ====================

test('subscription handles maximum quantity value', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::planMock('PLN_123'),
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->quantity(PHP_INT_MAX)
        ->create();

    expect($result)->toBeInstanceOf(\KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO::class);
});

test('subscription handles minimum quantity value', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::planMock('PLN_123'),
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->quantity(1)
        ->create();

    expect($result)->toBeInstanceOf(\KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO::class);
});

test('subscription handles maximum trial days', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::planMock('PLN_123'),
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->trialDays(365)
        ->create();

    expect($result)->toBeInstanceOf(\KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO::class);
});

test('subscription handles zero trial days', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::planMock('PLN_123'),
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->trialDays(0)
        ->create();

    expect($result)->toBeInstanceOf(\KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO::class);
});

// ==================== Date Handling Edge Cases ====================

test('subscription handles past start date', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::planMock('PLN_123'),
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Start date cannot be in the past',
        ])),
    ]);

    $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->startDate('2020-01-01')
        ->create();
})->throws(\KenDeNigerian\PayZephyr\Exceptions\SubscriptionException::class);

test('subscription handles far future start date', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::planMock('PLN_123'),
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $futureDate = date('Y-m-d', strtotime('+10 years'));

    $result = $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->startDate($futureDate)
        ->create();

    expect($result)->toBeInstanceOf(\KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO::class);
});

test('subscription handles invalid date format', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::planMock('PLN_123'),
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Invalid date format',
        ])),
    ]);

    $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->startDate('invalid-date')
        ->create();
})->throws(\KenDeNigerian\PayZephyr\Exceptions\SubscriptionException::class);

// ==================== Currency Edge Cases ====================

test('subscription handles unsupported currency', function () {
    $planDTO = new SubscriptionPlanDTO(
        name: 'Test Plan',
        amount: 1000.00,
        interval: 'monthly',
        currency: 'XYZ' // Unsupported currency
    );

    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Currency not supported',
        ])),
    ]);

    $subscription->planData($planDTO)->createPlan();
})->throws(\KenDeNigerian\PayZephyr\Exceptions\PlanException::class);

test('subscription handles currency case variations', function () {
    $planDTO = new SubscriptionPlanDTO(
        name: 'Test Plan',
        amount: 1000.00,
        interval: 'monthly',
        currency: 'ngn' // Lowercase
    );

    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => ['plan_code' => 'PLN_123'],
        ])),
    ]);

    $result = $subscription->planData($planDTO)->createPlan();

    expect($result)->toBeInstanceOf(\KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO::class);
});

// ==================== Interval Edge Cases ====================

test('subscription handles all valid intervals', function () {
    $intervals = ['daily', 'weekly', 'monthly', 'annually'];

    foreach ($intervals as $interval) {
        $planDTO = new SubscriptionPlanDTO(
            name: "Test Plan $interval",
            amount: 1000.00,
            interval: $interval
        );

        $subscription = SubscriptionTestHelper::createWithMock([
            new Response(200, [], json_encode([
                'status' => true,
                'data' => ['plan_code' => "PLN_$interval"],
            ])),
        ]);

        $result = $subscription->planData($planDTO)->createPlan();

        expect($result)->toBeInstanceOf(\KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO::class)
            ->and($result->planCode)->toBe("PLN_$interval");
    }
});

// ==================== Pagination Edge Cases ====================

test('subscription list handles last page with fewer results', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                ['subscription_code' => 'SUB_1'],
            ],
        ])),
    ]);

    $result = $subscription->perPage(50)->page(999)->list();

    expect($result['data'])->toHaveCount(1);
});

test('subscription list handles first page correctly', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                ['subscription_code' => 'SUB_1'],
                ['subscription_code' => 'SUB_2'],
            ],
        ])),
    ]);

    $result = $subscription->perPage(2)->page(1)->list();

    expect($result)->toBeArray()->toHaveCount(2);
});

test('subscription listPlans handles empty result set', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [],
        ])),
    ]);

    $result = $subscription->listPlans();

    expect($result)->toBe(['data' => [], 'meta' => null]);
});

// ==================== Response Structure Edge Cases ====================

test('subscription handles nested customer object in response', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => [
                    'email' => 'test@example.com',
                    'customer_code' => 'CUS_123',
                    'first_name' => 'Test',
                    'last_name' => 'User',
                ],
                'plan' => ['name' => 'Test Plan'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $subscription->code('SUB_123')->fetch();

    expect($result->customer)->toBe('test@example.com');
});

test('subscription handles nested plan object in response', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => ['email' => 'test@example.com'],
                'plan' => [
                    'plan_code' => 'PLN_123',
                    'name' => 'Test Plan',
                    'amount' => 500000,
                    'interval' => 'monthly',
                ],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $subscription->code('SUB_123')->fetch();

    expect($result->plan)->toBe('PLN_123')
        ->and($result->planName)->toBe('Test Plan');
});

test('subscription handles missing nested objects gracefully', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => null, // Missing customer object
                'plan' => null, // Missing plan object
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $subscription->code('SUB_123')->fetch();

    expect($result->customer)->toBe('')
        ->and($result->plan)->toBe('');
});

// ==================== Metadata Edge Cases ====================

test('subscription handles empty metadata array', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::planMock('PLN_123'),
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->metadata([])
        ->create();

    // The plan's code is the DTO's plan, not an entry slipped into the metadata.
    expect($result->metadata)->toBe([])
        ->and($result->plan)->toBe('PLN_123');
});

test('subscription handles null metadata in response', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test'],
                'amount' => 500000,
                'currency' => 'NGN',
                'metadata' => null,
            ],
        ])),
    ]);

    $result = $subscription->code('SUB_123')->fetch();

    expect($result->metadata)->toBeArray();
});

test('subscription handles deeply nested metadata', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::planMock('PLN_123'),
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->metadata([
            'level1' => [
                'level2' => [
                    'level3' => 'value',
                ],
            ],
        ])
        ->create();

    expect($result)->toBeInstanceOf(\KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO::class);
});

// ==================== Concurrent Operations Edge Cases ====================

test('subscription handles rapid sequential creates', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        // Plan fetch for validation (first create)
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'plan_code' => 'PLN_123',
                'name' => 'Test Plan',
                'amount' => 500000,
                'interval' => 'monthly',
                'currency' => 'NGN',
                'status' => 'active',
            ],
        ])),
        // Subscription creation (first create)
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_1',
                'status' => 'active',
                'customer' => ['email' => 'test1@example.com'],
                'plan' => ['name' => 'Test'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
        // Plan fetch for validation (second create)
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'plan_code' => 'PLN_123',
                'name' => 'Test Plan',
                'amount' => 500000,
                'interval' => 'monthly',
                'currency' => 'NGN',
                'status' => 'active',
            ],
        ])),
        // Subscription creation (second create)
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_2',
                'status' => 'active',
                'customer' => ['email' => 'test2@example.com'],
                'plan' => ['name' => 'Test'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result1 = $subscription->customer('test1@example.com')
        ->plan('PLN_123')
        ->create();

    $result2 = $subscription->customer('test2@example.com')
        ->plan('PLN_123')
        ->create();

    expect($result1->subscriptionCode)->toBe('SUB_1')
        ->and($result2->subscriptionCode)->toBe('SUB_2');
});

// ==================== State Transition Edge Cases ====================

test('subscription handles status transitions correctly', function () {
    // Active -> Cancelled
    $subscription1 = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'cancelled',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result1 = $subscription1->code('SUB_123')->fetch();
    expect($result1->isCancelled())->toBeTrue();

    // Cancelled -> Active (enable)
    $subscription2 = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => ['subscription_code' => 'SUB_123'],
        ])),
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result2 = $subscription2->code('SUB_123')
        ->token('token_12345')
        ->enable();

    expect($result2->isActive())->toBeTrue();
});

// ==================== Error Recovery Edge Cases ====================

test('subscription handles partial response data', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                // Missing other fields
            ],
        ])),
    ]);

    $result = $subscription->code('SUB_123')->fetch();

    expect($result->subscriptionCode)->toBe('SUB_123')
        ->and($result->status)->toBe('unknown')
        ->and($result->customer)->toBe('');
});

test('subscription handles malformed status values', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'UNKNOWN_STATUS_XYZ',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $subscription->code('SUB_123')->fetch();

    // Should handle unknown status gracefully
    expect($result->status)->toBe('UNKNOWN_STATUS_XYZ')
        ->and($result->isActive())->toBeFalse()
        ->and($result->isCancelled())->toBeFalse();
});

// ==================== Provider-Specific Edge Cases ====================

test('subscription handles provider-specific response formats', function () {
    // Some providers might return different structures
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => [
                    'email' => 'test@example.com',
                    'customer_code' => 'CUS_123',
                ],
                'plan' => [
                    'plan_code' => 'PLN_123',
                    'name' => 'Test Plan',
                ],
                'amount' => 500000,
                'currency' => 'NGN',
                'next_payment_date' => '2024-02-01T00:00:00.000Z',
                'email_token' => 'token_12345',
                'metadata' => [],
            ],
        ])),
    ]);

    $result = $subscription->code('SUB_123')->fetch();

    expect($result)->toBeInstanceOf(\KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO::class)
        ->and($result->subscriptionCode)->toBe('SUB_123')
        ->and($result->customer)->toBe('test@example.com')
        ->and($result->plan)->toBe('PLN_123')
        ->and($result->planName)->toBe('Test Plan');
});

// ==================== Memory and Performance Edge Cases ====================

test('subscription handles large response payloads', function () {
    $largeMetadata = [];
    for ($i = 0; $i < 100; $i++) {
        $largeMetadata["key_$i"] = str_repeat('x', 100);
    }

    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test'],
                'amount' => 500000,
                'currency' => 'NGN',
                'metadata' => $largeMetadata,
            ],
        ])),
    ]);

    $result = $subscription->code('SUB_123')->fetch();

    expect($result->metadata)->toBeArray()->toHaveCount(100);
});

test('subscription handles multiple rapid status checks', function () {
    $responses = [];
    for ($i = 0; $i < 10; $i++) {
        $responses[] = new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ]));
    }

    $subscription = SubscriptionTestHelper::createWithMock($responses);

    for ($i = 0; $i < 10; $i++) {
        $result = $subscription->code('SUB_123')->fetch();
        expect($result->status)->toBe('active');
    }
});
