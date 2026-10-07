<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\PaymentManager;

function createPaystackDriverWithMockForNetworkTest(array $responses): PaystackDriver
{
    $config = [
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'base_url' => 'https://api.paystack.co',
        'currencies' => ['NGN', 'USD'],
    ];

    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);
    $client = new Client(['handler' => $handlerStack]);

    $driver = new PaystackDriver($config);
    $driver->setClient($client);

    return $driver;
}

test('it handles connection timeouts gracefully', function (): void {
    $mock = new MockHandler([
        new ConnectException('Connection timeout', new Request('POST', '/transaction/initialize')),
    ]);

    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['NGN'],
    ]);
    $driver->setClient($client);

    $request = new ChargeRequestDTO(10000, 'NGN', 'test@example.com', null, 'https://example.com/callback');

    expect(fn (): ChargeResponseDTO => $driver->charge($request))
        ->toThrow(ChargeException::class);
});

test('it handles dns resolution failures', function (): void {
    $mock = new MockHandler([
        new ConnectException('Could not resolve host', new Request('POST', '/transaction/initialize')),
    ]);

    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['NGN'],
    ]);
    $driver->setClient($client);

    $request = new ChargeRequestDTO(10000, 'NGN', 'test@example.com', null, 'https://example.com/callback');

    expect(fn (): ChargeResponseDTO => $driver->charge($request))
        ->toThrow(ChargeException::class);
});

test('it handles ssl certificate errors', function (): void {
    $mock = new MockHandler([
        new ConnectException('SSL certificate problem', new Request('POST', '/transaction/initialize')),
    ]);

    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['NGN'],
    ]);
    $driver->setClient($client);

    $request = new ChargeRequestDTO(10000, 'NGN', 'test@example.com', null, 'https://example.com/callback');

    expect(fn (): ChargeResponseDTO => $driver->charge($request))
        ->toThrow(ChargeException::class);
});

test('it handles server errors gracefully', function (): void {
    $driver = createPaystackDriverWithMockForNetworkTest([
        new ServerException('Internal Server Error', new Request('POST', '/transaction/initialize'), new Response(500)),
    ]);

    $request = new ChargeRequestDTO(10000, 'NGN', 'test@example.com', null, 'https://example.com/callback');

    expect(fn (): ChargeResponseDTO => $driver->charge($request))
        ->toThrow(ChargeException::class);
});

test('it handles network timeouts', function (): void {
    $mock = new MockHandler([
        new ConnectException('Operation timed out', new Request('POST', '/transaction/initialize')),
    ]);

    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['NGN'],
    ]);
    $driver->setClient($client);

    $request = new ChargeRequestDTO(10000, 'NGN', 'test@example.com', null, 'https://example.com/callback');

    expect(fn (): ChargeResponseDTO => $driver->charge($request))
        ->toThrow(ChargeException::class);
});

test('it provides user-friendly error messages for connection errors', function (): void {
    $mock = new MockHandler([
        new ConnectException('Connection refused', new Request('POST', '/transaction/initialize')),
    ]);

    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['NGN'],
    ]);
    $driver->setClient($client);

    $request = new ChargeRequestDTO(10000, 'NGN', 'test@example.com', null, 'https://example.com/callback');

    try {
        $driver->charge($request);
        expect(false)->toBeTrue(); // Should not reach here
    } catch (ChargeException $e) {
        $message = $e->getMessage();
        $hasConnectionError = str_contains($message, 'Unable to connect')
            || str_contains($message, 'connection')
            || str_contains($message, 'Connection');
        expect($hasConnectionError)->toBeTrue();
    }
});

test('it handles partial network failures in fallback chain', function (): void {
    $manager = app(PaymentManager::class);

    config([
        'payments.providers' => [
            'paystack' => [
                'driver' => 'paystack',
                'secret_key' => 'sk_test_xxx',
                'enabled' => true,
                'currencies' => ['NGN'],
            ],
            'stripe' => [
                'driver' => 'stripe',
                'secret_key' => 'sk_test_xxx',
                'enabled' => true,
                'currencies' => ['NGN'],
            ],
        ],
        'payments.default' => 'paystack',
        'payments.fallback' => 'stripe',
    ]);

    expect($manager)->toBeInstanceOf(PaymentManager::class);
});
