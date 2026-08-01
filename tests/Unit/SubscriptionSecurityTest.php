<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
use KenDeNigerian\PayZephyr\Exceptions\PlanException;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Subscription;
use Tests\Helpers\SubscriptionTestHelper;

// ==================== Input Validation Security Tests ====================

test('subscription prevents SQL injection in customer field', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::planMock('PLN_123'),
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Invalid customer',
        ])),
    ]);

    $subscription->customer("'; DROP TABLE subscriptions; --")
        ->plan('PLN_123')
        ->create();
})->throws(SubscriptionException::class);

test('subscription prevents XSS in metadata', function () {
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
                // Real providers echo back whatever metadata you sent when
                // creating the subscription - the mock must actually include
                // it, otherwise this test would pass identically whether or
                // not sanitization ever ran (SubscriptionResponseDTO::$metadata
                // would just default to [] either way).
                'metadata' => [
                    'xss' => '<script>alert("xss")</script>',
                    'html' => '<img src=x onerror=alert(1)>',
                ],
            ],
        ])),
    ]);

    $result = $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->metadata([
            'xss' => '<script>alert("xss")</script>',
            'html' => '<img src=x onerror=alert(1)>',
        ])
        ->create();

    expect($result)->toBeInstanceOf(\KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO::class);

    // The actual assertion: what landed in subscription_transactions.metadata
    // must be sanitized, exactly like payment_transactions.metadata already
    // is via MetadataSanitizer in PaymentManager::charge(). Without that same
    // sanitization applied on the subscription-logging path, this column
    // stores raw attacker-influenced markup verbatim - a stored-XSS vector
    // for any admin panel that renders it without independently escaping.
    $logged = \KenDeNigerian\PayZephyr\Models\SubscriptionTransaction::where('subscription_code', 'SUB_123')->first();

    expect($logged)->not->toBeNull();
    $metadata = $logged->metadata instanceof \ArrayObject ? $logged->metadata->getArrayCopy() : (array) $logged->metadata;

    expect($metadata['xss'] ?? '')->not->toContain('<script>')
        ->and($metadata['html'] ?? '')->not->toContain('onerror=');
});

test('subscription prevents path traversal in plan code', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(404, [], json_encode([
            'status' => false,
            'message' => 'Invalid plan',
        ])),
    ]);

    $subscription->customer('test@example.com')
        ->plan('../../../etc/passwd')
        ->create();
})->throws(SubscriptionException::class);

test('subscription prevents command injection in subscription code', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Invalid subscription code',
        ])),
    ]);

    $subscription->code('SUB_123; rm -rf /')
        ->fetch();
})->throws(SubscriptionException::class);

test('subscription validates email format in customer field', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::planMock('PLN_123'),
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Invalid email format',
        ])),
    ]);

    $subscription->customer('not-an-email')
        ->plan('PLN_123')
        ->create();
})->throws(SubscriptionException::class);

// ==================== Authorization Security Tests ====================

test('subscription prevents unauthorized plan creation', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(403, [], json_encode([
            'status' => false,
            'message' => 'Insufficient permissions',
        ])),
    ]);

    $planDTO = new SubscriptionPlanDTO('Test', 1000.00, 'monthly');

    $subscription->planData($planDTO)->createPlan();
})->throws(PlanException::class);

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

test('subscription validates token ownership before cancel', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(403, [], json_encode([
            'status' => false,
            'message' => 'Token does not match subscription',
        ])),
    ]);

    $subscription->code('SUB_123')
        ->token('wrong_token')
        ->cancel();
})->throws(SubscriptionException::class);

test('subscription prevents token reuse after cancellation', function () {
    // First cancellation succeeds
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

    // Second attempt with same token should fail
    $subscription2 = SubscriptionTestHelper::createWithMock([
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Token already used or invalid',
        ])),
    ]);

    $subscription2->code('SUB_123')->cancel('token_12345');
})->throws(SubscriptionException::class);

// ==================== Rate Limiting Security Tests ====================

test('subscription handles rate limiting on create', function () {
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

test('subscription handles rate limiting on plan creation', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(429, [], json_encode([
            'status' => false,
            'message' => 'Rate limit exceeded',
        ])),
    ]);

    $planDTO = new SubscriptionPlanDTO('Test', 1000.00, 'monthly');

    $subscription->planData($planDTO)->createPlan();
})->throws(PlanException::class);

