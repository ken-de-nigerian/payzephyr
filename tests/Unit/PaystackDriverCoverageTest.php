<?php

use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;

test('paystack driver getIdempotencyHeader returns correct header', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('getIdempotencyHeader');
    $method->setAccessible(true);

    $result = $method->invoke($driver, 'test_key');

    expect($result)->toBe(['Idempotency-Key' => 'test_key']);
});

test('paystack driver healthCheck returns true for 4xx errors', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $client = Mockery::mock(\GuzzleHttp\Client::class);
    $response = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(404);

    $client->shouldReceive('request')
        ->once()
        ->andReturn($response);

    $driver->setClient($client);

    expect($driver->healthCheck())->toBeTrue();
});

test('paystack driver healthCheck returns true for 2xx responses', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $client = Mockery::mock(\GuzzleHttp\Client::class);
    $response = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);

    $client->shouldReceive('request')
        ->once()
        ->andReturn($response);

    $driver->setClient($client);

    expect($driver->healthCheck())->toBeTrue();
});

test('paystack driver healthCheck returns false for 5xx errors', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $client = Mockery::mock(\GuzzleHttp\Client::class);
    $response = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(500);

    $client->shouldReceive('request')
        ->once()
        ->andReturn($response);

    $driver->setClient($client);

    expect($driver->healthCheck())->toBeFalse();
});

test('paystack driver healthCheck returns false for network errors', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $client = Mockery::mock(\GuzzleHttp\Client::class);
    $client->shouldReceive('request')
        ->once()
        ->andThrow(new \GuzzleHttp\Exception\ConnectException('Connection timeout', Mockery::mock(\Psr\Http\Message\RequestInterface::class)));

    $driver->setClient($client);

    expect($driver->healthCheck())->toBeFalse();
});

test('paystack driver healthCheck handles ClientException with status code', function () {
    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $client = Mockery::mock(\GuzzleHttp\Client::class);
    $response = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(400);

    $client->shouldReceive('request')
        ->once()
        ->andThrow(new \GuzzleHttp\Exception\ClientException('Bad Request', Mockery::mock(\Psr\Http\Message\RequestInterface::class), $response));

    $driver->setClient($client);

    // A 400 Bad Request from Paystack when checking invalid_ref_test means the API is working correctly
    // The API is responding as expected (transaction not found), which indicates it's operational
    expect($driver->healthCheck())->toBeTrue();
});
