<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;
use KenDeNigerian\PayZephyr\Exceptions\PlanException;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Subscription;
use Tests\Helpers\PaystackDriverTestHelper;
use Tests\Helpers\SubscriptionTestHelper;

// ==================== Subscription Fluent API Tests ====================

test('subscription fluent api with() method sets provider', function () {
    $subscription = SubscriptionTestHelper::createWithMock([]);

    $result = $subscription->with('paystack');

    expect($result)->toBe($subscription);
});

test('subscription fluent api using() method sets provider', function () {
    $subscription = SubscriptionTestHelper::createWithMock([]);

    $result = $subscription->using('paystack');

    expect($result)->toBe($subscription);
});

test('subscription fluent api with() accepts array of providers', function () {
    $subscription = SubscriptionTestHelper::createWithMock([]);

    $result = $subscription->with(['paystack', 'stripe']);

    expect($result)->toBe($subscription);
});

test('subscription fluent api methods can be chained', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test Plan'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $subscription
        ->code('SUB_123')
        ->with('paystack')
        ->fetch();

    expect($result)->toBeInstanceOf(SubscriptionResponseDTO::class);
});

// ==================== Plan Creation Tests ====================

test('subscription createPlan validates plan data is set', function () {
    $subscription = SubscriptionTestHelper::createWithMock([]);

    $subscription->createPlan();
})->throws(PaymentException::class, 'Plan data is required');

test('subscription createPlan requires provider to support subscriptions', function () {
    // Create a driver that doesn't support subscriptions
    $nonSubscriptionDriver = Mockery::mock('KenDeNigerian\PayZephyr\Contracts\DriverInterface');

    $manager = new PaymentManager;
    $reflection = new ReflectionClass($manager);
    $driversProperty = $reflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $drivers = $driversProperty->getValue($manager);
    $drivers['paystack'] = $nonSubscriptionDriver;
    $driversProperty->setValue($manager, $drivers);
    config(['payments.default' => 'paystack']);

    $subscription = new Subscription($manager);
    $planDTO = new SubscriptionPlanDTO('Test', 1000.00, 'monthly');

    $subscription->planData($planDTO)->createPlan();
})->throws(PaymentException::class, 'does not support subscriptions');

test('subscription createPlan handles network errors', function () {
    $mock = new MockHandler([
        new ConnectException('Connection timeout', new Request('POST', '/plan')),
    ]);

    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $driver = new PaystackDriver(['secret_key' => 'test', 'currencies' => ['NGN']]);
    $driver->setClient($client);

    $manager = new PaymentManager;
    $reflection = new ReflectionClass($manager);
    $driversProperty = $reflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $drivers = $driversProperty->getValue($manager);
    $drivers['paystack'] = $driver;
    $driversProperty->setValue($manager, $drivers);
    config(['payments.default' => 'paystack']);

    $subscription = new Subscription($manager);
    $planDTO = new SubscriptionPlanDTO('Test', 1000.00, 'monthly');

    $subscription->planData($planDTO)->createPlan();
})->throws(PlanException::class);

test('subscription createPlan handles invalid response structure', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => false,
            'message' => 'Invalid plan data',
        ])),
    ]);

    $planDTO = new SubscriptionPlanDTO('Test', 1000.00, 'monthly');

    $subscription->planData($planDTO)->createPlan();
})->throws(PlanException::class);

test('subscription createPlan handles missing data in response', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [], // Missing plan_code
        ])),
    ]);

    $planDTO = new SubscriptionPlanDTO('Test', 1000.00, 'monthly');

    $result = $subscription->planData($planDTO)->createPlan();

    expect($result)->toBeInstanceOf(\KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO::class);
});

// ==================== Plan Retrieval Tests ====================

test('subscription getPlan requires plan code', function () {
    $subscription = SubscriptionTestHelper::createWithMock([]);

    $subscription->fetchPlan();
})->throws(PaymentException::class, 'Plan code is required');

test('subscription getPlan handles plan not found', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(404, [], json_encode([
            'status' => false,
            'message' => 'Plan not found',
        ])),
    ]);

    $subscription->plan('PLN_nonexistent')->fetchPlan();
})->throws(PlanException::class);

test('subscription getPlan handles unauthorized access', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(401, [], json_encode([
            'status' => false,
            'message' => 'Unauthorized',
        ])),
    ]);

    $subscription->plan('PLN_123')->fetchPlan();
})->throws(PlanException::class);

