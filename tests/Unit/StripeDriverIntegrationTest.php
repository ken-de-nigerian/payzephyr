<?php

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequest;
use KenDeNigerian\PayZephyr\Drivers\StripeDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use Stripe\Exception\InvalidRequestException;

// Helper to create a fake StripeClient/Service structure
function createMockStripeDriver(object $stripeMock): StripeDriver
{
    $config = [
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD', 'EUR', 'GBP', 'NGN'],
        'callback_url' => 'https://example.com/callback',
    ];

    $driver = new class($config) extends StripeDriver
    {
        // We override this to avoid constructing the real StripeClient
        protected function initializeClient(): void
        {
            // No-op for the real client
        }
    };

    // Inject our mock
    $driver->setStripeClient($stripeMock);

    return $driver;
}

test('stripe charge succeeds', function () {
    // 1. Mock the Session object (Result of the API call)
    $sessionMock = (object) [
        'id' => 'cs_test_123',
        'url' => 'https://checkout.stripe.com/pay/cs_test_123',
        'status' => 'open',
    ];

    // 2. Mock the Sessions Service (The ->sessions part)
    $sessionsService = new class($sessionMock)
    {
        public function __construct(private readonly object $session) {}

        public function create()
        {
            return $this->session;
        }
    };

    // 3. Mock the Checkout Service (The ->checkout part)
    $checkoutService = new class($sessionsService)
    {
        public function __construct(public object $sessions) {}
    };

    // 4. Mock the Stripe Client (The root object)
    $stripeMock = new class($checkoutService)
    {
        public function __construct(public object $checkout) {}
    };

    $driver = createMockStripeDriver($stripeMock);

    $request = new ChargeRequest(10000, 'USD', 'test@example.com', 'stripe_ref_123');
    $response = $driver->charge($request);

    // Assertions based on the new Checkout Session logic
    expect($response->reference)->toBe('stripe_ref_123')
        ->and($response->authorizationUrl)->toBe('https://checkout.stripe.com/pay/cs_test_123')
        ->and($response->accessCode)->toBe('cs_test_123')
        ->and($response->status)->toBe('pending');
});

test('stripe charge handles api error', function () {
    // Mock throwing exception from the checkout session creation
    $sessionsService = new class
    {
        public function create()
        {
            throw new InvalidRequestException('Invalid currency', 400);
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

    $driver = createMockStripeDriver($stripeMock);

    // This checks that the driver correctly catches the Stripe exception
    // and rethrows it as a ChargeException (which extends PaymentException)
    // Note: The previous test checked for InvalidArgumentException from ChargeRequest,
    // but here we want to test the Driver error handling.
    // If you want to test invalid currency validation inside ChargeRequest, that is a separate unit test.
    // Here we simulate Stripe rejecting it.

    // We expect a ChargeException (wrapper), not the raw Stripe exception
    expect(fn () => $driver->charge(new ChargeRequest(100, 'USD', 'test@example.com')))
        ->toThrow(ChargeException::class);
});

test('stripe verify returns success', function () {
    // Verify uses paymentIntents, so we keep this mock structure
    $intentMock = (object) [
        'id' => 'pi_test_123',
        'status' => 'succeeded',
        'amount' => 1000000,
        'currency' => 'usd',
        'created' => time(),
        'metadata' => ['reference' => 'stripe_ref_123'],
        'payment_method' => 'pm_123',
        'receipt_email' => 'test@example.com',
    ];

    $paymentIntents = new class($intentMock)
    {
        public function __construct(private readonly object $intent) {}

        public function retrieve()
        {
            return $this->intent;
        }
    };

    $stripeMock = new class($paymentIntents)
    {
        public function __construct(public object $paymentIntents) {}
    };

    $driver = createMockStripeDriver($stripeMock);
    $result = $driver->verify('stripe_ref_123');

    expect($result->status)->toBe('success')
        ->and($result->amount)->toBe(10000.0)
        ->and($result->isSuccessful())->toBeTrue();
});

test('stripe verify returns failed', function () {
    $intentMock = (object) [
        'id' => 'pi_test_123',
        'status' => 'canceled',
        'amount' => 1000000,
        'currency' => 'usd',
        'metadata' => ['reference' => 'stripe_failed'],
        'receipt_email' => 'test@example.com',
    ];

    $paymentIntents = new class($intentMock)
    {
        public function __construct(private readonly object $intent) {}

        public function retrieve()
        {
            return $this->intent;
        }
    };

    $stripeMock = new class($paymentIntents)
    {
        public function __construct(public object $paymentIntents) {}
    };

    $driver = createMockStripeDriver($stripeMock);
    $result = $driver->verify('stripe_failed');

    expect($result->isFailed())->toBeTrue();
});

test('stripe verify handles not found', function () {
    $paymentIntents = new class
    {
        public function retrieve()
        {
            throw new InvalidRequestException('No such payment_intent', 404);
        }

        public function all(): object
        {
            // Emulate finding nothing by metadata either
            return (object) ['data' => []];
        }
    };

    $stripeMock = new class($paymentIntents)
    {
        public function __construct(public object $paymentIntents) {}
    };

    $driver = createMockStripeDriver($stripeMock);

    $driver->verify('stripe_nonexistent');
})->throws(VerificationException::class);
