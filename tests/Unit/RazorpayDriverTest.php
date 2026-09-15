<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\RazorpayDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\Models\WebhookEvent;
use Tests\Helpers\RazorpayDriverTestHelper;

function razorpayLinkCreatedResponse(): Response
{
    return new Response(200, [], json_encode([
        'id' => 'plink_Abc123',
        'status' => 'created',
        'short_url' => 'https://rzp.io/i/abc123',
    ]));
}

test('razorpay driver requires both the key id and the key secret', function () {
    new RazorpayDriver(['key_id' => 'rzp_test_key']);
})->throws(InvalidConfigurationException::class, 'Razorpay key id and key secret are required');

test('razorpay driver authenticates with http basic auth', function () {
    $driver = RazorpayDriverTestHelper::driver();

    $method = (new ReflectionClass($driver))->getMethod('getDefaultHeaders');

    expect($method->invoke($driver)['Authorization'])
        ->toBe('Basic '.base64_encode('rzp_test_key:test_key_secret'));
});

test('razorpay charge creates a payment link and returns its short url', function () {
    $driver = RazorpayDriverTestHelper::driver([razorpayLinkCreatedResponse()], $history);

    $response = $driver->charge(new ChargeRequestDTO(
        amount: 499.50,
        currency: 'INR',
        email: 'buyer@example.com',
        reference: 'ORDER_1001',
        callbackUrl: 'https://shop.test/payment/callback',
        metadata: ['order_id' => 1001, 'items' => ['a', 'b']],
        idempotencyKey: 'ORDER_1001',
    ));

    $request = $history[0]['request'];
    $body = json_decode((string) $request->getBody(), true);

    expect($response->reference)->toBe('ORDER_1001')
        ->and($response->authorizationUrl)->toBe('https://rzp.io/i/abc123')
        ->and($response->accessCode)->toBe('plink_Abc123')
        ->and($response->status)->toBe('pending')
        ->and($response->provider)->toBe('razorpay')
        ->and($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toBe('/v1/payment_links')
        ->and($body['amount'])->toBe(49950)
        ->and($body['currency'])->toBe('INR')
        ->and($body['reference_id'])->toBe('ORDER_1001')
        ->and($body['callback_url'])->toBe('https://shop.test/payment/callback?reference=ORDER_1001')
        ->and($body['callback_method'])->toBe('get')
        ->and($body['customer'])->toBe(['email' => 'buyer@example.com'])
        ->and($body['notes'])->toBe(['payzephyr_reference' => 'ORDER_1001', 'order_id' => '1001'])
        ->and($body)->not->toHaveKey('options')
        ->and($request->hasHeader('Idempotency-Key'))->toBeFalse();
});

test('razorpay charge omits the callback when none is given', function () {
    $driver = RazorpayDriverTestHelper::driver([razorpayLinkCreatedResponse()], $history);

    $driver->charge(new ChargeRequestDTO(amount: 10, currency: 'INR', email: 'buyer@example.com'));

    $body = json_decode((string) $history[0]['request']->getBody(), true);

    expect($body)->not->toHaveKey('callback_url')
        ->and($body)->not->toHaveKey('callback_method');
});

test('razorpay charge converts amounts using the currency exponent', function (string $currency, float $amount, int $expected) {
    $driver = RazorpayDriverTestHelper::driver([razorpayLinkCreatedResponse()], $history);

    $driver->charge(new ChargeRequestDTO(amount: $amount, currency: $currency, email: 'buyer@example.com'));

    $body = json_decode((string) $history[0]['request']->getBody(), true);

    expect($body['amount'])->toBe($expected);
})->with([
    'two-decimal INR' => ['INR', 10.0, 1000],
    'zero-decimal JPY' => ['JPY', 500.0, 500],
    'three-decimal KWD keeps a trailing zero' => ['KWD', 99.991, 99990],
]);

test('razorpay charge sends customer name and contact when provided', function () {
    $driver = RazorpayDriverTestHelper::driver([razorpayLinkCreatedResponse()], $history);

    $driver->charge(new ChargeRequestDTO(
        amount: 10,
        currency: 'INR',
        email: 'buyer@example.com',
        customer: ['name' => 'Test Buyer', 'phone' => '+919000090000'],
    ));

    $body = json_decode((string) $history[0]['request']->getBody(), true);

    expect($body['customer'])->toBe([
        'email' => 'buyer@example.com',
        'name' => 'Test Buyer',
        'contact' => '+919000090000',
    ]);
});

test('razorpay charge caps notes at fifteen scalar pairs', function () {
    $driver = RazorpayDriverTestHelper::driver([razorpayLinkCreatedResponse()], $history);

    $metadata = [];
    foreach (range(1, 20) as $i) {
        $metadata["key_$i"] = str_repeat('x', 300);
    }

    $driver->charge(new ChargeRequestDTO(amount: 10, currency: 'INR', email: 'buyer@example.com', metadata: $metadata));

    $notes = json_decode((string) $history[0]['request']->getBody(), true)['notes'];

    expect($notes)->toHaveCount(15)
        ->and(strlen($notes['key_1']))->toBe(256);
});

test('razorpay charge generates a RAZORPAY_ reference within the reference_id limit', function () {
    $driver = RazorpayDriverTestHelper::driver([razorpayLinkCreatedResponse()]);

    $response = $driver->charge(new ChargeRequestDTO(amount: 10, currency: 'INR', email: 'buyer@example.com'));

    expect($response->reference)->toStartWith('RAZORPAY_')
        ->and(strlen($response->reference))->toBeLessThanOrEqual(40);
});

test('razorpay charge rejects a reference longer than 40 characters without calling razorpay', function () {
    $driver = RazorpayDriverTestHelper::driver([], $history);

    expect(fn () => $driver->charge(new ChargeRequestDTO(
        amount: 10,
        currency: 'INR',
        email: 'buyer@example.com',
        reference: str_repeat('A', 41),
    )))->toThrow(ChargeException::class, 'limited to 40 characters');

    expect($history)->toBeEmpty();
});

test('razorpay charge throws when the response has no checkout url', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new Response(200, [], json_encode(['id' => 'plink_Abc123', 'status' => 'created'])),
    ]);

    $driver->charge(new ChargeRequestDTO(amount: 10, currency: 'INR', email: 'buyer@example.com'));
})->throws(ChargeException::class, 'did not return a payment link id and checkout URL');

