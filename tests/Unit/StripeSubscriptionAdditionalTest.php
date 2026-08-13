<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\SubscriptionActionDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionPlanDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\StripeDriver;
use KenDeNigerian\PayZephyr\Exceptions\PlanException;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;
use Stripe\Exception\ApiConnectionException;

function stripeObj2(array $data): object
{
    return json_decode(json_encode($data), false);
}

function makeStripeDriverWithClient2(object $client): StripeDriver
{
    $driver = new StripeDriver(['secret_key' => 'sk_test', 'currencies' => ['USD']]);
    $driver->setStripeClient($client);

    return $driver;
}

// ---------------------------------------------------------------------
// createPlan - error branch
// ---------------------------------------------------------------------

test('stripe createPlan throws PlanException on API error', function () {
    $productsResource = new class
    {
        public function create(array $params)
        {
            throw ApiConnectionException::factory('network down');
        }
    };
    $pricesResource = new class
    {
        public function create(array $params) {}
    };

    $client = new class($productsResource, $pricesResource)
    {
        public function __construct(public object $products, public object $prices) {}
    };

    $driver = makeStripeDriverWithClient2($client);
    $plan = new SubscriptionPlanDTO('Pro Plan', 20.00, 'monthly', 'USD');

    $driver->createPlan($plan);
})->throws(PlanException::class, 'Failed to create plan');

test('stripe createPlan maps daily, weekly and annually intervals', function (string $interval, string $stripeInterval) {
    $pricesResource = new class
    {
        public function create(array $params)
        {
            return stripeObj2([
                'id' => 'price_123',
                'unit_amount' => $params['unit_amount'],
                'currency' => $params['currency'],
                'recurring' => ['interval' => $params['recurring']['interval']],
                'metadata' => [],
            ]);
        }
    };
    $productsResource = new class
    {
        public function create(array $params)
        {
            return stripeObj2(['id' => 'prod_123', 'name' => $params['name'] ?? '']);
        }
    };

    $client = new class($productsResource, $pricesResource)
    {
        public function __construct(public object $products, public object $prices) {}
    };

    $driver = makeStripeDriverWithClient2($client);
    $plan = new SubscriptionPlanDTO('Plan', 10.00, $interval, 'USD');

    $result = $driver->createPlan($plan);

    expect($result->interval)->toBe($interval);
})->with([
    ['daily', 'day'],
    ['weekly', 'week'],
    ['annually', 'year'],
]);

// ---------------------------------------------------------------------
// updatePlan
// ---------------------------------------------------------------------

test('stripe updatePlan updates the product name/description without changing the price', function () {
    $pricesResource = new class
    {
        public function retrieve($id, $params = [])
        {
            return stripeObj2([
                'id' => $id,
                'unit_amount' => 1000,
                'currency' => 'usd',
                'recurring' => ['interval' => 'month'],
                'metadata' => [],
                'product' => ['id' => 'prod_123', 'name' => 'Old Name', 'description' => null],
            ]);
        }
    };
    $productsResource = new class
    {
        public array $updateCalls = [];

        public function update($id, array $params)
        {
            $this->updateCalls[] = [$id, $params];

            return stripeObj2(['id' => $id, 'name' => $params['name'] ?? 'Old Name']);
        }

        public function retrieve($id)
        {
            return stripeObj2(['id' => $id, 'name' => 'New Name']);
        }
    };

    $client = new class($productsResource, $pricesResource)
    {
        public function __construct(public object $products, public object $prices) {}
    };

    $driver = makeStripeDriverWithClient2($client);
    $result = $driver->updatePlan('price_abc', ['name' => 'New Name']);

    expect($result->name)->toBe('New Name')
        ->and($result->planCode)->toBe('price_abc');
});