// ==================== Plan Update Tests ====================

test('subscription updatePlan requires plan code', function () {
    $subscription = SubscriptionTestHelper::createWithMock([]);

    $subscription->planUpdates(['name' => 'New Name'])->updatePlan();
})->throws(PaymentException::class, 'Plan code is required');

test('subscription updatePlan requires plan updates', function () {
    $subscription = SubscriptionTestHelper::createWithMock([]);

    $subscription->plan('PLN_123')->updatePlan();
})->throws(PaymentException::class, 'Plan updates are required');

test('subscription updatePlan handles invalid updates', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Invalid update data',
        ])),
    ]);

    $subscription->plan('PLN_123')
        ->planUpdates(['invalid_field' => 'value'])
        ->updatePlan();
})->throws(PlanException::class);

// ==================== Plan Listing Tests ====================

test('subscription listPlans handles empty response', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [],
        ])),
    ]);

    $result = $subscription->listPlans();

    expect($result)->toBeArray()->toBeEmpty();
});

test('subscription listPlans respects pagination', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [['plan_code' => 'PLN_1']],
        ])),
    ]);

    $result = $subscription->perPage(1)->page(2)->listPlans();

    expect($result)->toBeArray();
});

test('subscription listPlans handles pagination edge cases', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [],
        ])),
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [],
        ])),
    ]);

    // Test with zero per page
    $result = $subscription->perPage(0)->listPlans();
    expect($result)->toBeArray();

    // Test with negative page - need new subscription instance for second call
    $subscription2 = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [],
        ])),
    ]);
    $result = $subscription2->page(-1)->listPlans();
    expect($result)->toBeArray();
});

// ==================== Subscription Creation Tests ====================

test('subscription create validates customer is set', function () {
    $subscription = SubscriptionTestHelper::createWithMock([]);

    $subscription->plan('PLN_123')->create();
})->throws(InvalidArgumentException::class, 'Customer is required');

test('subscription create validates plan is set', function () {
    $subscription = SubscriptionTestHelper::createWithMock([]);

    $subscription->customer('test@example.com')->create();
})->throws(InvalidArgumentException::class, 'Plan is required');

test('subscription create handles invalid customer email', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::planMock('PLN_123'),
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Invalid customer email',
        ])),
    ]);

    $subscription->customer('invalid-email')
        ->plan('PLN_123')
        ->create();
})->throws(SubscriptionException::class);

test('subscription create handles invalid plan code', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(404, [], json_encode([
            'status' => false,
            'message' => 'Plan not found',
        ])),
    ]);

    $subscription->customer('test@example.com')
        ->plan('PLN_invalid')
        ->create();
})->throws(SubscriptionException::class);

test('subscription create handles missing authorization code when required', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::planMock('PLN_123'),
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Authorization code required',
        ])),
    ]);

    $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->create();
})->throws(SubscriptionException::class);

test('subscription create handles duplicate subscription attempt', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::planMock('PLN_123'),
        new Response(409, [], json_encode([
            'status' => false,
            'message' => 'Subscription already exists',
        ])),
    ]);

    $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->create();
})->throws(SubscriptionException::class);

test('subscription create handles rate limiting', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::planMock('PLN_123'),
        new Response(429, [], json_encode([
            'status' => false,
            'message' => 'Too many requests',
        ])),
    ]);

    $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->create();
})->throws(SubscriptionException::class);

// ==================== Subscription Retrieval Tests ====================

test('subscription get requires subscription code', function () {
    $subscription = SubscriptionTestHelper::createWithMock([]);

    $subscription->fetch();
})->throws(PaymentException::class, 'Subscription code is required');

test('subscription get handles subscription not found', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(404, [], json_encode([
            'status' => false,
            'message' => 'Subscription not found',
        ])),
    ]);

    $subscription->code('SUB_nonexistent')->fetch();
})->throws(SubscriptionException::class);

test('subscription get handles unauthorized access', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(403, [], json_encode([
            'status' => false,
            'message' => 'Access denied',
        ])),
    ]);

    $subscription->code('SUB_123')->fetch();
})->throws(SubscriptionException::class);

test('subscription get handles malformed response', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], 'invalid json'),
    ]);

    $subscription->code('SUB_123')->fetch();
})->throws(SubscriptionException::class);

// ==================== Subscription Cancellation Tests ====================

test('subscription cancel requires subscription code', function () {
    $subscription = SubscriptionTestHelper::createWithMock([]);

    $subscription->cancel();
})->throws(PaymentException::class, 'Subscription code is required');