test('razorpay charge throws when the response has no payment link id', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new Response(200, [], json_encode(['short_url' => 'https://rzp.io/i/abc123'])),
    ]);

    $driver->charge(new ChargeRequestDTO(amount: 10, currency: 'INR', email: 'buyer@example.com'));
})->throws(ChargeException::class, 'did not return a payment link id and checkout URL');

test('a razorpay rejection is a definitive charge failure, not an ambiguous one', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new Response(400, [], json_encode(['error' => [
            'code' => 'BAD_REQUEST_ERROR',
            'description' => 'reference_id already exists',
        ]])),
    ]);

    $exception = null;
    try {
        $driver->charge(new ChargeRequestDTO(amount: 10, currency: 'INR', email: 'buyer@example.com', reference: 'ORDER_1001'));
    } catch (ChargeException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(ChargeException::class)
        ->and($exception->isAmbiguousProviderOutcome())->toBeFalse()
        ->and($exception->getMessage())->toContain('Razorpay: reference_id already exists');
});

test('a razorpay rejection without an error description keeps the generic message', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new Response(400, [], 'not json'),
    ]);

    $exception = null;
    try {
        $driver->charge(new ChargeRequestDTO(amount: 10, currency: 'INR', email: 'buyer@example.com'));
    } catch (ChargeException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(ChargeException::class)
        ->and($exception->getMessage())->not->toContain('Razorpay:');
});

test('a razorpay charge that loses its response is ambiguous', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new RequestException('Operation timed out', new Request('POST', '/v1/payment_links')),
    ]);

    $exception = null;
    try {
        $driver->charge(new ChargeRequestDTO(amount: 10, currency: 'INR', email: 'buyer@example.com', reference: 'ORDER_1001'));
    } catch (ChargeException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(ChargeException::class)
        ->and($exception->isAmbiguousProviderOutcome())->toBeTrue();
});

test('razorpay charge maps channels to the payment link checkout methods', function () {
    $driver = RazorpayDriverTestHelper::driver([razorpayLinkCreatedResponse()], $history);

    $driver->charge(new ChargeRequestDTO(
        amount: 10,
        currency: 'INR',
        email: 'buyer@example.com',
        channels: ['card', 'mobile_money'],
    ));

    $body = json_decode((string) $history[0]['request']->getBody(), true);

    expect($body['options'])->toBe(['checkout' => ['method' => [
        'card' => true,
        'netbanking' => false,
        'upi' => true,
        'wallet' => false,
    ]]]);
});

test('razorpay verify fetches a payment link by its id', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new Response(200, [], json_encode(RazorpayDriverTestHelper::paymentLink())),
    ], $history);

    $result = $driver->verify('plink_Abc123');

    expect($history[0]['request']->getUri()->getPath())->toBe('/v1/payment_links/plink_Abc123')
        ->and($result->reference)->toBe('ORDER_1001')
        ->and($result->status)->toBe('success')
        ->and($result->isSuccessful())->toBeTrue()
        ->and($result->amount)->toBe(499.5)
        ->and($result->currency)->toBe('INR')
        ->and($result->channel)->toBe('upi')
        ->and($result->paidAt)->toBe(date('c', 1757000000))
        ->and($result->metadata)->toBe(['payzephyr_reference' => 'ORDER_1001', 'order_id' => '1001'])
        ->and($result->customer['email'])->toBe('buyer@example.com')
        ->and($result->provider)->toBe('razorpay');
});

