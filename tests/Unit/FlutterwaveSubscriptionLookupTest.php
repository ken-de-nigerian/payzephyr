<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;

/*
 * Flutterwave's subscription list is paged, and both lookups read one page
 * and filtered it here: a customer's subscription on any other page was not
 * found. Both now ask Flutterwave to filter, and still check what comes back.
 */

function flutterwaveLookupDriver(array $responses, array &$history): FlutterwaveDriver
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $driver = new FlutterwaveDriver(['secret_key' => 'test_secret', 'currencies' => ['NGN']]);
    $driver->setClient(new Client(['handler' => $stack]));

    return $driver;
}

function flutterwaveSubscriptionRow(int $id, string $email, mixed $plan): array
{
    return ['id' => $id, 'status' => 'active', 'amount' => 5000, 'plan' => $plan, 'customer' => ['email' => $email, 'currency' => 'NGN']];
}

test('listing a customer\'s subscriptions asks Flutterwave to filter by their email', function (): void {
    $history = [];
    $driver = flutterwaveLookupDriver([
        new Response(200, [], json_encode(['status' => 'success', 'data' => [
            flutterwaveSubscriptionRow(1, 'a@b.com', 3807),
            flutterwaveSubscriptionRow(2, 'someone@else.com', 3807),
        ]])),
    ], $history);

    $listing = $driver->listSubscriptions(customer: 'a@b.com');

    parse_str($history[0]['request']->getUri()->getQuery(), $query);

    expect($query)->toBe(['page' => '1', 'email' => 'a@b.com'])
        ->and($listing['data'])->toHaveCount(1)
        ->and($listing['data'][0]->subscriptionCode)->toBe('1');
});

test('the subscription a charge created is looked up by customer and plan', function (): void {
    $history = [];
    $driver = flutterwaveLookupDriver([
        new Response(200, [], json_encode(['status' => 'success', 'data' => ['id' => 99]])),
        new Response(200, [], json_encode(['status' => 'success', 'data' => [
            flutterwaveSubscriptionRow(7, 'someone@else.com', 3807),
            flutterwaveSubscriptionRow(8, 'a@b.com', ['id' => 3807, 'name' => 'Pro']),
        ]])),
    ], $history);

    $subscription = $driver->createSubscription(new SubscriptionRequestDTO(
        customer: 'a@b.com',
        plan: '3807',
        authorization: 'flw-t1nf-token-1234',
    ));

    parse_str($history[1]['request']->getUri()->getQuery(), $query);

    expect($query)->toBe(['email' => 'a@b.com', 'plan' => '3807'])
        ->and($subscription->subscriptionCode)->toBe('8')
        ->and($subscription->plan)->toBe('3807');
});

test('the subscription a charge created is the customer\'s active one, newest first, not an older cancelled one', function (): void {
    // A customer who subscribed to the plan before has an older, cancelled
    // subscription to it too - listed here first, and newest last.
    $row = fn (int $id, string $status): array => array_merge(flutterwaveSubscriptionRow($id, 'a@b.com', 3807), ['status' => $status]);
    $history = [];
    $driver = flutterwaveLookupDriver([
        new Response(200, [], json_encode(['status' => 'success', 'data' => ['id' => 99]])),
        new Response(200, [], json_encode(['status' => 'success', 'data' => [
            $row(10, 'cancelled'),
            $row(11, 'active'),
            $row(12, 'active'),
            $row(13, 'cancelled'),
        ]])),
    ], $history);

    $subscription = $driver->createSubscription(new SubscriptionRequestDTO(
        customer: 'a@b.com',
        plan: '3807',
        authorization: 'flw-t1nf-token-1234',
    ));

    expect($subscription->subscriptionCode)->toBe('12')
        ->and($subscription->status)->toBe('active');
});
