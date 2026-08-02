<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionActionDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\Exceptions\PlanException;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;
use Tests\Helpers\PaystackDriverTestHelper;

/**
 * The existing "throws exception" tests in PaystackSubscriptionTest.php for
 * fetchPlan/listPlans/enableSubscription/listSubscriptions all use a non-2xx
 * HTTP status code (404/500/400) on the mocked Response. Guzzle's default
 * http_errors middleware throws a RequestException/ServerException for those
 * BEFORE the driver ever parses the JSON body, so control never reaches the
 * `if (! ($data['status'] ?? false))` check inside the try block - it's
 * caught instead by the driver's own makeRequest() Guzzle handler and
 * rethrown as a ChargeException, which flows through the *generic*
 * `catch (Throwable $e)` branch, not the specific
 * `catch (PlanException|SubscriptionException $e) { throw $e; }` rethrow.
 *
 * These tests use HTTP 200 with a JSON `status: false` body instead, which
 * is the only way to actually reach the JSON-status check and its adjacent
 * specific-exception rethrow.
 */
test('paystack fetchPlan throws PlanException from the JSON status check (not an HTTP error)', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => false,
            'message' => 'Plan does not exist',
        ])),
    ]);

    $driver->fetchPlan('PLN_missing');
})->throws(PlanException::class, 'Plan does not exist');

test('paystack listPlans throws PlanException from the JSON status check (not an HTTP error)', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => false,
            'message' => 'Could not list plans',
        ])),
    ]);

    $driver->listPlans();
})->throws(PlanException::class, 'Could not list plans');

test('paystack enableSubscription throws SubscriptionException from the JSON status check (not an HTTP error)', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => false,
            'message' => 'Token has expired',
        ])),
    ]);

    $driver->enableSubscription(new SubscriptionActionDTO('SUB_test123', ['token' => 'expired_token_1234']));
})->throws(SubscriptionException::class, 'Token has expired');

test('paystack listSubscriptions throws SubscriptionException from the JSON status check (not an HTTP error)', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => false,
            'message' => 'Could not list subscriptions',
        ])),
    ]);

    $driver->listSubscriptions();
})->throws(SubscriptionException::class, 'Could not list subscriptions');

test('paystack createSubscription throws when the response has no subscription_code or code field', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                // Deliberately missing both 'subscription_code' and 'code'.
                'status' => 'active',
                'customer' => ['email' => 'customer@example.com'],
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

    $driver->createSubscription($request);
})->throws(SubscriptionException::class, 'Subscription code not found in response');

test('paystack cancelSubscription wraps a non-domain (network/HTTP) failure via the generic catch branch', function () {
    // A 500 on the disable endpoint itself makes Guzzle throw a
    // ServerException before any JSON body is parsed. makeRequest() turns
    // that into a ChargeException, which is not a SubscriptionException, so
    // it is caught by cancelSubscription()'s generic `catch (Throwable $e)`
    // branch rather than the specific SubscriptionException rethrow.
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(500, [], json_encode(['status' => false, 'message' => 'Internal server error'])),
    ]);

    $driver->cancelSubscription(new SubscriptionActionDTO('SUB_test123', ['token' => 'token_abc123']));
})->throws(SubscriptionException::class, 'Failed to cancel subscription:');