test('razorpay verify looks a payment link up by reference, then re-fetches it by id for its payments', function () {
    // Razorpay's list endpoint returns payments: [] even for a paid link.
    $driver = RazorpayDriverTestHelper::driver([
        new Response(200, [], json_encode(['payment_links' => [RazorpayDriverTestHelper::paymentLink(['payments' => []])]])),
        new Response(200, [], json_encode(RazorpayDriverTestHelper::paymentLink())),
    ], $history);

    $result = $driver->verify('ORDER_1001');

    $lookup = $history[0]['request']->getUri();

    expect($lookup->getPath())->toBe('/v1/payment_links')
        ->and($lookup->getQuery())->toBe('reference_id=ORDER_1001')
        ->and($history[1]['request']->getUri()->getPath())->toBe('/v1/payment_links/plink_Abc123')
        ->and($result->reference)->toBe('ORDER_1001')
        ->and($result->isSuccessful())->toBeTrue()
        ->and($result->channel)->toBe('upi');
});

test('razorpay verify throws when the reference lookup returns a link without an id', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new Response(200, [], json_encode(['payment_links' => [['reference_id' => 'ORDER_1001']]])),
    ]);

    $driver->verify('ORDER_1001');
})->throws(VerificationException::class, 'without an id');

test('razorpay verify throws when no payment link matches the reference', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new Response(200, [], json_encode(['payment_links' => []])),
    ]);

    $driver->verify('ORDER_MISSING');
})->throws(VerificationException::class, 'found 0');

test('razorpay verify throws when razorpay returns an error', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new Response(404, [], json_encode(['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'The id provided does not exist']])),
    ]);

    $driver->verify('plink_Missing');
})->throws(VerificationException::class, 'Razorpay: The id provided does not exist');

test('razorpay verify normalizes payment link statuses', function (string $linkStatus, string $expected) {
    $link = RazorpayDriverTestHelper::paymentLink(['status' => $linkStatus, 'payments' => []]);
    $driver = RazorpayDriverTestHelper::driver([new Response(200, [], json_encode($link))]);

    $result = $driver->verify('plink_Abc123');

    expect($result->status)->toBe($expected)
        ->and($result->channel)->toBeNull()
        ->and($result->paidAt)->toBeNull();
})->with([
    ['created', 'pending'],
    ['partially_paid', 'pending'],
    ['expired', 'failed'],
    ['cancelled', 'failed'],
]);

test('razorpay webhook validation accepts a correctly signed, fresh event', function () {
    $driver = RazorpayDriverTestHelper::driver();
    $body = json_encode(RazorpayDriverTestHelper::paymentLinkWebhook());

    $headers = ['x-razorpay-signature' => [hash_hmac('sha256', $body, 'test_webhook_secret')]];

    expect($driver->validateWebhook($headers, $body))->toBeTrue();
});

test('razorpay webhook validation rejects a wrong signature', function () {
    $driver = RazorpayDriverTestHelper::driver();
    $body = json_encode(RazorpayDriverTestHelper::paymentLinkWebhook());

    $headers = ['x-razorpay-signature' => [hash_hmac('sha256', $body, 'test_key_secret')]];

    expect($driver->validateWebhook($headers, $body))->toBeFalse();
});

test('razorpay webhook validation rejects a missing signature header', function () {
    $driver = RazorpayDriverTestHelper::driver();

    expect($driver->validateWebhook([], json_encode(RazorpayDriverTestHelper::paymentLinkWebhook())))->toBeFalse();
});

test('razorpay webhook validation rejects every event when no webhook secret is configured', function () {
    $driver = RazorpayDriverTestHelper::driver(config: ['webhook_secret' => null]);
    $body = json_encode(RazorpayDriverTestHelper::paymentLinkWebhook());

    $headers = ['x-razorpay-signature' => [hash_hmac('sha256', $body, '')]];

    expect($driver->validateWebhook($headers, $body))->toBeFalse();
});

test('razorpay webhook validation accepts a signed event whose created_at is long past', function () {
    // Regression: Razorpay's envelope created_at is the link's creation time, so
    // a link paid an hour after creation is delivered with an hour-old value.
    $driver = RazorpayDriverTestHelper::driver();
    $body = json_encode(RazorpayDriverTestHelper::paymentLinkWebhook(createdAt: time() - 3600));

    $headers = ['x-razorpay-signature' => [hash_hmac('sha256', $body, 'test_webhook_secret')]];

    expect($driver->validateWebhook($headers, $body))->toBeTrue();
});

