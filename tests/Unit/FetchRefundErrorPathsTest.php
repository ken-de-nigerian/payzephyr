<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Drivers\MollieDriver;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use KenDeNigerian\PayZephyr\Drivers\OPayDriver;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Drivers\SquareDriver;
use KenDeNigerian\PayZephyr\Drivers\StripeDriver;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use Stripe\Exception\InvalidRequestException;

/**
 * fetchRefund() error handling, for every refund-capable driver.
 *
 * fetchRefund() is how an application reconciles a refund whose outcome it is
 * unsure of - exactly the situation after a timeout or a lost response. Its
 * failure path therefore has to surface a clean RefundException rather than
 * leaking a raw Guzzle/SDK exception, or the caller cannot distinguish
 * "the refund lookup failed" from "the refund does not exist".
 *
 * Every driver's fetchRefund() catch block was previously unexercised.
 */
function clientThatFailsToConnect(): Client
{
    $mock = new MockHandler([
        new ConnectException('Connection timed out', new Request('GET', '/refunds/rf_1')),
    ]);

    return new Client(['handler' => HandlerStack::create($mock)]);
}

/** @param array<int, Response> $responses */
function clientReturning(array $responses): Client
{
    return new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
}

test('paystack fetchRefund wraps a network failure in a RefundException', function () {
    $driver = new PaystackDriver(['secret_key' => 'sk_test_xxx', 'currencies' => ['NGN']]);
    $driver->setClient(clientThatFailsToConnect());

    expect(fn () => $driver->fetchRefund('rf_1'))->toThrow(RefundException::class);
});

test('flutterwave fetchRefund wraps a network failure in a RefundException', function () {
    $driver = new FlutterwaveDriver(['secret_key' => 'test_secret', 'currencies' => ['NGN']]);
    $driver->setClient(clientThatFailsToConnect());

    expect(fn () => $driver->fetchRefund('rf_1'))->toThrow(RefundException::class);
});

test('mollie fetchRefund wraps a network failure in a RefundException', function () {
    $driver = new MollieDriver(['api_key' => 'test_test_key', 'currencies' => ['EUR']]);
    $driver->setClient(clientThatFailsToConnect());

    // Mollie keys refunds as "{paymentId}:{refundId}".
    expect(fn () => $driver->fetchRefund('tr_1:re_1'))->toThrow(RefundException::class);
});

test('square fetchRefund wraps a network failure in a RefundException', function () {
    $driver = new SquareDriver([
        'access_token' => 'test_token',
        'location_id' => 'L123',
        'currencies' => ['USD'],
    ]);
    $driver->setClient(clientThatFailsToConnect());

    expect(fn () => $driver->fetchRefund('rf_1'))->toThrow(RefundException::class);
});

test('opay fetchRefund wraps a network failure in a RefundException', function () {
    $driver = new OPayDriver([
        'merchant_id' => 'MERCHANT123',
        'public_key' => 'PUBLIC_KEY_123',
        'secret_key' => 'SECRET_KEY_123',
        'base_url' => 'https://liveapi.opaycheckout.com',
        'currencies' => ['NGN'],
    ]);
    $driver->setClient(clientThatFailsToConnect());

    expect(fn () => $driver->fetchRefund('rf_1'))->toThrow(RefundException::class);
});

test('monnify fetchRefund wraps a network failure in a RefundException', function () {
    $driver = new MonnifyDriver([
        'api_key' => 'MK_TEST_xxx',
        'secret_key' => 'SK_TEST_xxx',
        'contract_code' => 'CONTRACT123',
        'base_url' => 'https://sandbox.monnify.com',
        'currencies' => ['NGN'],
    ]);

    // Monnify authenticates first, so the OAuth call must succeed for the
    // fetch itself to be the thing that fails.
    $driver->setClient(new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, [], (string) json_encode([
            'requestSuccessful' => true,
            'responseBody' => ['accessToken' => 'bearer_token_xyz', 'expiresIn' => 3600],
        ])),
        new ConnectException('Connection timed out', new Request('GET', '/refunds/rf_1')),
    ]))]));

    expect(fn () => $driver->fetchRefund('rf_1'))->toThrow(RefundException::class);
});

test('paypal fetchRefund wraps a network failure in a RefundException', function () {
    $driver = new PayPalDriver([
        'client_id' => 'test_client',
        'client_secret' => 'test_secret',
        'mode' => 'sandbox',
        'currencies' => ['USD'],
    ]);

    $driver->setClient(new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, [], (string) json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
        new ConnectException('Connection timed out', new Request('GET', '/v2/payments/refunds/rf_1')),
    ]))]));

    expect(fn () => $driver->fetchRefund('rf_1'))->toThrow(RefundException::class);
});

test('stripe fetchRefund wraps an SDK error in a RefundException', function () {
    $refundsService = new class
    {
        public function retrieve()
        {
            throw new InvalidRequestException('No such refund', 404);
        }
    };

    $stripeMock = new class($refundsService)
    {
        public function __construct(public object $refunds) {}
    };

    $driver = new StripeDriver(['secret_key' => 'sk_test', 'currencies' => ['USD']]);
    $driver->setStripeClient($stripeMock);

    expect(fn () => $driver->fetchRefund('re_1'))->toThrow(RefundException::class);
});

test('paystack fetchRefund rejects a body whose status flag is false', function () {
    // A 200 response that reports failure in the body must not be read as a
    // successful lookup.
    $driver = new PaystackDriver(['secret_key' => 'sk_test_xxx', 'currencies' => ['NGN']]);
    $driver->setClient(clientReturning([
        new Response(200, [], (string) json_encode(['status' => false, 'message' => 'Refund not found'])),
    ]));

    expect(fn () => $driver->fetchRefund('rf_missing'))->toThrow(RefundException::class);
});

test('flutterwave fetchRefund rejects a body whose status is not success', function () {
    $driver = new FlutterwaveDriver(['secret_key' => 'test_secret', 'currencies' => ['NGN']]);
    $driver->setClient(clientReturning([
        new Response(200, [], (string) json_encode(['status' => 'error', 'message' => 'not found'])),
    ]));

    expect(fn () => $driver->fetchRefund('rf_missing'))->toThrow(RefundException::class);
});

test('mollie fetchRefund rejects a malformed composite reference', function () {
    // Mollie refunds live under their payment, so the reference must carry
    // both ids - a bare refund id cannot be resolved.
    $driver = new MollieDriver(['api_key' => 'test_test_key', 'currencies' => ['EUR']]);
    $driver->setClient(clientReturning([]));

    expect(fn () => $driver->fetchRefund('re_no_payment_id'))->toThrow(RefundException::class);
});