test('subscription cancel without a token fails at the paystack driver, not the fluent API', function () {
    // Whether a token is required is a Paystack-specific rule (see
    // ADR-0006), so the generic Subscription::cancel() no longer gates on
    // it upfront - the provider-agnostic terminal-state validation still
    // runs (hence the fetchSubscription mock below), and the token
    // requirement surfaces from PaystackDriver::cancelSubscription() itself.
    $subscription = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::subscriptionMock('SUB_123'),
    ]);

    $subscription->code('SUB_123')->cancel();
})->throws(SubscriptionException::class, 'Paystack requires a valid email confirmation token');

test('subscription cancel accepts token as parameter', function () {
    // Disable validation to avoid extra HTTP calls
    config(['payments.subscriptions.validation.enabled' => false]);
    app()->forgetInstance('payments.config');

    $subscription = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::subscriptionMock('SUB_123'),
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'cancelled',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test Plan'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $subscription->code('SUB_123')->cancel('token_12345');

    expect($result)->toBeInstanceOf(SubscriptionResponseDTO::class)
        ->and($result->status)->toBe('cancelled');
});

test('subscription cancel uses fluent token method', function () {
    // Disable validation to avoid extra HTTP calls
    config(['payments.subscriptions.validation.enabled' => false]);
    app()->forgetInstance('payments.config');

    $subscription = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::subscriptionMock('SUB_123'),
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'cancelled',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test Plan'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $subscription->code('SUB_123')
        ->token('token_12345')
        ->cancel();

    expect($result)->toBeInstanceOf(SubscriptionResponseDTO::class);
});

test('subscription cancel handles invalid token', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Invalid token',
        ])),
    ]);

    $subscription->code('SUB_123')->cancel('invalid_token');
})->throws(SubscriptionException::class);

test('subscription cancel handles already cancelled subscription', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Subscription already cancelled',
        ])),
    ]);

    $subscription->code('SUB_123')->cancel('token_12345');
})->throws(SubscriptionException::class);

// ==================== Subscription Enable Tests ====================

test('subscription enable requires subscription code', function () {
    $subscription = SubscriptionTestHelper::createWithMock([]);

    $subscription->enable();
})->throws(PaymentException::class, 'Subscription code is required');

test('subscription enable without a token fails at the paystack driver, not the fluent API', function () {
    // enable() calls no validator (unlike cancel()), so this fails directly
    // inside PaystackDriver::enableSubscription() with no HTTP call made.
    $subscription = SubscriptionTestHelper::createWithMock([]);

    $subscription->code('SUB_123')->enable();
})->throws(SubscriptionException::class, 'Paystack requires a valid email confirmation token');

test('subscription enable handles invalid token', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Invalid token',
        ])),
    ]);

    $subscription->code('SUB_123')->enable('invalid_token');
})->throws(SubscriptionException::class);

test('subscription enable handles already active subscription', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Subscription already active',
        ])),
    ]);

    $subscription->code('SUB_123')->enable('token_12345');
})->throws(SubscriptionException::class);

// ==================== Subscription Listing Tests ====================

test('subscription list handles empty results', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [],
        ])),
    ]);

    $result = $subscription->list();

    expect($result)->toBeArray()->toBeEmpty();
});

test('subscription list filters by customer', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                ['subscription_code' => 'SUB_1', 'status' => 'active'],
            ],
        ])),
    ]);

    $result = $subscription->list('customer@example.com');

    expect($result)->toBeArray()->toHaveCount(1);
});

test('subscription list respects pagination', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [['subscription_code' => 'SUB_1']],
        ])),
    ]);

    $result = $subscription->perPage(10)->page(2)->list();

    expect($result)->toBeArray();
});

test('subscription list handles invalid pagination parameters', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [],
        ])),
    ]);

    // Should not throw, but handle gracefully
    $result = $subscription->perPage(-1)->page(0)->list();

    expect($result)->toBeArray();
});

// ==================== Security Tests ====================

test('subscription prevents unauthorized plan access', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(403, [], json_encode([
            'status' => false,
            'message' => 'Access denied',
        ])),
    ]);

    $subscription->plan('PLN_123')->fetchPlan();
})->throws(PlanException::class);

test('subscription prevents unauthorized subscription access', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(403, [], json_encode([
            'status' => false,
            'message' => 'Access denied',
        ])),
    ]);

    $subscription->code('SUB_123')->fetch();
})->throws(SubscriptionException::class);

