# Testing

## The problem this chapter solves

You want to test your checkout controller, your webhook listener, your subscription logic — but none of that should involve actually calling Paystack's or Stripe's real API every time you run your test suite. Real API calls are slow, need real (or sandbox) credentials to even run, and you don't want your CI pipeline creating real payment sessions every time someone pushes a commit.

The fix is the same one you'd use for any external HTTP dependency: intercept the HTTP call before it leaves your machine, and hand back a canned response instead.

## Important: `Http::fake()` won't work here

If you've tested Laravel HTTP calls before, your instinct is probably to reach for `Http::fake()`. **It won't intercept PayZephyr's requests.** `Http::fake()` only fakes calls made through Laravel's `Http` facade — PayZephyr's drivers talk to providers using Guzzle directly (`GuzzleHttp\Client`), not the `Http` facade, so Laravel's fake layer never sees these requests at all. This is worth knowing up front, because otherwise you'll write a test, watch it make a real network call anyway, and spend time confused about why.

## The actual mechanism: inject a mock Guzzle client

Every driver exposes `setClient()`, which swaps its internal HTTP client for one you control:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;

$driver = new PaystackDriver([
    'secret_key' => 'sk_test_fake',
    'currencies' => ['NGN'],
]);

$mock = new MockHandler([
    new Response(200, [], json_encode([
        'status' => true,
        'data' => [
            'reference' => 'ref_123',
            'authorization_url' => 'https://checkout.paystack.com/abc123',
            'access_code' => 'access_123',
        ],
    ])),
]);

$driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));
```

`MockHandler` takes an array of canned `Response` objects and hands them back in order, one per request the driver makes — the first request gets the first response, the second gets the second, and so on. This is the exact pattern PayZephyr's own test suite uses throughout, so it's proven to work for every operation (charges, verification, subscriptions, webhooks).

## Testing your checkout controller

Putting it together with a real Laravel feature test — swap the `paystack` driver PayZephyr would normally construct for your mocked one, then exercise your controller normally:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\PaymentManager;

test('checkout redirects to the payment page', function () {
    config([
        'payments.providers.paystack.enabled' => true,
        'payments.providers.paystack.secret_key' => 'sk_test_fake',
    ]);

    $driver = new PaystackDriver(config('payments.providers.paystack'));
    $driver->setClient(new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'reference' => 'ref_123',
                'authorization_url' => 'https://checkout.paystack.com/abc123',
                'access_code' => 'access_123',
            ],
        ])),
    ]))]));

    // Inject the mocked driver into the container so PaymentManager uses it
    // instead of constructing a real one.
    $manager = app(PaymentManager::class);
    $reflection = new ReflectionClass($manager);
    $driversProperty = $reflection->getProperty('drivers');
    $driversProperty->setAccessible(true);
    $driversProperty->setValue($manager, ['paystack' => $driver]);

    $response = $this->post('/checkout', ['email' => 'test@example.com']);

    $response->assertRedirect('https://checkout.paystack.com/abc123');
});
```

The reflection-based injection above is what PayZephyr's own test suite does internally, and it works, but it's admittedly not the most elegant thing to write in every test. If you find yourself doing this repeatedly, wrap it in a small test helper trait in your own app:

```php
// tests/Helpers/FakesPayzephyr.php

namespace Tests\Helpers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use KenDeNigerian\PayZephyr\Drivers\AbstractDriver;
use KenDeNigerian\PayZephyr\PaymentManager;
use ReflectionClass;

trait FakesPayzephyr
{
    protected function fakeDriver(string $provider, AbstractDriver $driver, array $responses): void
    {
        $driver->setClient(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));

        $manager = app(PaymentManager::class);
        $reflection = new ReflectionClass($manager);
        $property = $reflection->getProperty('drivers');
        $property->setAccessible(true);
        $property->setValue($manager, [$provider => $driver]);
    }
}
```

## Testing webhook handling

Since webhook processing happens inside a queued job (see [Queues](queues.md)), you can test your listener directly against that job without needing a real signed HTTP request at all:

```php
use Illuminate\Support\Facades\Event;
use KenDeNigerian\PayZephyr\Events\WebhookReceived;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;

test('a successful payment webhook marks the order paid', function () {
    $order = Order::factory()->create(['payment_reference' => 'ref_123', 'status' => 'pending']);

    $job = new ProcessWebhook('paystack', [
        'event' => 'charge.success',
        'data' => ['reference' => 'ref_123', 'status' => 'success'],
    ]);

    app()->call([$job, 'handle']);

    expect($order->fresh()->status)->toBe('paid');
});
```

If you want to test that your listener specifically responds to `WebhookReceived` (rather than testing the end-to-end database effect), fake events and assert on dispatch:

```php
test('webhook processing dispatches WebhookReceived', function () {
    Event::fake([WebhookReceived::class]);

    $job = new ProcessWebhook('paystack', [
        'event' => 'charge.success',
        'data' => ['reference' => 'ref_123', 'status' => 'success'],
    ]);
    app()->call([$job, 'handle']);

    Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $e) => $e->reference === 'ref_123');
});
```

## Testing verification

`Payment::verify()` follows the same mocked-response pattern as charging — hand back a canned "successful payment" or "failed payment" response and assert your code reacts correctly to each:

```php
test('a successful verification marks the order paid', function () {
    // ...set up the mocked driver as shown above, with a verify-endpoint response...

    $response = $this->get('/checkout/callback?reference=ref_123');

    $response->assertSee('Payment succeeded');
});
```

## Testing subscriptions

Subscriptions follow exactly the same mocking approach — mock the sequence of responses the provider's API would return for whichever operation you're testing (`createPlan`, then `createSubscription`, in that order, if your test exercises both). See PayZephyr's own subscription driver tests in its repository for real, working examples per provider if you want a reference beyond what fits in this chapter.

## Next steps

- [Error Handling](error-handling.md) — the exceptions your tests should also cover (a failed charge, an unreachable provider)
- [Queues](queues.md) — why webhook tests interact with `ProcessWebhook` directly instead of the HTTP layer
