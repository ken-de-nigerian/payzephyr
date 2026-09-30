<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\Drivers\MollieDriver;
use KenDeNigerian\PayZephyr\Exceptions\SubscriptionException;

/*
 * Mollie cannot filter customers by email, so the driver walks the list. It
 * read only the first page: past 250 customers an existing customer was not
 * found, a subscribe created a duplicate, and listing returned nothing.
 */

function mollieCustomerPage(array $customers, ?string $nextFrom): Response
{
    return new Response(200, [], json_encode([
        '_embedded' => ['customers' => $customers],
        '_links' => ['next' => $nextFrom === null ? null : ['href' => "https://api.mollie.com/v2/customers?from=$nextFrom&limit=250"]],
    ]));
}

function mollieDriverRecording(array $responses, array &$history): MollieDriver
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $driver = new MollieDriver(['api_key' => 'test_test_key', 'currencies' => ['EUR']]);
    $driver->setClient(new Client(['handler' => $stack]));

    return $driver;
}

test('a customer past the first page is found, and their subscriptions listed', function () {
    $history = [];
    $driver = mollieDriverRecording([
        mollieCustomerPage([['id' => 'cst_1', 'email' => 'someone@else.com']], 'cst_2'),
        mollieCustomerPage([['id' => 'cst_2', 'email' => 'a@b.com']], null),
        new Response(200, [], json_encode(['_embedded' => ['subscriptions' => [[
            'id' => 'sub_1', 'status' => 'active', 'description' => 'Pro',
            'amount' => ['value' => '10.00', 'currency' => 'EUR'], 'interval' => '1 month',
        ]]], '_links' => ['next' => null]])),
    ], $history);

    $listing = $driver->listSubscriptions(customer: 'a@b.com');

    expect($listing['data'])->toHaveCount(1)
        ->and($listing['data'][0]->subscriptionCode)->toBe('cst_2:sub_1')
        ->and($listing['has_more'])->toBeFalse()
        ->and($history[1]['request']->getUri()->getQuery())->toBe('limit=250&from=cst_2')
        ->and((string) $history[2]['request']->getUri())->toContain('/v2/customers/cst_2/subscriptions');
});

test('a customer on no page is not found', function () {
    $history = [];
    $driver = mollieDriverRecording([
        mollieCustomerPage([['id' => 'cst_1', 'email' => 'someone@else.com']], null),
    ], $history);

    expect($driver->listSubscriptions(customer: 'a@b.com'))->toBe(['data' => [], 'has_more' => false])
        ->and($history)->toHaveCount(1);
});

test('past the last page searched, the lookup refuses rather than answering not found', function () {
    $history = [];
    $pages = [];
    for ($i = 1; $i <= 20; $i++) {
        $pages[] = mollieCustomerPage([['id' => "cst_$i", 'email' => "c$i@else.com"]], 'cst_'.($i + 1));
    }

    $driver = mollieDriverRecording($pages, $history);

    expect(fn () => $driver->listSubscriptions(customer: 'a@b.com'))->toThrow(
        SubscriptionException::class,
        'Could not tell whether a Mollie customer exists for [a@b.com]: it is not among the first 5000 customers'
    )->and($history)->toHaveCount(20);
});