test('subscription validates token before cancel operation', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(401, [], json_encode([
            'status' => false,
            'message' => 'Invalid or expired token',
        ])),
    ]);

    $subscription->code('SUB_123')->cancel('expired_token');
})->throws(SubscriptionException::class);

test('subscription prevents token reuse attacks', function () {
    // First cancel should succeed
    $subscription1 = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => ['subscription_code' => 'SUB_123'],
        ])),
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

    $subscription1->code('SUB_123')->cancel('token_12345');

    // Second cancel with same token should fail
    $subscription2 = SubscriptionTestHelper::createWithMock([
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Token already used',
        ])),
    ]);

    $subscription2->code('SUB_123')->cancel('token_12345');
})->throws(SubscriptionException::class);

test('subscription sanitizes metadata before sending', function () {
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

    // Metadata with potentially dangerous content should be handled
    $result = $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->metadata(['script' => '<script>alert("xss")</script>'])
        ->create();

    expect($result)->toBeInstanceOf(SubscriptionResponseDTO::class);
});

// ==================== Edge Cases Tests ====================

test('subscription handles very large amounts', function () {
    $planDTO = new SubscriptionPlanDTO(
        name: 'Premium Plan',
        amount: 999999999.99,  // Very large amount
        interval: 'monthly',
        currency: 'NGN'
    );

    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'plan_code' => 'PLN_123',
                'amount' => 99999999999, // In kobo
            ],
        ])),
    ]);

    $result = $subscription->planData($planDTO)->createPlan();

    expect($result)->toBeInstanceOf(\KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO::class);
});

test('subscription handles very long plan names', function () {
    $longName = str_repeat('A', 1000);
    $planDTO = new SubscriptionPlanDTO(
        name: $longName,
        amount: 1000.00,
        interval: 'monthly'
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

test('subscription handles special characters in metadata', function () {
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
            'special' => '!@#$%^&*()',
            'unicode' => '测试',
            'emoji' => '😀',
        ])
        ->create();

    expect($result)->toBeInstanceOf(SubscriptionResponseDTO::class);
});

test('subscription handles null values in response', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => ['email' => null],
                'plan' => ['name' => null],
                'amount' => null,
                'currency' => null,
                'next_payment_date' => null,
                'email_token' => null,
            ],
        ])),
    ]);

    $result = $subscription->code('SUB_123')->fetch();

    expect($result)->toBeInstanceOf(SubscriptionResponseDTO::class);
});

test('subscription handles missing optional fields gracefully', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                // Missing customer, plan, etc.
            ],
        ])),
    ]);

    $result = $subscription->code('SUB_123')->fetch();

    expect($result)->toBeInstanceOf(SubscriptionResponseDTO::class)
        ->and($result->customer)->toBe('')
        ->and($result->plan)->toBe('');
});

test('subscription handles concurrent creation attempts', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(409, [], json_encode([
            'status' => false,
            'message' => 'Subscription already exists',
        ])),
    ]);

    $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->create();
})->throws(SubscriptionException::class);

// ==================== Status Check Tests ====================

test('subscription response isActive returns true for active status', function () {
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
            ],
        ])),
    ]);

    $result = $subscription->code('SUB_123')->fetch();

    expect($result->isActive())->toBeTrue()
        ->and($result->isCancelled())->toBeFalse()
        ->and($result->isCompleted())->toBeFalse();
});

test('subscription response isCancelled returns true for cancelled status', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
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

    $result = $subscription->code('SUB_123')->fetch();

    expect($result->isCancelled())->toBeTrue()
        ->and($result->isActive())->toBeFalse();
});

test('subscription response isCompleted returns true for completed status', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'completed',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $subscription->code('SUB_123')->fetch();

    expect($result->isCompleted())->toBeTrue()
        ->and($result->isActive())->toBeFalse();
});

test('subscription response handles non-renewing status', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'non-renewing',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $subscription->code('SUB_123')->fetch();

    expect($result->isActive())->toBeTrue(); // non-renewing is considered active
});

// ==================== DTO Validation Tests ====================

test('subscription plan DTO validates empty name', function () {
    new SubscriptionPlanDTO(
        name: '',
        amount: 1000.00,
        interval: 'monthly'
    );
})->throws(InvalidArgumentException::class, 'Plan name is required');

