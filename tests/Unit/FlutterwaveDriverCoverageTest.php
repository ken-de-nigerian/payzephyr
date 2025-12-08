<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

test('flutterwave driver getIdempotencyHeader returns correct header', function () {
    $driver = new FlutterwaveDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('getIdempotencyHeader');

    $result = $method->invoke($driver, 'test_key');

    expect($result)->toBe(['Idempotency-Key' => 'test_key']);
});

test('flutterwave driver healthCheck returns true for 200 response', function () {
    $driver = new FlutterwaveDriver([
        'secret_key' => 'sk_test_xxx',
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

test('flutterwave driver healthCheck returns true for 4xx errors', function () {
    $driver = new FlutterwaveDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $client = Mockery::mock(Client::class);
    $request = Mockery::mock(RequestInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(400);
    $client->shouldReceive('request')
        ->once()
        ->andThrow(new ClientException('Bad Request', $request, $response));

    $driver->setClient($client);

    expect($driver->healthCheck())->toBeTrue();
});

test('flutterwave driver healthCheck returns false for network errors', function () {
    $driver = new FlutterwaveDriver([
        'secret_key' => 'sk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('request')
        ->once()
        ->andThrow(new ConnectException('Connection timeout', Mockery::mock(RequestInterface::class)));

    $driver->setClient($client);

    expect($driver->healthCheck())->toBeFalse();
});
