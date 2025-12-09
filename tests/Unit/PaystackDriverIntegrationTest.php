<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

function createPaystackDriverWithMock(array $responses): PaystackDriver
{
    $config = [
        'secret_key' => 'sk_test_xxx',
        'base_url' => 'https://api.paystack.co',
        'currencies' => ['NGN', 'USD', 'GHS', 'ZAR'],
    ];

    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);
    $client = new Client(['handler' => $handlerStack]);

    $driver = new PaystackDriver($config);
    $driver->setClient($client);

    return $driver;
}

test('paystack charge succeeds with valid response', function () {
    $driver = createPaystackDriverWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/abc123',
                'access_code' => 'access_abc123',
                'reference' => 'ref_test_123',
            ],
        ])),
    ]);

    $request = new ChargeRequestDTO(10000, 'NGN', 'test@example.com', 'ref_test_123');
    $response = $driver->charge($request);

    expect($response->reference)->toBe('ref_test_123')
        ->and($response->authorizationUrl)->toBe('https://checkout.paystack.com/abc123')
        ->and($response->status)->toBe('pending');
});

test('paystack charge handles metadata', function () {
    $driver = createPaystackDriverWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/abc',
                'access_code' => 'access_abc',
                'reference' => 'ref_123',
            ],
        ])),
    ]);

    $request = new ChargeRequestDTO(50000, 'NGN', 'test@example.com', null, null, ['order_id' => 12345]);
    $response = $driver->charge($request);

    expect($response->metadata)->toBe(['order_id' => 12345]);
});

test('paystack charge throws exception on api error', function () {
    $driver = createPaystackDriverWithMock([
        new Response(400, [], json_encode(['status' => false, 'message' => 'Invalid amount'])),
    ]);

    $driver->charge(new ChargeRequestDTO(10000, 'NGN', 'test@example.com'));
})->throws(ChargeException::class);

test('paystack charge handles network error', function () {
    $mock = new MockHandler([
        new ConnectException('Timeout', new Request('POST', '/transaction/initialize')),
    ]);

    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $driver = new PaystackDriver(['secret_key' => 'test', 'currencies' => ['NGN']]);
    $driver->setClient($client);

    $driver->charge(new ChargeRequestDTO(10000, 'NGN', 'test@example.com'));
})->throws(ChargeException::class);

test('paystack verify returns success', function () {
    $driver = createPaystackDriverWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'reference' => 'ref_123',
                'status' => 'success',
                'amount' => 1000000,
                'currency' => 'NGN',
                'channel' => 'card',
                'authorization' => ['card_type' => 'visa'],
                'customer' => ['email' => 'test@example.com'],
            ],
        ])),
    ]);

    $result = $driver->verify('ref_123');

    expect($result->status)->toBe('success')
        ->and($result->amount)->toBe(10000.0)
        ->and($result->isSuccessful())->toBeTrue();
});

test('paystack verify returns failed', function () {
    $driver = createPaystackDriverWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'reference' => 'ref_failed',
                'status' => 'failed',
                'amount' => 1000000,
                'currency' => 'NGN',
                'customer' => ['email' => 'test@example.com'],
            ],
        ])),
    ]);

    $result = $driver->verify('ref_failed');
    expect($result->isFailed())->toBeTrue();
});

test('paystack verify handles not found', function () {
    $driver = createPaystackDriverWithMock([
        new Response(404, [], json_encode(['status' => false, 'message' => 'Not found'])),
    ]);

    $driver->verify('ref_nonexistent');
})->throws(VerificationException::class);

test('paystack verify converts kobo to naira', function () {
    $driver = createPaystackDriverWithMock([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'reference' => 'ref_123',
                'status' => 'success',
                'amount' => 5050500,
                'currency' => 'NGN',
                'customer' => ['email' => 'test@example.com'],
            ],
        ])),
    ]);

    $result = $driver->verify('ref_123');
    expect($result->amount)->toBe(50505.0);
});