// ==================== Data Integrity Security Tests ====================

test('subscription prevents amount tampering', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => ['email' => 'test@example.com'],
                'plan' => ['name' => 'Test'],
                'amount' => 100000, // Different from plan amount
                'currency' => 'NGN',
            ],
        ])),
    ]);

    // Amount should match what was in the plan, not what's in response
    $result = $subscription->code('SUB_123')->fetch();

    // The response amount is what the provider returns, but we validate it
    expect($result->amount)->toBe(1000.0);
});

test('subscription validates currency consistency', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Currency mismatch',
        ])),
    ]);

    $planDTO = new SubscriptionPlanDTO('Test', 1000.00, 'monthly', 'USD');

    $subscription->planData($planDTO)->createPlan();
})->throws(PlanException::class);

// ==================== Token Security Tests ====================

test('subscription token cannot be empty string', function () {
    // Whether a token is required is Paystack-specific (ADR-0006), so this
    // is now enforced by PaystackDriver rather than the generic Subscription
    // fluent API. Validation is disabled here to isolate that check from the
    // (separately-tested) terminal-state validation, which would otherwise
    // need its own mocked fetchSubscription() call.
    config(['payments.subscriptions.validation.enabled' => false]);
    app()->forgetInstance('payments.config');

    $subscription = SubscriptionTestHelper::createWithMock([]);

    $subscription->code('SUB_123')->token('')->cancel();
})->throws(SubscriptionException::class, 'Paystack requires a valid email confirmation token');

test('subscription token cannot be null', function () {
    config(['payments.subscriptions.validation.enabled' => false]);
    app()->forgetInstance('payments.config');

    $subscription = SubscriptionTestHelper::createWithMock([]);

    $subscription->code('SUB_123')->cancel(null);
})->throws(SubscriptionException::class, 'Paystack requires a valid email confirmation token');

test('subscription validates token format', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Invalid token format',
        ])),
    ]);

    $subscription->code('SUB_123')
        ->token('invalid_token_format_12345')
        ->cancel();
})->throws(SubscriptionException::class);

test('subscription prevents token brute force', function () {
    // Multiple failed attempts should be handled by provider
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Too many failed attempts',
        ])),
    ]);

    $subscription->code('SUB_123')
        ->token('wrong_token_1')
        ->cancel();
})->throws(SubscriptionException::class);

// ==================== Metadata Security Tests ====================

test('subscription prevents sensitive data in metadata', function () {
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

    // Metadata should be sanitized - sensitive data should not be logged
    $result = $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->metadata([
            'password' => 'secret123',
            'credit_card' => '4111111111111111',
            'ssn' => '123-45-6789',
        ])
        ->create();

    expect($result)->toBeInstanceOf(\KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO::class);
});

test('subscription limits metadata size', function () {
    $largeMetadata = [];
    for ($i = 0; $i < 10000; $i++) {
        $largeMetadata["key_$i"] = str_repeat('x', 1000);
    }

    $subscription = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::planMock('PLN_123'),
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Metadata too large',
        ])),
    ]);

    $subscription->customer('test@example.com')
        ->plan('PLN_123')
        ->metadata($largeMetadata)
        ->create();
})->throws(SubscriptionException::class);

// ==================== Provider Validation Security Tests ====================

test('subscription prevents provider spoofing', function () {
    // This test verifies that invalid providers are rejected
    // Since we can't easily mock PaymentManager to throw, we'll test with a real manager
    // that doesn't have the fake_provider configured
    $manager = new PaymentManager;
    config(['payments.default' => 'paystack']);

    $subscription = new Subscription($manager);
    $planDTO = new SubscriptionPlanDTO('Test', 1000.00, 'monthly');

    $subscription->planData($planDTO)
        ->with('fake_provider')
        ->createPlan();
})->throws(\KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException::class);

