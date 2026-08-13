<?php

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\StripeDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use Stripe\Exception\InvalidRequestException;

function createMockStripeDriver(object $stripeMock): StripeDriver
{
    $config = [
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['USD', 'EUR', 'GBP', 'NGN'],
        'callback_url' => 'https://example.com/callback',
    ];

    $driver = new StripeDriver($config);
    $driver->setStripeClient($stripeMock);

    return $driver;
}

test('stripe charge succeeds', function () {
    $sessionMock = (object) [
        'id' => 'cs_test_123',
        'url' => 'https://checkout.stripe.com/pay/cs_test_123',
        'status' => 'open',
    ];

    $sessionsService = new class($sessionMock)
    {
        public function __construct(private readonly object $session) {}

        public function create()
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

    $driver = createMockStripeDriver($stripeMock);

    $request = new ChargeRequestDTO(10000, 'USD', 'test@example.com', 'stripe_ref_123', 'https://example.com/callback');
    $response = $driver->charge($request);

    expect($response->reference)->toBe('stripe_ref_123')
        ->and($response->authorizationUrl)->toBe('https://checkout.stripe.com/pay/cs_test_123')
        ->and($response->accessCode)->toBe('cs_test_123')
        ->and($response->status)->toBe('pending');
});

test('stripe charge handles api error', function () {
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

    expect(fn () => $driver->charge(new ChargeRequestDTO(100, 'USD', 'test@example.com', null, 'https://example.com/callback')))
        ->toThrow(ChargeException::class);
});

test('stripe verify returns success', function () {
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

    $stripeMock = new class($paymentIntents, $checkoutService)
    {
        public function __construct(public object $paymentIntents, public object $checkout) {}
    };

    $driver = createMockStripeDriver($stripeMock);
    $result = $driver->verify('pi_stripe_ref_123');

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

    $stripeMock = new class($paymentIntents, $checkoutService)
    {
        public function __construct(public object $paymentIntents, public object $checkout) {}
    };

    $driver = createMockStripeDriver($stripeMock);
    $result = $driver->verify('pi_stripe_failed');

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
            return (object) ['data' => []];
        }
    };

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

    $stripeMock = new class($paymentIntents, $checkoutService)
    {
        public function __construct(public object $paymentIntents, public object $checkout) {}
    };

    $driver = createMockStripeDriver($stripeMock);

    $driver->verify('stripe_nonexistent');
})->throws(VerificationException::class);

test('stripe charge wraps a non-SDK exception in a ChargeException', function () {
    // Stripe's charge() previously caught only ApiErrorException, so anything
    // else - an unexpectedly-shaped SDK object, a TypeError - propagated raw
    // instead of as the ChargeException callers classify on.
    $sessionsService = new class
    {
        public function create()
        {
            throw new RuntimeException('unexpected SDK shape');
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
    $request = new ChargeRequestDTO(10000, 'USD', 'test@example.com', 'ref_x', 'https://example.com/callback');

    expect(fn () => $driver->charge($request))->toThrow(ChargeException::class);
});

test('stripe charge still surfaces a missing callback URL as a configuration error', function () {
    // InvalidConfigurationException is a sibling of ChargeException, not a
    // subclass - the broad Throwable catch must not swallow it into a generic
    // charge failure.
    $stripeMock = new class
    {
        public object $checkout;

        public function __construct()
        {
            $this->checkout = new class
            {
                public object $sessions;

                public function __construct()
                {
                    $this->sessions = new class
                    {
                        public function create()
                        {
                            return (object) ['id' => 'cs_1', 'url' => 'https://x.test'];
                        }
                    };
                }
            };
        }
    };

    $driver = new StripeDriver(['secret_key' => 'sk_test_xxx', 'currencies' => ['USD']]);
    $driver->setStripeClient($stripeMock);

    $request = new ChargeRequestDTO(10000, 'USD', 'test@example.com', 'ref_no_cb');

    expect(fn () => $driver->charge($request))
        ->toThrow(KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException::class);
});

test('stripe verify wraps a non-SDK exception in a VerificationException', function () {
    $sessionsService = new class
    {
        public function retrieve()
        {
            throw new RuntimeException('unexpected SDK shape');
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

    expect(fn () => $driver->verify('cs_test_123'))->toThrow(VerificationException::class);
});
