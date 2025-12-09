<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

function createFlutterwaveDriverWithMock(array $responses): FlutterwaveDriver
{
    $config = [
        'secret_key' => 'FLWSECK_TEST-xxx',
        'base_url' => 'https://api.flutterwave.com/v3',
        'currencies' => ['NGN', 'USD', 'GHS', 'KES', 'UGX', 'ZAR'],
        'callback_url' => 'https://example.com/webhook',
    ];

    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);
    $client = new Client(['handler' => $handlerStack]);

    $driver = new FlutterwaveDriver($config);
    $driver->setClient($client);

    return $driver;
}

test('flutterwave charge succeeds', function () {
    $driver = createFlutterwaveDriverWithMock([
        new Response(200, [], json_encode([
            'status' => 'success',
            'data' => [
                'link' => 'https://checkout.flutterwave.com/xyz789',
                'tx_ref' => 'fw_ref_123',
            ],
        ])),
    ]);

    $request = new ChargeRequestDTO(15000, 'NGN', 'test@example.com', 'fw_ref_123');
    $response = $driver->charge($request);

    expect($response->reference)->toBe('fw_ref_123')
        ->and($response->authorizationUrl)->toBe('https://checkout.flutterwave.com/xyz789')
        ->and($response->status)->toBe('pending');
});

test('flutterwave charge throws exception on error', function () {
    $driver = createFlutterwaveDriverWithMock([
        new Response(400, [], json_encode([
            'status' => 'error',
            'message' => 'Invalid currency',
        ])),
    ]);

    $driver->charge(new ChargeRequestDTO(10000, 'INVALID', 'test@example.com'));
})->throws(InvalidArgumentException::class);

test('flutterwave charge handles network error', function () {
    $mock = new MockHandler([
        new ConnectException('Timeout', new Request('POST', '/payments')),
    ]);

    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $driver = new FlutterwaveDriver(['secret_key' => 'test', 'currencies' => ['NGN'], 'callback_url' => 'http://test']);
    $driver->setClient($client);

    $driver->charge(new ChargeRequestDTO(10000, 'NGN', 'test@example.com'));
})->throws(ChargeException::class);

test('flutterwave verify returns success', function () {
    $driver = createFlutterwaveDriverWithMock([
        new Response(200, [], json_encode([
            'status' => 'success',
            'data' => [
                'tx_ref' => 'fw_ref_123',
                'status' => 'successful',
                'amount' => 15000,
                'currency' => 'NGN',
                'payment_type' => 'card',
                'customer' => ['email' => 'test@example.com'],
            ],
        ])),
    ]);

    $result = $driver->verify('fw_ref_123');

    expect($result->status)->toBe('success')
        ->and($result->amount)->toBe(15000.0)
        ->and($result->isSuccessful())->toBeTrue();
});

test('flutterwave verify returns failed', function () {
    $driver = createFlutterwaveDriverWithMock([
        new Response(200, [], json_encode([
            'status' => 'success',
            'data' => [
                'tx_ref' => 'fw_failed',
                'status' => 'failed',
                'amount' => 10000,
                'currency' => 'NGN',
                'customer' => ['email' => 'test@example.com'],
            ],
        ])),
    ]);

    $result = $driver->verify('fw_failed');
    expect($result->isFailed())->toBeTrue();
});

test('flutterwave verify handles not found', function () {
    $driver = createFlutterwaveDriverWithMock([
        new Response(404, [], json_encode([
            'status' => 'error',
            'message' => 'Transaction not found',
        ])),
    ]);

    $driver->verify('fw_nonexistent');
})->throws(VerificationException::class);

test('flutterwave verify handles network error', function () {
    $mock = new MockHandler([
        new ConnectException('Network error', new Request('GET', '/transactions/123/verify')),
    ]);

    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $driver = new FlutterwaveDriver(['secret_key' => 'test', 'currencies' => ['NGN']]);
    $driver->setClient($client);

    $driver->verify('fw_123');
})->throws(VerificationException::class);