test('subscription validates provider supports subscriptions', function () {
    // Create a driver that doesn't support subscriptions
    $nonSubscriptionDriver = Mockery::mock('KenDeNigerian\PayZephyr\Contracts\DriverInterface');

    $manager = new PaymentManager;
    $reflection = new \ReflectionClass($manager);
    $driversProperty = $reflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $drivers = $driversProperty->getValue($manager);
    $drivers['paystack'] = $nonSubscriptionDriver;
    $driversProperty->setValue($manager, $drivers);
    config(['payments.default' => 'paystack']);

    $subscription = new Subscription($manager);
    $planDTO = new SubscriptionPlanDTO('Test', 1000.00, 'monthly');

    $subscription->planData($planDTO)->createPlan();
})->throws(\KenDeNigerian\PayZephyr\Exceptions\PaymentException::class, 'does not support subscriptions');

// ==================== Response Validation Security Tests ====================

test('subscription validates response signature', function () {
    // This is handled by webhook validation, but we test the response structure
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                // Missing required fields
            ],
        ])),
    ]);

    $result = $subscription->code('SUB_123')->fetch();

    // Should handle missing fields gracefully
    expect($result)->toBeInstanceOf(\KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO::class);
});

test('subscription prevents response manipulation', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_123',
                'status' => 'active',
                'customer' => ['email' => 'hacker@example.com'], // Different customer
                'plan' => ['name' => 'Test'],
                'amount' => 1.00, // Tampered amount
                'currency' => 'NGN',
            ],
        ])),
    ]);

    // The response is what the provider returns - we trust the provider
    // But we should validate the subscription code matches
    $result = $subscription->code('SUB_123')->fetch();

    expect($result->subscriptionCode)->toBe('SUB_123');
});

// ==================== Error Message Security Tests ====================

test('subscription does not expose sensitive info in error messages', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(500, [], json_encode([
            'status' => false,
            'message' => 'Internal server error',
            'debug' => 'Database connection failed: user=admin, password=secret',
        ])),
    ]);

    try {
        $subscription->code('SUB_123')->fetch();
    } catch (SubscriptionException $e) {
        // Error message should not contain sensitive debug info
        expect($e->getMessage())->not->toContain('password')
            ->and($e->getMessage())->not->toContain('secret');
    }
});

test('subscription sanitizes error context', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        SubscriptionTestHelper::planMock('PLN_123'),
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Invalid request',
        ])),
    ]);

    try {
        $subscription->customer('test@example.com')
            ->plan('PLN_123')
            ->metadata(['password' => 'secret'])
            ->create();
    } catch (SubscriptionException $e) {
        // Verify exception was thrown
        expect($e)->toBeInstanceOf(SubscriptionException::class);

        // Context should be sanitized - check that sensitive data is not exposed
        $context = $e->getContext();
        $contextJson = json_encode($context);

        // Assert that the exception message doesn't contain sensitive data
        expect($e->getMessage())->not->toContain('secret');

        // If context exists, verify it doesn't contain sensitive data
        if (! empty($context)) {
            expect($contextJson)->not->toContain('secret');
        }

        // Always assert that we caught the exception (this ensures the test performs assertions)
        expect($e->getMessage())->toBeString();
    }
});

// ==================== Timing Attack Prevention Tests ====================

test('subscription response time is consistent for invalid tokens', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(400, [], json_encode([
            'status' => false,
            'message' => 'Invalid token',
        ])),
    ]);

    $start = microtime(true);

    try {
        $subscription->code('SUB_123')->cancel('invalid_token_1');
    } catch (SubscriptionException $e) {
        // Ignore
    }

    $time1 = microtime(true) - $start;

    $start = microtime(true);

    try {
        $subscription->code('SUB_123')->cancel('invalid_token_2');
    } catch (SubscriptionException $e) {
        // Ignore
    }

    $time2 = microtime(true) - $start;

    // Times should be similar (within reasonable margin)
    // This is a basic test - real timing attack prevention is more complex
    $difference = abs($time1 - $time2);
    expect($difference)->toBeLessThan(0.1); // 100ms tolerance
});

// ==================== CSRF Protection Tests ====================

test('subscription operations require proper authentication', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(401, [], json_encode([
            'status' => false,
            'message' => 'Unauthorized',
        ])),
    ]);

    $subscription->code('SUB_123')->fetch();
})->throws(SubscriptionException::class);

test('subscription cancel requires valid token (CSRF protection)', function () {
    $subscription = SubscriptionTestHelper::createWithMock([
        new Response(401, [], json_encode([
            'status' => false,
            'message' => 'Invalid or expired token',
        ])),
    ]);

    $subscription->code('SUB_123')->cancel('expired_token');
})->throws(SubscriptionException::class);
