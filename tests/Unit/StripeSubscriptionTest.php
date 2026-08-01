<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\SubscriptionActionDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\StripeDriver;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;

function stripeObj(array $data): object
{
    return json_decode(json_encode($data), false);
}

function makeStripeDriverWithClient(object $client): StripeDriver
{
    $driver = new StripeDriver(['secret_key' => 'sk_test', 'currencies' => ['USD']]);
    $driver->setStripeClient($client);

    return $driver;
}

test('stripe createPlan creates a product and a price', function () {
    $productsResource = new class
    {
        public function create(array $params)
        {
            return stripeObj(['id' => 'prod_123', 'name' => $params['name'], 'description' => $params['description'] ?? null]);
        }
    };
    $pricesResource = new class
    {
        public function create(array $params)
        {
            return stripeObj([
                'id' => 'price_123',
                'unit_amount' => $params['unit_amount'],
                'currency' => $params['currency'],
                'recurring' => ['interval' => $params['recurring']['interval']],
                'metadata' => $params['metadata'] ?? [],
            ]);
        }
    };

    $client = new class($productsResource, $pricesResource)
    {
        public function __construct(public object $products, public object $prices) {}
    };

    $driver = makeStripeDriverWithClient($client);
    $plan = new SubscriptionPlanDTO('Pro Plan', 20.00, 'monthly', 'USD');

    $result = $driver->createPlan($plan);

    expect($result->planCode)->toBe('price_123')
        ->and($result->name)->toBe('Pro Plan')
        ->and($result->amount)->toBe(20.00)
        ->and($result->interval)->toBe('monthly')
        ->and($result->currency)->toBe('USD');
});

test('stripe fetchPlan retrieves and maps a price with its product', function () {
    $pricesResource = new class
    {
        public function retrieve($id, $params = [])
        {
            return stripeObj([
                'id' => $id,
                'unit_amount' => 1000,
                'currency' => 'usd',
                'recurring' => ['interval' => 'year'],
                'metadata' => [],
                'product' => ['id' => 'prod_123', 'name' => 'Annual Plan', 'description' => null],
            ]);
        }
    };

    $client = new class($pricesResource)
    {
        public function __construct(public object $prices) {}
    };

    $driver = makeStripeDriverWithClient($client);
    $result = $driver->fetchPlan('price_abc');

    expect($result->planCode)->toBe('price_abc')
        ->and($result->name)->toBe('Annual Plan')
        ->and($result->interval)->toBe('annually')
        ->and($result->currency)->toBe('USD');
});

test('stripe createSubscription requires an authorization (payment method)', function () {
    $client = new class {};
    $driver = makeStripeDriverWithClient($client);

    $request = new SubscriptionRequestDTO(customer: 'test@example.com', plan: 'price_123');

    $driver->createSubscription($request);
})->throws(SubscriptionException::class, 'Stripe requires an existing PaymentMethod ID');

test('stripe createSubscription succeeds with an authorization and finds an existing customer', function () {
    $customersResource = new class
    {
        public function all(array $params)
        {
            return stripeObj(['data' => [['id' => 'cus_123', 'email' => $params['email']]]]);
        }
    };
    $subscriptionsResource = new class
    {
        public function create(array $params, array $options = [])
        {
            return stripeObj([
                'id' => 'sub_123',
                'status' => 'active',
                'customer' => $params['customer'],
                'current_period_end' => 1735689600,
                'metadata' => $params['metadata'] ?? [],
                'items' => ['data' => [
                    ['price' => ['id' => $params['items'][0]['price'], 'unit_amount' => 2000, 'currency' => 'usd']],
                ]],
            ]);
        }
    };

    $client = new class($customersResource, $subscriptionsResource)
    {
        public function __construct(public object $customers, public object $subscriptions) {}
    };

    $driver = makeStripeDriverWithClient($client);
    $request = new SubscriptionRequestDTO(
        customer: 'test@example.com',
        plan: 'price_123',
        authorization: 'pm_123'
    );

    $result = $driver->createSubscription($request);

    expect($result->subscriptionCode)->toBe('sub_123')
        ->and($result->status)->toBe('active')
        ->and($result->getStatus())->toBe(\KenDeNigerian\PayZephyr\Enums\SubscriptionStatus::ACTIVE)
        ->and($result->plan)->toBe('price_123')
        ->and($result->amount)->toBe(20.0);
});

test('stripe cancelSubscription cancels immediately by default', function () {
    $subscriptionsResource = new class
    {
        public function cancel($id)
        {
            return stripeObj([
                'id' => $id,
                'status' => 'canceled',
                'metadata' => [],
                'items' => ['data' => []],
            ]);
        }
    };

    $client = new class($subscriptionsResource)
    {
        public function __construct(public object $subscriptions) {}
    };

    $driver = makeStripeDriverWithClient($client);
    $result = $driver->cancelSubscription(new SubscriptionActionDTO('sub_123'));

    // Stripe's native spelling ('canceled') passes through as-is; getStatus()
    // is what interprets it via Enums\SubscriptionStatus, which already
    // recognizes both spellings.
    expect($result->status)->toBe('canceled')
        ->and($result->getStatus())->toBe(\KenDeNigerian\PayZephyr\Enums\SubscriptionStatus::CANCELLED)
        ->and($result->isCancelled())->toBeTrue();
});

test('stripe cancelSubscription schedules cancellation at period end when requested', function () {
    $subscriptionsResource = new class
    {
        public function update($id, array $params)
        {
            expect($params)->toBe(['cancel_at_period_end' => true]);

            return stripeObj(['id' => $id, 'status' => 'active', 'metadata' => [], 'items' => ['data' => []]]);
        }
    };

    $client = new class($subscriptionsResource)
    {
        public function __construct(public object $subscriptions) {}
    };

    $driver = makeStripeDriverWithClient($client);
    $driver->cancelSubscription(new SubscriptionActionDTO('sub_123', ['at_period_end' => true]));
});

test('stripe enableSubscription throws when the subscription is already fully cancelled', function () {
    $subscriptionsResource = new class
    {
        public function retrieve($id)
        {
            return stripeObj(['id' => $id, 'status' => 'canceled']);
        }
    };

    $client = new class($subscriptionsResource)
    {
        public function __construct(public object $subscriptions) {}
    };

    $driver = makeStripeDriverWithClient($client);
    $driver->enableSubscription(new SubscriptionActionDTO('sub_123'));
})->throws(SubscriptionException::class, 'cannot be reactivated on Stripe');

test('stripe enableSubscription resumes a subscription pending cancel_at_period_end', function () {
    $subscriptionsResource = new class
    {
        public function retrieve($id)
        {
            return stripeObj(['id' => $id, 'status' => 'active']);
        }

        public function update($id, array $params)
        {
            expect($params)->toBe(['cancel_at_period_end' => false]);

            return stripeObj(['id' => $id, 'status' => 'active', 'metadata' => [], 'items' => ['data' => []]]);
        }
    };

    $client = new class($subscriptionsResource)
    {
        public function __construct(public object $subscriptions) {}
    };

    $driver = makeStripeDriverWithClient($client);
    $result = $driver->enableSubscription(new SubscriptionActionDTO('sub_123'));

    expect($result->status)->toBe('active')
        ->and($result->isActive())->toBeTrue();
});