test('razorpay webhook validation rejects a correctly signed body that is not a json object', function () {
    $driver = RazorpayDriverTestHelper::driver();
    $body = 'not json';

    $headers = ['x-razorpay-signature' => [hash_hmac('sha256', $body, 'test_webhook_secret')]];

    expect($driver->validateWebhook($headers, $body))->toBeFalse();
});

test('razorpay extracts reference, status, channel and event id from payment link events', function () {
    $driver = RazorpayDriverTestHelper::driver();
    $payload = RazorpayDriverTestHelper::paymentLinkWebhook(createdAt: 1757000100);

    expect($driver->extractWebhookReference($payload))->toBe('ORDER_1001')
        ->and($driver->extractWebhookStatus($payload))->toBe('paid')
        ->and($driver->extractWebhookChannel($payload))->toBe('upi')
        ->and($driver->extractWebhookEventId($payload))->toBe('payment_link.paid:plink_Abc123:pay_Abc123');
});

test('razorpay refund events never carry a transaction reference or status', function () {
    // Regression guard: refund and payment events reach the same endpoint, and
    // a refund's "processed" must not be written over the payment's status.
    $driver = RazorpayDriverTestHelper::driver();
    $payload = RazorpayDriverTestHelper::refundWebhook('refund.processed', 'rfnd_Abc123', 'processed');

    expect($driver->extractWebhookReference($payload))->toBeNull()
        ->and($driver->extractWebhookStatus($payload))->toBe('unknown')
        ->and($driver->extractWebhookEventId($payload))->toBe('refund.processed:rfnd_Abc123:pay_Abc123');
});

test('razorpay event id falls back to the content hash when no entity id is present', function () {
    $driver = RazorpayDriverTestHelper::driver();

    expect($driver->extractWebhookEventId(['event' => 'payment_link.paid', 'payload' => []]))->toBeNull();
});

test('razorpay event id for an event without a payment keys on the link alone', function () {
    $driver = RazorpayDriverTestHelper::driver();

    $payload = [
        'event' => 'payment_link.cancelled',
        'payload' => ['payment_link' => ['entity' => ['id' => 'plink_Abc123']]],
    ];

    expect($driver->extractWebhookEventId($payload))->toBe('payment_link.cancelled:plink_Abc123');
});

test('a razorpay payment_link.paid webhook marks the logged transaction successful', function () {
    PaymentTransaction::create([
        'reference' => 'ORDER_1001',
        'provider' => 'razorpay',
        'status' => 'pending',
        'amount' => 499.50,
        'currency' => 'INR',
        'email' => 'buyer@example.com',
    ]);

    app()->call([new ProcessWebhook('razorpay', RazorpayDriverTestHelper::paymentLinkWebhook()), 'handle']);

    $transaction = PaymentTransaction::where('reference', 'ORDER_1001')->first();

    expect($transaction->status)->toBe('success')
        ->and($transaction->channel)->toBe('upi');
});

test('a replayed razorpay webhook with an old created_at is accepted once and deduplicated', function () {
    PaymentTransaction::create([
        'reference' => 'ORDER_1001',
        'provider' => 'razorpay',
        'status' => 'pending',
        'amount' => 499.50,
        'currency' => 'INR',
        'email' => 'buyer@example.com',
    ]);

    $body = json_encode(RazorpayDriverTestHelper::paymentLinkWebhook(createdAt: time() - 3600));
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'test_webhook_secret'),
    ];

    $first = $this->call('POST', '/payments/webhook/razorpay', [], [], [], $server, $body);
    $replay = $this->call('POST', '/payments/webhook/razorpay', [], [], [], $server, $body);

    expect($first->getStatusCode())->toBe(202)
        ->and($replay->getStatusCode())->toBe(202)
        ->and(PaymentTransaction::where('reference', 'ORDER_1001')->value('status'))->toBe('success')
        ->and(WebhookEvent::where('provider', 'razorpay')->count())->toBe(1);
});

test('razorpay health check passes when the api responds', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new Response(200, [], json_encode(['payment_links' => []])),
    ], $history);

    expect($driver->healthCheck())->toBeTrue()
        ->and($history[0]['request']->getUri()->getPath())->toBe('/v1/payment_links');
});

test('razorpay health check treats a 400 as reachable', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new ClientException('Bad Request', new Request('GET', '/v1/payment_links'), new Response(400)),
    ]);

    expect($driver->healthCheck())->toBeTrue();
});

test('razorpay health check fails on bad credentials', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new ClientException('Unauthorized', new Request('GET', '/v1/payment_links'), new Response(401)),
    ]);

    expect($driver->healthCheck())->toBeFalse();
});

test('razorpay health check fails when razorpay cannot be reached', function () {
    $driver = RazorpayDriverTestHelper::driver([
        new ConnectException('Connection refused', new Request('GET', '/v1/payment_links')),
    ]);

    expect($driver->healthCheck())->toBeFalse();
});
