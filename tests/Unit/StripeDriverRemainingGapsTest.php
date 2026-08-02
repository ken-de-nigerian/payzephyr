<?php

use KenDeNigerian\PayZephyr\Drivers\StripeDriver;
use Stripe\Exception\ApiErrorException;

/**
 * Builds a Stripe-Signature header value the same way Stripe itself does,
 * so \Stripe\Webhook::constructEvent() accepts it: t=<unix>,v1=<hmac>.
 */
function stripeGapsSignatureHeader(string $payload, string $secret, int $timestamp): string
{
    $signedPayload = $timestamp.'.'.$payload;
    $signature = hash_hmac('sha256', $signedPayload, $secret);

    return "t=$timestamp,v1=$signature";
}

function stripeGapsDriverWithMock(object $stripeMock, array $configOverrides = []): StripeDriver
{
    $config = array_merge([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD', 'EUR', 'GBP', 'NGN'],
        'callback_url' => 'https://example.com/callback',
    ], $configOverrides);

    $driver = new StripeDriver($config);
    $driver->setStripeClient($stripeMock);

    return $driver;
}

// Covers StripeDriver::getIdempotencyHeader() (line 90) - Stripe's own override
// of the AbstractDriver default, which is not exercised by charge()/verify()
// since those go through the native Stripe SDK rather than makeRequest().
test('stripe driver getIdempotencyHeader returns Idempotency-Key header', function () {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('getIdempotencyHeader');
    $method->setAccessible(true);

    $result = $method->invoke($driver, 'idem_key_123');

    expect($result)->toBe(['Idempotency-Key' => 'idem_key_123']);
});

// Covers the cs_ prefixed branch of verify() (lines ~193-198).
test('stripe verify retrieves checkout session directly for cs_ prefixed reference', function () {
    $sessionMock = (object) [
        'id' => 'cs_test_456',
        'client_reference_id' => 'ref_456',
        'payment_status' => 'paid',
        'amount_total' => 5000,
        'payment_intent' => null,
        'currency' => 'usd',
        'created' => time(),
        'payment_method_types' => ['card'],
        'customer_email' => 'cs@example.com',
        'metadata' => [],
    ];

    $sessionsService = new class($sessionMock)
    {
        public function __construct(private readonly object $session) {}

        public function retrieve()
        {
            return $this->session;
        }
    };

    $checkoutService = new class($sessionsService)
    {
        public function __construct(public object $sessions) {}
    };

    $stripeMock = new class($checkoutService)
    {
        public function __construct(public object $checkout) {}
    };

    $driver = stripeGapsDriverWithMock($stripeMock);
    $result = $driver->verify('cs_test_456');

    expect($result->status)->toBe('success')
        ->and($result->reference)->toBe('ref_456')
        ->and($result->amount)->toBe(50.0);
});

// Covers the fallback loop finding a matching checkout session by
// client_reference_id (lines ~213-225).
test('stripe verify falls back to matching checkout session by client_reference_id', function () {
    $matchingSession = (object) ['id' => 'cs_found_789', 'client_reference_id' => 'my_ref_789'];

    $fullSession = (object) [
        'id' => 'cs_found_789',
        'client_reference_id' => 'my_ref_789',
        'payment_status' => 'paid',
        'amount_total' => 7500,
        'payment_intent' => null,
        'currency' => 'usd',
        'created' => time(),
        'payment_method_types' => ['card'],
        'customer_email' => 'found@example.com',
        'metadata' => [],
    ];

    $sessionsService = new class($matchingSession, $fullSession)
    {
        public function __construct(private readonly object $matching, private readonly object $full) {}

        public function all(): object
        {
            return (object) ['data' => [$this->matching]];
        }

        public function retrieve()
        {
            return $this->full;
        }
    };

    $checkoutService = new class($sessionsService)
    {
        public function __construct(public object $sessions) {}
    };

    $stripeMock = new class($checkoutService)
    {
        public function __construct(public object $checkout) {}
    };

    $driver = stripeGapsDriverWithMock($stripeMock);
    $result = $driver->verify('my_ref_789');

    expect($result->status)->toBe('success')
        ->and($result->reference)->toBe('my_ref_789')
        ->and($result->amount)->toBe(75.0);
});