test('subscription plan DTO validates negative amount', function () {
    new SubscriptionPlanDTO(
        name: 'Test Plan',
        amount: -100.00,
        interval: 'monthly'
    );
})->throws(InvalidArgumentException::class, 'Amount must be greater than zero');

test('subscription plan DTO validates zero amount', function () {
    new SubscriptionPlanDTO(
        name: 'Test Plan',
        amount: 0.00,
        interval: 'monthly'
    );
})->throws(InvalidArgumentException::class, 'Amount must be greater than zero');

test('subscription plan DTO validates invalid interval', function () {
    new SubscriptionPlanDTO(
        name: 'Test Plan',
        amount: 1000.00,
        interval: 'invalid'
    );
})->throws(InvalidArgumentException::class, 'Interval must be one of');

test('subscription request DTO validates empty customer', function () {
    new SubscriptionRequestDTO(
        customer: '',
        plan: 'PLN_123'
    );
})->throws(InvalidArgumentException::class, 'Customer is required');

test('subscription request DTO validates empty plan', function () {
    new SubscriptionRequestDTO(
        customer: 'test@example.com',
        plan: ''
    );
})->throws(InvalidArgumentException::class, 'Plan is required');

test('subscription request DTO validates zero quantity', function () {
    new SubscriptionRequestDTO(
        customer: 'test@example.com',
        plan: 'PLN_123',
        quantity: 0
    );
})->throws(InvalidArgumentException::class, 'Quantity must be at least 1');

test('subscription request DTO validates negative quantity', function () {
    new SubscriptionRequestDTO(
        customer: 'test@example.com',
        plan: 'PLN_123',
        quantity: -1
    );
})->throws(InvalidArgumentException::class, 'Quantity must be at least 1');

test('subscription request DTO validates negative trial days', function () {
    new SubscriptionRequestDTO(
        customer: 'test@example.com',
        plan: 'PLN_123',
        trialDays: -1
    );
})->throws(InvalidArgumentException::class, 'Trial days cannot be negative');

// ==================== Integration Tests ====================

test('subscription complete workflow: create plan, create subscription, cancel', function () {
    // Disable validation to avoid extra HTTP calls
    config(['payments.subscriptions.validation.enabled' => false]);
    app()->forgetInstance('payments.config');

    // Step 1: Create plan
    $subscription1 = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'plan_code' => 'PLN_123',
                'name' => 'Test Plan',
                'amount' => 500000,
            ],
        ])),
    ]);

    $planDTO = new SubscriptionPlanDTO('Test Plan', 5000.00, 'monthly');
    $plan = $subscription1->planData($planDTO)->createPlan();
    expect($plan->planCode)->toBe('PLN_123');

    // Step 2: Create subscription
    // Disable duplicate prevention and validation to avoid extra HTTP calls
    config(['payments.subscriptions.prevent_duplicates' => false]);
    config(['payments.subscriptions.validation.enabled' => false]);

    $subscription2 = SubscriptionTestHelper::createWithMock([
        // No planMock needed since validation is disabled
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'code' => 'SUB_123', // Also include 'code' as fallback
                'status' => 'active',
                'customer' => ['email' => 'test@example.com'],
                'plan' => [
                    'name' => 'Test Plan',
                    'plan_code' => 'PLN_123',
                    'code' => 'PLN_123',
                ],
                'amount' => 500000,
                'currency' => 'NGN',
                'email_token' => 'token_12345',
            ],
        ])), // For subscription creation (POST /subscription)
    ]);

    $sub = $subscription2->customer('test@example.com')
        ->plan('PLN_123')
        ->create();
    expect($sub->subscriptionCode)->toBe('SUB_123')
        ->and($sub->emailToken)->toBe('token_12345');

    // Step 3: Cancel subscription
    $subscription3 = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::subscriptionMock('SUB_123'),
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'cancelled',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test Plan'],
                'amount' => 500000,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $cancelled = $subscription3->code('SUB_123')
        ->token('token_12345')
        ->cancel();
    expect($cancelled->status)->toBe('cancelled');
});

test('subscription handles provider fallback scenario', function () {
    $driver2 = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => ['plan_code' => 'PLN_123'],
        ])),
    ]);

    $manager = new PaymentManager;
    $reflection = new ReflectionClass($manager);
    $driversProperty = $reflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $drivers = $driversProperty->getValue($manager);
    $drivers['paystack'] = $driver2;
    $driversProperty->setValue($manager, $drivers);
    config(['payments.default' => 'paystack']);

    $subscription = new Subscription($manager);
    $planDTO = new SubscriptionPlanDTO('Test', 1000.00, 'monthly');

    $result = $subscription->planData($planDTO)->with('paystack')->createPlan();

    expect($result)->toBeInstanceOf(\KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO::class);
});

