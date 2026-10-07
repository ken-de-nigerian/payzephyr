<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
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

test('square driver charge handles 401 error with sandbox hint', function (): void {
    $driver = createSquareDriverWithMockForEdgeCases([
        new ClientException('Unauthorized', new Request('POST', '/v2/checkout/payment-links'), new Response(401, [], json_encode([
            'errors' => [
                ['code' => 'UNAUTHORIZED', 'detail' => 'Invalid access token'],
            ],
        ]))),
    ], ['base_url' => 'https://connect.squareupsandbox.com']);

    $request = new ChargeRequestDTO(10000, 'USD', 'test@example.com', null, 'https://example.com/callback');

    // Regression: charge() previously rethrew AbstractDriver::makeRequest()'s
    // wrapped ChargeException verbatim without inspecting getPrevious(), so
    // this sandbox-credential hint was silently unreachable dead code.
    expect(fn (): ChargeResponseDTO => $driver->charge($request))->toThrow(ChargeException::class, 'sandbox access token');
});

test('square driver charge handles 403 error with production hint', function (): void {
    $driver = createSquareDriverWithMockForEdgeCases([
        new ClientException('Forbidden', new Request('POST', '/v2/checkout/payment-links'), new Response(403, [], json_encode([
            'errors' => [
                ['code' => 'FORBIDDEN', 'detail' => 'Access denied'],
            ],
        ]))),
    ], ['base_url' => 'https://connect.squareup.com']);

    $request = new ChargeRequestDTO(10000, 'USD', 'test@example.com', null, 'https://example.com/callback');

    // Regression: see note above - this production-credential hint was
    // equally unreachable before the fix.
    expect(fn (): ChargeResponseDTO => $driver->charge($request))->toThrow(ChargeException::class, 'production access token');
});

test('square driver charge handles generic throwable errors', function (): void {
    $driver = createSquareDriverWithMockForEdgeCases([
        new \Exception('Network timeout'),
    ]);

    $request = new ChargeRequestDTO(10000, 'USD', 'test@example.com', null, 'https://example.com/callback');

    expect(fn (): ChargeResponseDTO => $driver->charge($request))->toThrow(ChargeException::class, 'Network timeout');
});

test('square driver verifyByPaymentLinkId returns null when order_id missing', function (): void {
    $driver = createSquareDriverWithMockForEdgeCases([
        new Response(200, [], json_encode([
            'payment_link' => [
                'id' => 'link_123',
            ],
        ])),
    ]);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('verifyByPaymentLinkId');

    $result = $method->invoke($driver, 'link_123');

    expect($result)->toBeNull();
});

test('square driver verifyByPaymentId returns null for invalid payment ID format', function (): void {
    $driver = createSquareDriverWithMockForEdgeCases([]);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('verifyByPaymentId');

    $result = $method->invoke($driver, 'invalid_ref');

    expect($result)->toBeNull();
});

test('square driver verifyByPaymentId returns null for wrong length payment ID', function (): void {
    $driver = createSquareDriverWithMockForEdgeCases([
        new Response(404, [], json_encode(['errors' => [['code' => 'NOT_FOUND']]])),
    ]);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('verifyByPaymentId');

    $result = $method->invoke($driver, 'payment_123');

    expect($result)->toBeNull();
});
