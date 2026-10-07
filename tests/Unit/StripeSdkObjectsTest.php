<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;
use KenDeNigerian\PayZephyr\Drivers\StripeDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\PlanException;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use Stripe\Collection;
use Stripe\Customer;
use Stripe\Price;
use Stripe\Product;
use Stripe\Refund;
use Stripe\Subscription;

/*
 * Every other Stripe test stands in for the SDK with stdClass trees. These
 * use the SDK's own objects, which is what a real call returns - and where
 * the refund and subscription mappers went wrong: an `(array)` cast of a
 * StripeObject yields the SDK's internals (`_values`, `_opts`, ...), not the
 * data, so every mapped refund, plan and subscription carried those as its
 * metadata.
 */

function stripeSdkDriver(array $resources): StripeDriver
{
    $driver = new StripeDriver(['secret_key' => 'sk_test', 'currencies' => ['USD']]);
    $driver->setStripeClient((object) $resources);

    return $driver;
}

function stripeSdkResource(array $methods): object
{
    return new class($methods)
    {
        public array $calls = [];

        public function __construct(private array $methods) {}

        public function __call(string $name, array $arguments): mixed
        {
            $this->calls[] = [$name, $arguments];

            return ($this->methods[$name])(...$arguments);
        }
    };
}

function sdkSubscription(array $overrides = []): Subscription
{
    return Subscription::constructFrom(array_merge([
        'id' => 'sub_1',
        'object' => 'subscription',
        'status' => 'trialing',
        'current_period_end' => 1767225600,
        'metadata' => ['tier' => 'gold'],
        'customer' => ['id' => 'cus_1', 'object' => 'customer', 'email' => 'a@b.com'],
        'items' => ['object' => 'list', 'data' => [[
            'id' => 'si_1',
            'object' => 'subscription_item',
            'price' => ['id' => 'price_1', 'object' => 'price', 'unit_amount' => 2500, 'currency' => 'usd'],
        ]]],
    ], $overrides));
}

test('a subscription the SDK returns is mapped from its data, not its internals', function (): void {
    $driver = stripeSdkDriver(['subscriptions' => stripeSdkResource(['retrieve' => fn (): Subscription => sdkSubscription()])]);

    $subscription = $driver->fetchSubscription('sub_1');

    expect($subscription->subscriptionCode)->toBe('sub_1')
        ->and($subscription->status)->toBe('active')
        ->and($subscription->customer)->toBe('a@b.com')
        ->and($subscription->plan)->toBe('price_1')
        ->and($subscription->amount)->toBe(25.0)
        ->and($subscription->currency)->toBe('USD')
        ->and($subscription->nextPaymentDate)->toBe(date('Y-m-d H:i:s', 1767225600))
        ->and($subscription->metadata)->toBe(['tier' => 'gold']);
});

test('a subscription whose customer and price were not expanded still maps', function (): void {
    $sdk = sdkSubscription([
        'customer' => 'cus_1',
        'items' => ['object' => 'list', 'data' => [['id' => 'si_1', 'object' => 'subscription_item', 'price' => 'price_1']]],
        'current_period_end' => null,
    ]);
    $driver = stripeSdkDriver(['subscriptions' => stripeSdkResource(['retrieve' => fn (): Subscription => $sdk])]);

    $subscription = $driver->fetchSubscription('sub_1');

    expect($subscription->customer)->toBe('')
        ->and($subscription->plan)->toBe('price_1')
        ->and($subscription->amount)->toBeNull()
        ->and($subscription->currency)->toBe('USD')
        ->and($subscription->nextPaymentDate)->toBeNull();
});

test('a subscription response without an id is refused, not mapped with an empty code', function (): void {
    $driver = stripeSdkDriver(['subscriptions' => stripeSdkResource([
        'retrieve' => fn () => Subscription::constructFrom(['object' => 'subscription', 'status' => 'active']),
    ])]);

    expect(fn (): SubscriptionResponseDTO => $driver->fetchSubscription('sub_1'))
        ->toThrow(ChargeException::class, '[stripe] omitted the required field [id] from its subscription response');
});

