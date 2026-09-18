<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\OPayDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

function createOPayDriverWithMock(array $responses): OPayDriver
{
    $config = [
        'merchant_id' => 'MERCHANT123',
        'public_key' => 'PUBLIC_KEY_123',
        'secret_key' => 'SECRET_KEY_123', // Required for status API authentication
        'base_url' => 'https://liveapi.opaycheckout.com',
        'currencies' => ['NGN'],
    ];

    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);
    $client = new Client(['handler' => $handlerStack]);

    $driver = new OPayDriver($config);
    $driver->setClient($client);

    return $driver;
}

test('opay charges successfully and returns cashierUrl', function () {
    $driver = createOPayDriverWithMock([
        new Response(200, [], json_encode([
            'code' => '00000',
            'message' => 'Success',
            'data' => [
                'cashierUrl' => 'https://checkout.opayweb.com/abc123',
                'orderNo' => 'opay_ref_123',
            ],
        ])),
    ]);

    $request = new ChargeRequestDTO(20000, 'NGN', 'test@example.com', null, 'https://example.com/callback');
    $response = $driver->charge($request);

    expect($response->reference)->toStartWith('OPAY_')
        ->and($response->authorizationUrl)->toBe('https://checkout.opayweb.com/abc123')
        ->and($response->status)->toBe('pending');
});

test('opay charge throws exception when code is not 00000', function () {
    $driver = createOPayDriverWithMock([
        new Response(200, [], json_encode([
            'code' => '00001',
            'message' => 'Invalid request',
        ])),
    ]);

    $driver->charge(new ChargeRequestDTO(10000, 'NGN', 'test@example.com'));
})->throws(ChargeException::class);

test('opay charge handles network error', function () {
    $mock = new MockHandler([
        new ConnectException('Timeout', new Request('POST', '/api/v3/international/cashier/create')),
    ]);

    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $driver = new OPayDriver([
        'merchant_id' => 'test',
        'public_key' => 'test',
        'currencies' => ['NGN'],
    ]);
    $driver->setClient($client);

    $request = new ChargeRequestDTO(10000, 'NGN', 'test@example.com');

    $driver->charge($request);
})->throws(ChargeException::class);

test('opay verify returns success for code 00000', function () {
    $driver = createOPayDriverWithMock([
        new Response(200, [], json_encode([
            'code' => '00000',
            'message' => 'Success',
            'data' => [
                'reference' => 'OPAY_123',
                'amount' => [
                    'total' => 20000,
                    'currency' => 'NGN',
                ],
                'status' => 'SUCCESS',
                'createTime' => strtotime('2024-01-01T12:00:00Z'),
                'payTime' => '2024-01-01T12:00:00Z',
                'customerEmail' => 'test@example.com',
                'customerName' => 'Test User',
            ],
        ])),
    ]);

    $result = $driver->verify('OPAY_123');

    expect($result->status)->toBe('success')
        ->and($result->amount)->toBe(200.0)
        ->and($result->currency)->toBe('NGN');
});

test('opay verify returns pending for PENDING status', function () {
    $driver = createOPayDriverWithMock([
        new Response(200, [], json_encode([
            'code' => '00000',
            'message' => 'Success',
            'data' => [
                'reference' => 'OPAY_123',
                // The nested shape OPay actually returns. This fixture used to
                // pass `amount` as a scalar, which meant the driver reported
                // 0.0 - and the test passed anyway, because it only asserted
                // the status. The amount is asserted here now for that reason.
                'amount' => [
                    'total' => 20000,
                    'currency' => 'NGN',
                ],
                'status' => 'PENDING',
            ],
        ])),
    ]);

    $result = $driver->verify('OPAY_123');

    expect($result->status)->toBe('pending')
        ->and($result->amount)->toBe(200.0)
        ->and($result->currency)->toBe('NGN');
});

test('opay verify throws exception when code is not 00000', function () {
    $driver = createOPayDriverWithMock([
        new Response(200, [], json_encode([
            'code' => '00001',
            'message' => 'Transaction not found',
        ])),
    ]);

    $driver->verify('OPAY_123');
})->throws(VerificationException::class);

test('opay verify handles network error', function () {
    $mock = new MockHandler([
        new ConnectException('Timeout', new Request('POST', '/api/v3/international/cashier/query')),
    ]);

    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $driver = new OPayDriver([
        'merchant_id' => 'test',
        'public_key' => 'test',
        'secret_key' => 'test_secret', // Required for status API authentication
        'currencies' => ['NGN'],
    ]);
    $driver->setClient($client);

    $driver->verify('OPAY_123');
})->throws(VerificationException::class);
