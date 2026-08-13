<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Drivers\MollieDriver;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use KenDeNigerian\PayZephyr\Drivers\OPayDriver;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Drivers\SquareDriver;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;

/**
 * refund() creation error handling, for every refund-capable driver.
 *
 * A refund whose creation fails must always surface a RefundException, never
 * a raw transport exception - PaymentManager and Refund both classify on that
 * type to decide whether the outcome is ambiguous, and a leaked Guzzle
 * exception would bypass that classification entirely.
 */

/** @param array<int, mixed> $queue */
function refundClient(array $queue): Client
{
    return new Client(['handler' => HandlerStack::create(new MockHandler($queue))]);
}

function lostConnection(): ConnectException
{
    return new ConnectException('Connection timed out', new Request('POST', '/refunds'));
}

function refundRequest(array $overrides = []): RefundRequestDTO
{
    return RefundRequestDTO::fromArray(array_merge([
        'transaction_reference' => 'txn_1',
        'amount' => 10.00,
        'currency' => 'NGN',
        'idempotency_key' => 'idem-key-1',
    ], $overrides));
}

test('paystack refund wraps a network failure in a RefundException', function () {
    $driver = new PaystackDriver(['secret_key' => 'sk_test_xxx', 'currencies' => ['NGN']]);
    $driver->setClient(refundClient([lostConnection()]));

    expect(fn () => $driver->refund(refundRequest()))->toThrow(RefundException::class);
});

test('flutterwave refund wraps a network failure in a RefundException', function () {
    $driver = new FlutterwaveDriver(['secret_key' => 'test_secret', 'currencies' => ['NGN']]);
    $driver->setClient(refundClient([lostConnection()]));

    expect(fn () => $driver->refund(refundRequest()))->toThrow(RefundException::class);
});

test('flutterwave refund rejects a response carrying no refund reference', function () {
    $driver = new FlutterwaveDriver(['secret_key' => 'test_secret', 'currencies' => ['NGN']]);
    $driver->setClient(refundClient([
        new Response(200, [], (string) json_encode(['status' => 'success', 'data' => []])),
    ]));

    expect(fn () => $driver->refund(refundRequest()))
        ->toThrow(RefundException::class, 'Refund reference not found');
});

test('mollie refund wraps a network failure in a RefundException', function () {
    $driver = new MollieDriver(['api_key' => 'test_test_key', 'currencies' => ['EUR']]);
    $driver->setClient(refundClient([lostConnection()]));

    expect(fn () => $driver->refund(refundRequest(['currency' => 'EUR'])))->toThrow(RefundException::class);
});

test('mollie refund rejects a response carrying no refund id', function () {
    $driver = new MollieDriver(['api_key' => 'test_test_key', 'currencies' => ['EUR']]);
    $driver->setClient(refundClient([new Response(200, [], (string) json_encode([]))]));

    expect(fn () => $driver->refund(refundRequest(['currency' => 'EUR'])))->toThrow(RefundException::class);
});

test('opay refund wraps a network failure in a RefundException', function () {
    $driver = new OPayDriver([
        'merchant_id' => 'MERCHANT123',
        'public_key' => 'PUBLIC_KEY_123',
        'secret_key' => 'SECRET_KEY_123',
        'base_url' => 'https://liveapi.opaycheckout.com',
        'currencies' => ['NGN'],
    ]);
    $driver->setClient(refundClient([lostConnection()]));

    expect(fn () => $driver->refund(refundRequest()))->toThrow(RefundException::class);
});

test('square refund wraps a network failure in a RefundException', function () {
    $driver = new SquareDriver([
        'access_token' => 'test_token',
        'location_id' => 'L123',
        'currencies' => ['USD'],
    ]);
    $driver->setClient(refundClient([lostConnection()]));

    expect(fn () => $driver->refund(refundRequest(['currency' => 'USD'])))->toThrow(RefundException::class);
});

test('square explains itself when a full refund cannot look up the original amount', function () {
    // Square's refund API requires an explicit amount, so a full refund has to
    // read the original payment first. If that read fails the error must name
    // the payment and tell the caller to pass amount() - not surface a bare
    // transport error.
    $driver = new SquareDriver([
        'access_token' => 'test_token',
        'location_id' => 'L123',
        'currencies' => ['USD'],
    ]);
    $driver->setClient(refundClient([lostConnection()]));

    expect(fn () => $driver->refund(RefundRequestDTO::fromArray([
        'transaction_reference' => 'pay_123',
        'currency' => 'USD',
    ])))->toThrow(RefundException::class, 'Pass an explicit amount()');
});

test('square fetchRefund rejects a response with no refund object', function () {
    $driver = new SquareDriver([
        'access_token' => 'test_token',
        'location_id' => 'L123',
        'currencies' => ['USD'],
    ]);
    $driver->setClient(refundClient([
        new Response(200, [], (string) json_encode(['errors' => [['detail' => 'Refund not found']]])),
    ]));

    expect(fn () => $driver->fetchRefund('rf_missing'))
        ->toThrow(RefundException::class, 'Refund not found');
});

test('monnify refund wraps a network failure in a RefundException', function () {
    $driver = new MonnifyDriver([
        'api_key' => 'MK_TEST_xxx',
        'secret_key' => 'SK_TEST_xxx',
        'contract_code' => 'CONTRACT123',
        'base_url' => 'https://sandbox.monnify.com',
        'currencies' => ['NGN'],
    ]);
    $driver->setClient(refundClient([
        new Response(200, [], (string) json_encode([
            'requestSuccessful' => true,
            'responseBody' => ['accessToken' => 'tok', 'expiresIn' => 3600],
        ])),
        lostConnection(),
    ]));

    expect(fn () => $driver->refund(refundRequest()))->toThrow(RefundException::class);
});

test('paypal refund wraps a network failure in a RefundException', function () {
    $driver = new PayPalDriver([
        'client_id' => 'test_client',
        'client_secret' => 'test_secret',
        'mode' => 'sandbox',
        'currencies' => ['USD'],
    ]);
    $driver->setClient(refundClient([
        new Response(200, [], (string) json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
        lostConnection(),
    ]));

    expect(fn () => $driver->refund(refundRequest(['currency' => 'USD'])))->toThrow(RefundException::class);
});

test('paypal refund rejects a response carrying no refund id', function () {
    $driver = new PayPalDriver([
        'client_id' => 'test_client',
        'client_secret' => 'test_secret',
        'mode' => 'sandbox',
        'currencies' => ['USD'],
    ]);
    $driver->setClient(refundClient([
        new Response(200, [], (string) json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
        new Response(200, [], (string) json_encode([])),
    ]));

    expect(fn () => $driver->refund(refundRequest(['currency' => 'USD'])))->toThrow(RefundException::class);
});