test('listing subscriptions maps each SDK object and filters by the found customer', function (): void {
    $subscriptions = stripeSdkResource(['all' => fn () => Collection::constructFrom([
        'object' => 'list', 'has_more' => false, 'data' => [sdkSubscription()->toArray()],
    ])]);
    $customers = stripeSdkResource(['all' => fn () => Collection::constructFrom([
        'object' => 'list', 'data' => [['id' => 'cus_1', 'object' => 'customer', 'email' => 'a@b.com']],
    ])]);
    $driver = stripeSdkDriver(['subscriptions' => $subscriptions, 'customers' => $customers]);

    $listing = $driver->listSubscriptions(10, 1, 'a@b.com');

    expect($listing['data'])->toHaveCount(1)
        ->and($listing['data'][0]->metadata)->toBe(['tier' => 'gold'])
        ->and($subscriptions->calls[0][1][0]['customer'])->toBe('cus_1');
});

test('creating a subscription sends flat metadata and the found customer id', function (): void {
    $subscriptions = stripeSdkResource(['create' => fn (): Subscription => sdkSubscription(['customer' => 'cus_9'])]);
    $customers = stripeSdkResource([
        'all' => fn () => Collection::constructFrom(['object' => 'list', 'data' => []]),
        'create' => fn () => Customer::constructFrom(['id' => 'cus_9', 'object' => 'customer', 'email' => 'new@b.com']),
    ]);
    $driver = stripeSdkDriver(['subscriptions' => $subscriptions, 'customers' => $customers]);

    $response = $driver->createSubscription(new SubscriptionRequestDTO(
        customer: 'new@b.com',
        plan: 'price_1',
        metadata: ['cart' => ['sku' => 'A1'], 'gift' => true, 'seats' => 3],
        authorization: 'pm_1234567890',
    ));

    $sent = $subscriptions->calls[0][1][0];

    expect($sent['customer'])->toBe('cus_9')
        ->and($sent['metadata'])->toBe(['cart' => '{"sku":"A1"}', 'gift' => 'true', 'seats' => '3'])
        ->and($response->customer)->toBe('new@b.com');
});

test('a plan the SDK returns is mapped from its data, with the product expanded on it', function (): void {
    $price = Price::constructFrom([
        'id' => 'price_1', 'object' => 'price', 'unit_amount' => 1999, 'currency' => 'usd',
        'recurring' => ['interval' => 'year'],
        'metadata' => ['audience' => 'teams'],
        'product' => ['id' => 'prod_1', 'object' => 'product', 'name' => 'Team', 'description' => 'For teams'],
    ]);
    $driver = stripeSdkDriver(['prices' => stripeSdkResource(['retrieve' => fn () => $price])]);

    $plan = $driver->fetchPlan('price_1');

    expect($plan->planCode)->toBe('price_1')
        ->and($plan->name)->toBe('Team')
        ->and($plan->description)->toBe('For teams')
        ->and($plan->amount)->toBe(19.99)
        ->and($plan->interval)->toBe('annually')
        ->and($plan->currency)->toBe('USD')
        ->and($plan->metadata)->toBe(['audience' => 'teams']);
});

test('updating a plan whose product was not expanded uses the product id', function (): void {
    $price = Price::constructFrom([
        'id' => 'price_1', 'object' => 'price', 'unit_amount' => 1000, 'currency' => 'usd',
        'recurring' => ['interval' => 'month'], 'metadata' => ['audience' => 'teams'], 'product' => 'prod_7',
    ]);
    $prices = stripeSdkResource([
        'retrieve' => fn () => $price,
        'create' => fn (array $params) => Price::constructFrom(['id' => 'price_2', 'object' => 'price'] + $params),
    ]);
    $products = stripeSdkResource([
        'retrieve' => fn (string $id) => Product::constructFrom(['id' => $id, 'object' => 'product', 'name' => 'Team']),
    ]);
    $driver = stripeSdkDriver(['prices' => $prices, 'products' => $products]);

    $plan = $driver->updatePlan('price_1', ['amount' => 12.5]);

    $created = $prices->calls[1][1][0];

    expect($created)->toBe([
        'unit_amount' => 1250,
        'currency' => 'usd',
        'recurring' => ['interval' => 'month'],
        'product' => 'prod_7',
        'metadata' => ['audience' => 'teams'],
    ])
        ->and($products->calls[0][1][0])->toBe('prod_7')
        ->and($plan->planCode)->toBe('price_2');
});