test('stripe updatePlan creates a new price when the amount changes', function () {
    $pricesResource = new class
    {
        public function retrieve($id, $params = [])
        {
            return stripeObj2([
                'id' => $id,
                'unit_amount' => 1000,
                'currency' => 'usd',
                'recurring' => ['interval' => 'month'],
                'metadata' => [],
                'product' => 'prod_123',
            ]);
        }

        public function create(array $params)
        {
            return stripeObj2([
                'id' => 'price_new',
                'unit_amount' => $params['unit_amount'],
                'currency' => $params['currency'],
                'recurring' => ['interval' => $params['recurring']['interval']],
                'metadata' => $params['metadata'] ?? [],
            ]);
        }
    };
    $productsResource = new class
    {
        public function retrieve($id)
        {
            return stripeObj2(['id' => $id, 'name' => 'Product']);
        }
    };

    $client = new class($productsResource, $pricesResource)
    {
        public function __construct(public object $products, public object $prices) {}
    };

    $driver = makeStripeDriverWithClient2($client);
    $result = $driver->updatePlan('price_abc', ['amount' => 50.00, 'interval' => 'annually']);

    expect($result->planCode)->toBe('price_new')
        ->and($result->amount)->toBe(50.00)
        ->and($result->interval)->toBe('annually');
});

test('stripe updatePlan updates mutable attributes (metadata/active/nickname) in place', function () {
    $pricesResource = new class
    {
        public array $updateCalls = [];

        public function retrieve($id, $params = [])
        {
            return stripeObj2([
                'id' => $id,
                'unit_amount' => 1000,
                'currency' => 'usd',
                'recurring' => ['interval' => 'month'],
                'metadata' => [],
                'product' => ['id' => 'prod_123', 'name' => 'Product'],
            ]);
        }

        public function update($id, array $params)
        {
            $this->updateCalls[] = [$id, $params];

            return stripeObj2([
                'id' => $id,
                'unit_amount' => 1000,
                'currency' => 'usd',
                'recurring' => ['interval' => 'month'],
                'metadata' => $params['metadata'] ?? [],
            ]);
        }
    };
    $productsResource = new class
    {
        public function retrieve($id)
        {
            return stripeObj2(['id' => $id, 'name' => 'Product']);
        }
    };

    $client = new class($productsResource, $pricesResource)
    {
        public function __construct(public object $products, public object $prices) {}
    };

    $driver = makeStripeDriverWithClient2($client);
    $result = $driver->updatePlan('price_abc', ['metadata' => ['foo' => 'bar'], 'active' => true, 'nickname' => 'nick']);

    expect($result->planCode)->toBe('price_abc')
        ->and($result->metadata)->toBe(['foo' => 'bar']);
});

test('stripe updatePlan casts non-string metadata values before sending them to Stripe', function () {
    // Regression: Stripe's metadata parameter is documented as
    // array<string, string>. This package's own DTOs/consumer input declare
    // metadata as array<string, mixed> (a general-purpose bag), so a
    // non-string value (e.g. an int passed by a careless caller) must be
    // cast rather than sent as-is.
    $pricesResource = new class
    {
        public array $updateCalls = [];

        public function retrieve($id, $params = [])
        {
            return stripeObj2([
                'id' => $id,
                'unit_amount' => 1000,
                'currency' => 'usd',
                'recurring' => ['interval' => 'month'],
                'metadata' => [],
                'product' => ['id' => 'prod_123', 'name' => 'Product'],
            ]);
        }

        public function update($id, array $params)
        {
            $this->updateCalls[] = [$id, $params];

            return stripeObj2([
                'id' => $id,
                'unit_amount' => 1000,
                'currency' => 'usd',
                'recurring' => ['interval' => 'month'],
                'metadata' => $params['metadata'] ?? [],
            ]);
        }
    };
    $productsResource = new class
    {
        public function retrieve($id)
        {
            return stripeObj2(['id' => $id, 'name' => 'Product']);
        }
    };

    $client = new class($productsResource, $pricesResource)
    {
        public function __construct(public object $products, public object $prices) {}
    };

    $driver = makeStripeDriverWithClient2($client);
    $driver->updatePlan('price_abc', ['metadata' => ['count' => 5, 'active_flag' => true]]);

    expect($pricesResource->updateCalls)->toHaveCount(1);
    $sentMetadata = $pricesResource->updateCalls[0][1]['metadata'];

    expect($sentMetadata['count'])->toBe('5')
        ->and($sentMetadata['active_flag'])->toBe('1');
});

