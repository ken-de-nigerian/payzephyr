<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;
use KenDeNigerian\PayZephyr\PaymentManager;
use Stripe\Exception\ApiConnectionException;

/**
 * Proves the distinction ChargeException::isAmbiguousProviderOutcome() draws
 * actually changes chargeWithFallback()'s behaviour end-to-end, through the
 * real Guzzle-backed driver path (not just the exception class in
 * isolation): a request that was never transmitted is safe to retry against
 * a fallback provider; a request that was sent but whose response was lost
 * is not, since the original provider may have already processed it.
 */
function makeGuzzleThrowingPaystackDriver(Throwable $throwOnRequest): PaystackDriver
{
    $driver = new PaystackDriver(['secret_key' => 'sk_test_xxx', 'currencies' => ['NGN']]);

    $handler = new MockHandler([$throwOnRequest]);
    $driver->setClient(new Client(['handler' => HandlerStack::create($handler)]));

    return $driver;
}

beforeEach(function () {
    app()->forgetInstance('payments.config');

    config([
        'payments.default' => 'paystack',
        'payments.fallback' => 'stripe',
        'payments.health_check.enabled' => false,
        'payments.providers' => [
            'paystack' => ['driver' => 'paystack', 'enabled' => true, 'secret_key' => 'sk_test_xxx'],
            'stripe' => ['driver' => 'stripe', 'enabled' => true, 'secret_key' => 'sk_test_xxx'],
        ],
    ]);
});

function makeStandardChargeRequest(): ChargeRequestDTO
{
    return ChargeRequestDTO::fromArray([
        'amount' => 100.00,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'callback_url' => 'https://example.com/callback',
    ]);
}

test('a connection failure (request never transmitted) safely falls back to the next provider', function () {
    $request = new Request('POST', 'https://api.paystack.co/transaction/initialize');
    $primary = makeGuzzleThrowingPaystackDriver(
        new ConnectException('Could not resolve host', $request)
    );
    $secondary = makeCountingSuccessDriver('stripe');

    $manager = new PaymentManager;
    injectFakeDrivers($manager, ['paystack' => $primary, 'stripe' => $secondary]);

    $response = $manager->chargeWithFallback(makeStandardChargeRequest());

    expect($response->reference)->toBe('ref_stripe')
        ->and($secondary->chargeCalls)->toBe(1);
});

test('a lost response after the request was sent does not fall back to another provider', function () {
    // Regression: RequestException with no response (e.g. a read timeout
    // after the connection succeeded) means the provider may have already
    // received and processed the charge. Falling back to a different
    // provider here risks a duplicate charge.
    $request = new Request('POST', 'https://api.paystack.co/transaction/initialize');
    $primary = makeGuzzleThrowingPaystackDriver(
        new RequestException('cURL error 28: Operation timed out', $request, null)
    );
    $secondary = makeCountingSuccessDriver('stripe');

    $manager = new PaymentManager;
    injectFakeDrivers($manager, ['paystack' => $primary, 'stripe' => $secondary]);

    $exception = null;
    try {
        $manager->chargeWithFallback(makeStandardChargeRequest());
    } catch (ProviderException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull()
        ->and($exception->getMessage())->toContain('will not retry this against a different provider')
        ->and($exception->getContext()['ambiguous_outcome'] ?? null)->toBeTrue()
        ->and($secondary->chargeCalls)->toBe(0);
});

test('a definitive error response from the provider safely falls back to the next provider', function () {
    // A real HTTP response (even an error one) means the provider actively
    // participated in the exchange and gave a definitive answer - safe to
    // try elsewhere, matching existing behaviour.
    $request = new Request('POST', 'https://api.paystack.co/transaction/initialize');
    $response = new Response(500, [], 'Internal Server Error');
    $primary = makeGuzzleThrowingPaystackDriver(
        new RequestException('Server error', $request, $response)
    );
    $secondary = makeCountingSuccessDriver('stripe');

    $manager = new PaymentManager;
    injectFakeDrivers($manager, ['paystack' => $primary, 'stripe' => $secondary]);

    $result = $manager->chargeWithFallback(makeStandardChargeRequest());

    expect($result->reference)->toBe('ref_stripe')
        ->and($secondary->chargeCalls)->toBe(1);
});

test('ChargeException::isAmbiguousProviderOutcome is false when there is no underlying network exception', function () {
    $exception = new ChargeException('Invalid API key');

    expect($exception->isAmbiguousProviderOutcome())->toBeFalse();
});

test('ChargeException::isAmbiguousProviderOutcome is true for a Stripe SDK connection failure', function () {
    $stripeException = new ApiConnectionException('Could not connect to Stripe');
    $exception = new ChargeException('Stripe charge failed', 0, $stripeException);

    expect($exception->isAmbiguousProviderOutcome())->toBeTrue();
});