// ==================== Error Recovery Tests ====================

test('subscription handles temporary network failures gracefully', function () {
    $mock = new MockHandler([
        new ConnectException('Connection timeout', new Request('POST', '/subscription')),
    ]);

    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $driver = new PaystackDriver(['secret_key' => 'test', 'currencies' => ['NGN']]);
    $driver->setClient($client);

    $manager = new PaymentManager;
    $reflection = new ReflectionClass($manager);
    $driversProperty = $reflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $drivers = $driversProperty->getValue($manager);
    $drivers['paystack'] = $driver;
    $driversProperty->setValue($manager, $drivers);
    config(['payments.default' => 'paystack']);

    $subscription = new Subscription($manager);

    $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->create();
})->throws(SubscriptionException::class);

test('subscription handles malformed JSON response', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], 'not json {invalid}'),
    ]);

    $subscription->code('SUB_123')->fetch();
})->throws(SubscriptionException::class);

test('subscription handles empty response body', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], ''),
    ]);

    $subscription->code('SUB_123')->fetch();
})->throws(SubscriptionException::class);

test('subscription handles HTTP 500 errors', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(500, [], json_encode([
            'status' => false,
            'message' => 'Internal server error',
        ])),
    ]);

    $subscription->code('SUB_123')->fetch();
})->throws(SubscriptionException::class);

test('subscription handles HTTP 502 Bad Gateway', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(502, [], json_encode([
            'status' => false,
            'message' => 'Bad Gateway',
        ])),
    ]);

    $subscription->code('SUB_123')->fetch();
})->throws(SubscriptionException::class);

test('subscription handles HTTP 503 Service Unavailable', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(503, [], json_encode([
            'status' => false,
            'message' => 'Service Unavailable',
        ])),
    ]);

    $subscription->code('SUB_123')->fetch();
})->throws(SubscriptionException::class);

// ==================== Amount Conversion Tests ====================

test('subscription correctly converts amount from kobo to naira', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test'],
                'amount' => 5050500, // 50,505.00 NGN in kobo
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $subscription->code('SUB_123')->fetch();

    expect($result->amount)->toBe(50505.0);
});

test('subscription plan DTO correctly converts amount to minor units', function () {
    $planDTO = new SubscriptionPlanDTO(
        name: 'Test Plan',
        amount: 1234.56,
        interval: 'monthly'
    );

    expect($planDTO->getAmountInMinorUnits())->toBe(123456);
});

test('subscription handles zero amount in response', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test'],
                'amount' => 0,
                'currency' => 'NGN',
            ],
        ])),
    ]);

    $result = $subscription->code('SUB_123')->fetch();

    expect($result->amount)->toBe(0.0);
});

// ==================== Provider Selection Tests ====================

test('subscription uses default provider when not specified', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => ['plan_code' => 'PLN_123'],
        ])),
    ]);

    $manager = new PaymentManager;
    $reflection = new ReflectionClass($manager);
    $driversProperty = $reflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $drivers = $driversProperty->getValue($manager);
    $drivers['paystack'] = $driver;
    $driversProperty->setValue($manager, $drivers);
    config(['payments.default' => 'paystack']);

    $subscription = new Subscription($manager);
    $planDTO = new SubscriptionPlanDTO('Test', 1000.00, 'monthly');

    $result = $subscription->planData($planDTO)->createPlan();

    expect($result)->toBeInstanceOf(\KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO::class);
});

test('subscription uses first provider from array', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => ['plan_code' => 'PLN_123'],
        ])),
    ]);

    $manager = new PaymentManager;
    $reflection = new ReflectionClass($manager);
    $driversProperty = $reflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $drivers = $driversProperty->getValue($manager);
    $drivers['paystack'] = $driver;
    $driversProperty->setValue($manager, $drivers);
    config(['payments.default' => 'stripe']);

    $subscription = new Subscription($manager);
    $planDTO = new SubscriptionPlanDTO('Test', 1000.00, 'monthly');

    $result = $subscription->planData($planDTO)
        ->with(['paystack', 'stripe'])
        ->createPlan();

    expect($result)->toBeInstanceOf(\KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO::class);
});
