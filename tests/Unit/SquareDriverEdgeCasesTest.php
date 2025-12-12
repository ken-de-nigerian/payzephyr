<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\SquareDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;

function createSquareDriverWithMockForEdgeCases(array $responses, array $config = []): SquareDriver
{
    $defaultConfig = [
        'access_token' => 'test_token',
        'location_id' => 'test_location',
        'base_url' => 'https://connect.squareup.com',
        'currencies' => ['USD'],
    ];

    $config = array_merge($defaultConfig, $config);
    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);
    $client = new Client(['handler' => $handlerStack]);

    $driver = new SquareDriver($config);
    $driver->setClient($client);

    return $driver;
}

test('square driver charge handles 401 error with sandbox hint', function () {
    $driver = createSquareDriverWithMockForEdgeCases([
        new ClientException('Unauthorized', new Request('POST', '/v2/checkout/payment-links'), new Response(401, [], json_encode([
            'errors' => [
                ['code' => 'UNAUTHORIZED', 'detail' => 'Invalid access token'],
            ],
        ]))),
    ], ['base_url' => 'https://connect.squareupsandbox.com']);

    $request = new ChargeRequestDTO(10000, 'USD', 'test@example.com', null, 'https://example.com/callback');

    expect(fn () => $driver->charge($request))->toThrow(\KenDeNigerian\PayZephyr\Exceptions\ChargeException::class);
});

test('square driver charge handles 403 error with production hint', function () {
    $driver = createSquareDriverWithMockForEdgeCases([
        new ClientException('Forbidden', new Request('POST', '/v2/checkout/payment-links'), new Response(403, [], json_encode([
            'errors' => [
                ['code' => 'FORBIDDEN', 'detail' => 'Access denied'],
            ],
        ]))),
    ], ['base_url' => 'https://connect.squareup.com']);

    $request = new ChargeRequestDTO(10000, 'USD', 'test@example.com', null, 'https://example.com/callback');

    expect(fn () => $driver->charge($request))->toThrow(\KenDeNigerian\PayZephyr\Exceptions\ChargeException::class);
});

test('square driver charge handles generic throwable errors', function () {
    $driver = createSquareDriverWithMockForEdgeCases([
        new \Exception('Network timeout'),
    ]);

    $request = new ChargeRequestDTO(10000, 'USD', 'test@example.com', null, 'https://example.com/callback');

    expect(fn () => $driver->charge($request))->toThrow(ChargeException::class, 'Network timeout');
});

test('square driver verifyByPaymentLinkId returns null when order_id missing', function () {
    $driver = createSquareDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'payment_link' => [
                'id' => 'link_123',
            ],
        ])),
    ]);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('verifyByPaymentLinkId');
    $method->setAccessible(true);

    $result = $method->invoke($driver, 'link_123');

    expect($result)->toBeNull();
});

test('square driver verifyByPaymentId returns null for invalid payment ID format', function () {
    $driver = createSquareDriverWithMockForEdgeCases([]);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('verifyByPaymentId');
    $method->setAccessible(true);

    $result = $method->invoke($driver, 'invalid_ref');

    expect($result)->toBeNull();
});

test('square driver verifyByPaymentId returns null for wrong length payment ID', function () {
    $driver = createSquareDriverWithMockForEdgeCases([
        new Response(404, [], json_encode(['errors' => [['code' => 'NOT_FOUND']]])),
    ]);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('verifyByPaymentId');
    $method->setAccessible(true);

    $result = $method->invoke($driver, 'payment_123');

    expect($result)->toBeNull();
});