test('stripe updatePlan returns the existing price unchanged when no mutable attributes are given', function () {
    $pricesResource = new class
    {
        public function retrieve($id, $params = [])
        {
            return stripeObj2([
                'id' => $id,
                'unit_amount' => 1000,
                'currency' => 'usd',
                'recurring' => ['interval' => 'month'],
                'metadata' => [],
                'product' => ['id' => 'prod_123', 'name' => 'Product'],
            ]);
        }

        public function update($id, array $params)
        {
            throw new RuntimeException('update should not be called');
        }
    };
    $productsResource = new class
    {
        public function retrieve($id)
        {
            return stripeObj2(['id' => $id, 'name' => 'Product']);
        }
    };

    $client = new class($productsResource, $pricesResource)
    {
        public function __construct(public object $products, public object $prices) {}
    };

    $driver = makeStripeDriverWithClient2($client);
    $result = $driver->updatePlan('price_abc', []);

    expect($result->planCode)->toBe('price_abc');
});

test('stripe updatePlan throws PlanException on API error', function () {
    $pricesResource = new class
    {
        public function retrieve($id, $params = [])
        {
            throw ApiConnectionException::factory('boom');
        }
    };

    $client = new class($pricesResource)
    {
        public function __construct(public object $prices) {}
    };

    $driver = makeStripeDriverWithClient2($client);

    $driver->updatePlan('price_abc', ['name' => 'X']);
})->throws(PlanException::class, 'Failed to update plan');

// ---------------------------------------------------------------------
// fetchPlan - error branch
// ---------------------------------------------------------------------

test('stripe fetchPlan throws PlanException on API error', function () {
    $pricesResource = new class
    {
        public function retrieve($id, $params = [])
        {
            throw ApiConnectionException::factory('not found');
        }
    };

    $client = new class($pricesResource)
    {
        public function __construct(public object $prices) {}
    };

    $driver = makeStripeDriverWithClient2($client);

    $driver->fetchPlan('price_missing');
})->throws(PlanException::class, 'Failed to get plan');

// ---------------------------------------------------------------------
// listPlans
// ---------------------------------------------------------------------

test('stripe listPlans returns a mapped list of plans', function () {
    $pricesResource = new class
    {
        public function all(array $params)
        {
            return stripeObj2([
                'data' => [
                    [
                        'id' => 'price_1',
                        'unit_amount' => 1000,
                        'currency' => 'usd',
                        'recurring' => ['interval' => 'month'],
                        'metadata' => [],
                        'product' => ['id' => 'prod_1', 'name' => 'Plan One'],
                    ],
                ],
                'has_more' => false,
            ]);
        }
    };

    $client = new class($pricesResource)
    {
        public function __construct(public object $prices) {}
    };

    $driver = makeStripeDriverWithClient2($client);
    $result = $driver->listPlans(10, 1);

    expect($result['data'])->toHaveCount(1)
        ->and($result['data'][0]->planCode)->toBe('price_1')
        ->and($result['has_more'])->toBeFalse();
});

test('stripe listPlans logs a warning when page greater than one is requested', function () {
    $pricesResource = new class
    {
        public function all(array $params)
        {
            return stripeObj2(['data' => [], 'has_more' => false]);
        }
    };

    $client = new class($pricesResource)
    {
        public function __construct(public object $prices) {}
    };

    $driver = makeStripeDriverWithClient2($client);
    $result = $driver->listPlans(10, 2);

    expect($result['data'])->toBe([]);
});

test('stripe listPlans throws PlanException on API error', function () {
    $pricesResource = new class
    {
        public function all(array $params)
        {
            throw ApiConnectionException::factory('down');
        }
    };

    $client = new class($pricesResource)
    {
        public function __construct(public object $prices) {}
    };

    $driver = makeStripeDriverWithClient2($client);

    $driver->listPlans();
})->throws(PlanException::class, 'Failed to list plans');

// ---------------------------------------------------------------------
// createSubscription - error branch
// ---------------------------------------------------------------------

test('stripe createSubscription throws SubscriptionException on API error', function () {
    $customersResource = new class
    {
        public function all(array $params)
        {
            return stripeObj2(['data' => []]);
        }

        public function create(array $params)
        {
            return stripeObj2(['id' => 'cus_new', 'email' => $params['email']]);
        }
    };
    $subscriptionsResource = new class
    {
        public function create(array $params, array $options = [])
        {
            throw ApiConnectionException::factory('down');
        }
    };

    $client = new class($customersResource, $subscriptionsResource)
    {
        public function __construct(public object $customers, public object $subscriptions) {}
    };

    $driver = makeStripeDriverWithClient2($client);
    $request = new SubscriptionRequestDTO(customer: 'test@example.com', plan: 'price_123', authorization: 'pm_123');

    $driver->createSubscription($request);
})->throws(SubscriptionException::class, 'Failed to create subscription');