// Covers the fallback loop finding a matching PaymentIntent by
// metadata['reference'] (lines ~228-232).
test('stripe verify falls back to matching payment intent by metadata reference', function () {
    $sessionsService = new class
    {
        public function all(): object
        {
            return (object) ['data' => []];
        }
    };

    $checkoutService = new class($sessionsService)
    {
        public function __construct(public object $sessions) {}
    };

    $matchingIntent = (object) [
        'id' => 'pi_matching_999',
        'status' => 'succeeded',
        'amount' => 12000,
        'currency' => 'usd',
        'created' => time(),
        'payment_method_types' => ['card'],
        'receipt_email' => 'intent@example.com',
        'metadata' => ['reference' => 'intent_ref_999'],
    ];

    $paymentIntents = new class($matchingIntent)
    {
        public function __construct(private readonly object $intent) {}

        public function all(): object
        {
            return (object) ['data' => [$this->intent]];
        }
    };

    $stripeMock = new class($paymentIntents, $checkoutService)
    {
        public function __construct(public object $paymentIntents, public object $checkout) {}
    };

    $driver = stripeGapsDriverWithMock($stripeMock);
    $result = $driver->verify('intent_ref_999');

    expect($result->status)->toBe('success')
        ->and($result->reference)->toBe('intent_ref_999')
        ->and($result->amount)->toBe(120.0);
});

// Covers the "no signature header at all" branch of validateWebhook().
test('stripe driver rejects webhook when no signature header is present', function () {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'webhook_secret' => 'whsec_test_secret',
        'currencies' => ['USD'],
    ]);

    $result = $driver->validateWebhook([], json_encode(['type' => 'payment_intent.succeeded']));

    expect($result)->toBeFalse();
});

// Covers the "webhook secret not configured" branch of validateWebhook().
test('stripe driver rejects webhook when webhook secret is not configured', function () {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);

    $headers = ['stripe-signature' => ['t=123,v1=abcdef']];

    $result = $driver->validateWebhook($headers, json_encode(['type' => 'payment_intent.succeeded']));

    expect($result)->toBeFalse();
});

// Covers the generic Exception catch branch of validateWebhook() (lines ~299-305).
// Stripe's own Webhook::constructEvent() throws Exception\UnexpectedValueException
// (which extends the base SPL \Exception rather than SignatureVerificationException)
// when the payload is not valid JSON, even if the signature itself is valid.
test('stripe driver rejects webhook with valid signature but malformed json payload', function () {
    $secret = 'whsec_test_secret';
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'webhook_secret' => $secret,
        'currencies' => ['USD'],
    ]);

    $payload = '{not valid json';
    $timestamp = time();

    $headers = [
        'stripe-signature' => [stripeGapsSignatureHeader($payload, $secret, $timestamp)],
    ];

    $result = $driver->validateWebhook($headers, $payload);

    expect($result)->toBeFalse();
});

// Covers the immediate success path of healthCheck() (no exception thrown at all).
test('stripe driver healthCheck returns true when balance retrieval succeeds', function () {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);

    $balanceMock = Mockery::mock();
    $balanceMock->shouldReceive('retrieve')->once()->andReturn((object) ['available' => []]);

    $stripeMock = Mockery::mock();
    $stripeMock->balance = $balanceMock;

    $driver->setStripeClient($stripeMock);

    expect($driver->healthCheck())->toBeTrue();
});

// Covers the branch where ApiErrorException::getHttpStatus() returns null (line ~328).
test('stripe driver healthCheck returns false when api error has no http status', function () {
    $driver = new StripeDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD'],
    ]);

    $exception = Mockery::mock(ApiErrorException::class);
    $exception->shouldReceive('getHttpStatus')->andReturn(null);
    $exception->shouldReceive('getMessage')->andReturn('Unknown error');

    $balanceMock = Mockery::mock();
    $balanceMock->shouldReceive('retrieve')->once()->andThrow($exception);

    $stripeMock = Mockery::mock();
    $stripeMock->balance = $balanceMock;

    $driver->setStripeClient($stripeMock);

    expect($driver->healthCheck())->toBeFalse();
});