test('a plan is switched off by any value that is a switch', function (mixed $off): void {
    $price = Price::constructFrom([
        'id' => 'price_1', 'object' => 'price', 'unit_amount' => 1000, 'currency' => 'usd',
        'recurring' => ['interval' => 'month'], 'product' => 'prod_7',
    ]);
    $prices = stripeSdkResource(['retrieve' => fn () => $price, 'update' => fn () => $price]);
    $products = stripeSdkResource([
        'retrieve' => fn (string $id) => Product::constructFrom(['id' => $id, 'object' => 'product', 'name' => 'Team']),
    ]);

    stripeSdkDriver(['prices' => $prices, 'products' => $products])->updatePlan('price_1', ['active' => $off]);

    expect($prices->calls[1][1][1])->toBe(['active' => false]);
})->with([false, 0, '0', 'false', 'off']);

test('a plan update whose active is not a switch is refused before Stripe is called', function (): void {
    $prices = stripeSdkResource([]);

    expect(fn (): PlanResponseDTO => stripeSdkDriver(['prices' => $prices])->updatePlan('price_1', ['active' => 'archived']))
        ->toThrow(PlanException::class, 'Plan active must be true or false. Nothing was updated.')
        ->and($prices->calls)->toBe([]);
});

test('a refund the SDK returns is mapped from its data, not its internals', function (): void {
    $refunds = stripeSdkResource(['create' => fn () => Refund::constructFrom([
        'id' => 're_1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 5000, 'currency' => 'usd',
        'payment_intent' => 'pi_1', 'metadata' => ['ticket' => '4411'],
    ])]);
    $driver = stripeSdkDriver(['refunds' => $refunds]);

    $refund = $driver->refund(new RefundRequestDTO(
        transactionReference: 'pi_1',
        amount: 50.0,
        metadata: ['ticket' => 4411, 'lines' => ['a', 'b']],
    ));

    expect($refund->refundReference)->toBe('re_1')
        ->and($refund->transactionReference)->toBe('pi_1')
        ->and($refund->amount)->toBe(50.0)
        ->and($refund->currency)->toBe('USD')
        ->and($refund->metadata)->toBe(['ticket' => '4411'])
        ->and($refunds->calls[0][1][0]['metadata'])->toBe(['ticket' => '4411', 'lines' => '["a","b"]']);
});

test('a refund with its payment intent expanded reports the intent id', function (): void {
    $driver = stripeSdkDriver(['refunds' => stripeSdkResource(['retrieve' => fn () => Refund::constructFrom([
        'id' => 're_1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 5000, 'currency' => 'usd',
        'payment_intent' => ['id' => 'pi_9', 'object' => 'payment_intent', 'amount' => 5000],
    ])])]);

    expect($driver->fetchRefund('re_1')->transactionReference)->toBe('pi_9');
});

test('a refund response without a status is reported as unreadable, not mapped', function (): void {
    $driver = stripeSdkDriver(['refunds' => stripeSdkResource(['retrieve' => fn () => Refund::constructFrom([
        'id' => 're_1', 'object' => 'refund', 'amount' => 5000, 'currency' => 'usd',
    ])])]);

    expect(fn (): RefundResponseDTO => $driver->fetchRefund('re_1'))
        ->toThrow(RefundException::class, 'omitted the required field [status] from its refund response');
});