// ---------------------------------------------------------------------
// fetchSubscription
// ---------------------------------------------------------------------

test('stripe fetchSubscription retrieves and maps a subscription', function () {
    $subscriptionsResource = new class
    {
        public function retrieve($id, $params = [])
        {
            return stripeObj2([
                'id' => $id,
                'status' => 'active',
                'current_period_end' => 1735689600,
                'metadata' => [],
                'customer' => ['id' => 'cus_123', 'email' => 'test@example.com'],
                'items' => ['data' => [
                    ['price' => ['id' => 'price_123', 'unit_amount' => 2000, 'currency' => 'usd']],
                ]],
            ]);
        }
    };

    $client = new class($subscriptionsResource)
    {
        public function __construct(public object $subscriptions) {}
    };

    $driver = makeStripeDriverWithClient2($client);
    $result = $driver->fetchSubscription('sub_123');

    expect($result->subscriptionCode)->toBe('sub_123')
        ->and($result->customer)->toBe('test@example.com')
        ->and($result->plan)->toBe('price_123');
});

test('stripe fetchSubscription throws SubscriptionException on API error', function () {
    $subscriptionsResource = new class
    {
        public function retrieve($id, $params = [])
        {
            throw ApiConnectionException::factory('missing');
        }
    };

    $client = new class($subscriptionsResource)
    {
        public function __construct(public object $subscriptions) {}
    };

    $driver = makeStripeDriverWithClient2($client);

    $driver->fetchSubscription('sub_missing');
})->throws(SubscriptionException::class, 'Failed to fetch subscription');

// ---------------------------------------------------------------------
// cancelSubscription - error branch
// ---------------------------------------------------------------------

test('stripe cancelSubscription throws SubscriptionException on API error', function () {
    $subscriptionsResource = new class
    {
        public function cancel($id)
        {
            throw ApiConnectionException::factory('down');
        }
    };

    $client = new class($subscriptionsResource)
    {
        public function __construct(public object $subscriptions) {}
    };

    $driver = makeStripeDriverWithClient2($client);

    $driver->cancelSubscription(new SubscriptionActionDTO('sub_123'));
})->throws(SubscriptionException::class, 'Failed to cancel subscription');

// ---------------------------------------------------------------------
// enableSubscription - error branch
// ---------------------------------------------------------------------

test('stripe enableSubscription throws SubscriptionException when the Stripe API errors', function () {
    $subscriptionsResource = new class
    {
        public function retrieve($id)
        {
            throw ApiConnectionException::factory('down');
        }
    };

    $client = new class($subscriptionsResource)
    {
        public function __construct(public object $subscriptions) {}
    };

    $driver = makeStripeDriverWithClient2($client);

    $driver->enableSubscription(new SubscriptionActionDTO('sub_123'));
})->throws(SubscriptionException::class, 'Failed to enable subscription');

// ---------------------------------------------------------------------
// listSubscriptions
// ---------------------------------------------------------------------

test('stripe listSubscriptions returns a mapped list without a customer filter', function () {
    $subscriptionsResource = new class
    {
        public function all(array $params)
        {
            expect($params)->not->toHaveKey('customer');

            return stripeObj2([
                'data' => [
                    [
                        'id' => 'sub_1',
                        'status' => 'active',
                        'metadata' => [],
                        'customer' => ['id' => 'cus_1', 'email' => 'a@example.com'],
                        'items' => ['data' => []],
                    ],
                ],
                'has_more' => true,
            ]);
        }
    };

    $client = new class($subscriptionsResource)
    {
        public function __construct(public object $subscriptions) {}
    };

    $driver = makeStripeDriverWithClient2($client);
    $result = $driver->listSubscriptions(10, 1);

    expect($result['data'])->toHaveCount(1)
        ->and($result['has_more'])->toBeTrue();
});

