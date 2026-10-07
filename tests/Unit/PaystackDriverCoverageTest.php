<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

test('paystack driver getIdempotencyHeader returns correct header', function (): void {
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('getIdempotencyHeader');

    $result = $method->invoke($driver, 'test_key');

    expect($result)->toBe(['Idempotency-Key' => 'test_key']);
});

test('paystack driver healthCheck returns true for 4xx errors', function (): void {
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $client = Mockery::mock(Client::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(404);

    $client->shouldReceive('request')
        ->once()
        ->andReturn($response);

    $driver->setClient($client);

    expect($driver->healthCheck())->toBeTrue();
});

test('paystack driver healthCheck returns true for 2xx responses', function (): void {
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $client = Mockery::mock(Client::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);

    $client->shouldReceive('request')
        ->once()
        ->andReturn($response);

    $driver->setClient($client);

    expect($driver->healthCheck())->toBeTrue();
});

test('paystack driver healthCheck returns false for 5xx errors', function (): void {
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $client = Mockery::mock(Client::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(500);

    $client->shouldReceive('request')
        ->once()
        ->andReturn($response);

    $driver->setClient($client);

    expect($driver->healthCheck())->toBeFalse();
});

test('paystack driver healthCheck returns false for network errors', function (): void {
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('request')
        ->once()
        ->andThrow(new ConnectException('Connection timeout', Mockery::mock(RequestInterface::class)));

    $driver->setClient($client);

    expect($driver->healthCheck())->toBeFalse();
});

test('paystack driver healthCheck handles ClientException with status code', function (): void {
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $client = Mockery::mock(Client::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(400);

    $client->shouldReceive('request')
        ->once()
        ->andThrow(new ClientException('Bad Request', Mockery::mock(RequestInterface::class), $response));

    $driver->setClient($client);

    expect($driver->healthCheck())->toBeTrue();
});
