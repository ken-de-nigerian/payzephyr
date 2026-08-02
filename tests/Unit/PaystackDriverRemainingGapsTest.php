<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

/**
 * Closes remaining coverage gaps in PaystackDriver that are not exercised by
 * PaystackDriverCoverageTest, PaystackDriverIntegrationTest, DriversTest,
 * WebhookSignatureTest, or WebhookTest.
 */
function paystackRemainingGapsDriver(array $responses): PaystackDriver
{
    $mock = new MockHandler($responses);
    $client = new Client(['handler' => HandlerStack::create($mock)]);

    $driver = new PaystackDriver(['secret_key' => 'sk_test_xxx', 'currencies' => ['NGN']]);
    $driver->setClient($client);

    return $driver;
}

test('paystack driver throws invalid configuration exception when secret key is missing', function () {
    new PaystackDriver(['currencies' => ['NGN']]);
})->throws(InvalidConfigurationException::class, 'Paystack secret key is required');

test('paystack driver charge throws when body reports status false with a 200 response', function () {
    // A non-2xx HTTP status is converted into a ChargeException by
    // AbstractDriver::makeRequest() before parseResponse() ever runs, so the
    // explicit `if (! ($data['status'] ?? false))` body check in charge()
    // can only be reached via a 200 response whose JSON body itself reports
    // failure (this is how Paystack signals some validation failures).
    $driver = paystackRemainingGapsDriver([
        new Response(200, [], json_encode([
            'status' => false,
            'message' => 'Duplicate transaction reference',
        ])),
    ]);

    $driver->charge(new ChargeRequestDTO(10000, 'NGN', 'test@example.com'));
})->throws(ChargeException::class, 'Duplicate transaction reference');

test('paystack driver charge wraps unexpected non-charge errors in a charge exception', function () {
    // status: true but the 'data' object is missing 'access_code'. Building
    // the ChargeResponseDTO then passes null into its non-nullable
    // `string $accessCode` parameter, triggering a TypeError under
    // strict_types - a Throwable that isn't already a ChargeException, so it
    // must be caught by the generic catch (Throwable $e) block.
    $driver = paystackRemainingGapsDriver([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/abc',
                'reference' => 'ref_missing_code',
            ],
        ])),
    ]);

    $driver->charge(new ChargeRequestDTO(10000, 'NGN', 'test@example.com'));
})->throws(ChargeException::class, 'Payment initialization failed');

test('paystack driver verify throws when body reports status false with a 200 response', function () {
    $driver = paystackRemainingGapsDriver([
        new Response(200, [], json_encode([
            'status' => false,
            'message' => 'Transaction reference not found',
        ])),
    ]);

    $driver->verify('ref_not_found');
})->throws(VerificationException::class, 'Transaction reference not found');

test('paystack driver healthCheck returns true when a ClientException carries a 400/404 response', function () {
    // Unlike the Mockery-based ClientException test in
    // PaystackDriverCoverageTest (which throws directly from the mocked
    // client), this drives the exception through the real Guzzle client so
    // AbstractDriver::makeRequest() wraps it as
    // ChargeException(msg, 0, $clientException) first - exercising
    // healthCheck()'s getPrevious() chain-walk rather than a bare catch.
    $request = new Request('GET', '/transaction/verify/invalid_ref_test');
    $response = new Response(400, [], json_encode(['status' => false, 'message' => 'Invalid key']));

    $driver = paystackRemainingGapsDriver([
        new ClientException('Bad Request', $request, $response),
    ]);

    expect($driver->healthCheck())->toBeTrue();
});