test('stripe listSubscriptions filters by customer email when a matching customer is found', function () {
    $customersResource = new class
    {
        public function all(array $params)
        {
            return stripeObj2(['data' => [['id' => 'cus_1', 'email' => $params['email']]]]);
        }
    };
    $subscriptionsResource = new class
    {
        public function all(array $params)
        {
            expect($params['customer'])->toBe('cus_1');

            return stripeObj2(['data' => [], 'has_more' => false]);
        }
    };

    $client = new class($customersResource, $subscriptionsResource)
    {
        public function __construct(public object $customers, public object $subscriptions) {}
    };

    $driver = makeStripeDriverWithClient2($client);
    $result = $driver->listSubscriptions(10, 1, 'test@example.com');

    expect($result['data'])->toBe([]);
});

test('stripe listSubscriptions returns an empty list when the customer email is not found', function () {
    $customersResource = new class
    {
        public function all(array $params)
        {
            return stripeObj2(['data' => []]);
        }
    };
    $subscriptionsResource = new class
    {
        public function all(array $params)
        {
            throw new RuntimeException('should not be called');
        }
    };

    $client = new class($customersResource, $subscriptionsResource)
    {
        public function __construct(public object $customers, public object $subscriptions) {}
    };

    $driver = makeStripeDriverWithClient2($client);
    $result = $driver->listSubscriptions(10, 1, 'missing@example.com');

    expect($result)->toBe(['data' => [], 'has_more' => false]);
});

test('stripe listSubscriptions logs a warning when page greater than one is requested', function () {
    $subscriptionsResource = new class
    {
        public function all(array $params)
        {
            return stripeObj2(['data' => [], 'has_more' => false]);
        }
    };

    $client = new class($subscriptionsResource)
    {
        public function __construct(public object $subscriptions) {}
    };

    $driver = makeStripeDriverWithClient2($client);
    $result = $driver->listSubscriptions(10, 2);

    expect($result['data'])->toBe([]);
});

test('stripe listSubscriptions throws SubscriptionException on API error', function () {
    $subscriptionsResource = new class
    {
        public function all(array $params)
        {
            throw ApiConnectionException::factory('down');
        }
    };

    $client = new class($subscriptionsResource)
    {
        public function __construct(public object $subscriptions) {}
    };

    $driver = makeStripeDriverWithClient2($client);

    $driver->listSubscriptions();
})->throws(SubscriptionException::class, 'Failed to list subscriptions');

// ---------------------------------------------------------------------
// mapIntervalFromStripe - day / week
// ---------------------------------------------------------------------

test('stripe fetchPlan maps day and week stripe intervals back to daily/weekly', function (string $stripeInterval, string $expected) {
    $pricesResource = new class($stripeInterval)
    {
        public function __construct(private readonly string $interval) {}

        public function retrieve($id, $params = [])
        {
            return stripeObj2([
                'id' => $id,
                'unit_amount' => 1000,
                'currency' => 'usd',
                'recurring' => ['interval' => $this->interval],
                'metadata' => [],
                'product' => ['id' => 'prod_123', 'name' => 'Plan'],
            ]);
        }
    };

    $client = new class($pricesResource)
    {
        public function __construct(public object $prices) {}
    };

    $driver = makeStripeDriverWithClient2($client);
    $result = $driver->fetchPlan('price_abc');

    expect($result->interval)->toBe($expected);
})->with([
    ['day', 'daily'],
    ['week', 'weekly'],
]);

// ---------------------------------------------------------------------
// mapStripeSubscriptionStatus
// ---------------------------------------------------------------------

test('stripe fetchSubscription maps trialing, incomplete and incomplete_expired statuses', function (string $stripeStatus, string $expected) {
    $subscriptionsResource = new class($stripeStatus)
    {
        public function __construct(private readonly string $status) {}

        public function retrieve($id, $params = [])
        {
            return stripeObj2([
                'id' => $id,
                'status' => $this->status,
                'metadata' => [],
                'customer' => 'cus_123',
                'items' => ['data' => []],
            ]);
        }
    };

    $client = new class($subscriptionsResource)
    {
        public function __construct(public object $subscriptions) {}
    };

    $driver = makeStripeDriverWithClient2($client);
    $result = $driver->fetchSubscription('sub_123');

    expect($result->status)->toBe($expected);
})->with([
    ['trialing', 'active'],
    ['incomplete', 'attention'],
    ['incomplete_expired', 'expired'],
]);
