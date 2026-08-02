<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

test('monnify driver getIdempotencyHeader returns correct header', function () {
    $driver = new MonnifyDriver([
        'api_key' => 'test_key',
        'secret_key' => 'test_secret',
        'contract_code' => 'test_contract',
        'currencies' => ['NGN'],
    ]);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('getIdempotencyHeader');

    $result = $method->invoke($driver, 'test_key');

    expect($result)->toBe(['Idempotency-Key' => 'test_key']);
});

test('monnify driver healthCheck returns true for successful authentication', function () {
    $driver = new MonnifyDriver([
        'api_key' => 'test_key',
        'secret_key' => 'test_secret',
        'contract_code' => 'test_contract',
        'currencies' => ['NGN'],
    ]);

    $client = Mockery::mock(Client::class);
    $response = Mockery::mock(ResponseInterface::class);
    $stream = Mockery::mock(StreamInterface::class);
    $stream->shouldReceive('__toString')->andReturn(json_encode([
        'requestSuccessful' => true,
        'responseBody' => [
            'accessToken' => 'token',
            'expiresIn' => 3600,
        ],
    ]));
    $response->shouldReceive('getBody')->andReturn($stream);

    $client->shouldReceive('request')
        ->once()
        ->andReturn($response);

    $driver->setClient($client);

    expect($driver->healthCheck())->toBeTrue();
});

test('monnify driver healthCheck returns true for 4xx errors', function () {
    // A 4xx means Monnify's API answered (just rejected these credentials),
    // matching the same "reachable but misconfigured" contract every other
    // driver's healthCheck() follows (see e.g. SquareDriver/PaystackDriver/
    // OPayDriver/FlutterwaveDriver's own "returns true for 4xx errors" tests).
    //
    // Regression: healthCheck() -> getAccessToken() double-wraps
    // makeRequest()'s own ChargeException in a second ChargeException, so
    // the real ClientException sits two levels down getPrevious(), not one.
    // A single-level getPrevious() check (the pattern this test used to pass
    // against) can never reach it, making the ClientException branch dead
    // and silently falling through to a generic `false`.
    $driver = new MonnifyDriver([
        'api_key' => 'test_key',
        'secret_key' => 'test_secret',
        'contract_code' => 'test_contract',
        'currencies' => ['NGN'],
    ]);

    $client = Mockery::mock(Client::class);
    $request = Mockery::mock(RequestInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(400);

    $client->shouldReceive('request')
        ->once()
        ->with('POST', '/api/v1/auth/login', Mockery::any())
        ->andThrow(new ClientException('Bad Request', $request, $response));

    $driver->setClient($client);

    expect($driver->healthCheck())->toBeTrue();
});

test('monnify driver healthCheck returns false for network errors', function () {
    $driver = new MonnifyDriver([
        'api_key' => 'test_key',
        'secret_key' => 'test_secret',
        'contract_code' => 'test_contract',
        'currencies' => ['NGN'],
    ]);

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('request')
        ->once()
        ->andThrow(new ConnectException('Connection timeout', Mockery::mock(RequestInterface::class)));

    $driver->setClient($client);

    expect($driver->healthCheck())->toBeFalse();
});

test('monnify driver verify handles currencyCode field', function () {
    $driver = new MonnifyDriver([
        'api_key' => 'test_key',
        'secret_key' => 'test_secret',
        'contract_code' => 'test_contract',
        'currencies' => ['NGN'],
    ]);

    $client = Mockery::mock(Client::class);

    $authResponse = Mockery::mock(ResponseInterface::class);
    $authStream = Mockery::mock(StreamInterface::class);
    $authStream->shouldReceive('__toString')->andReturn(json_encode([
        'requestSuccessful' => true,
        'responseBody' => [
            'accessToken' => 'token',
            'expiresIn' => 3600,
        ],
    ]));
    $authResponse->shouldReceive('getBody')->andReturn($authStream);

    $verifyResponse = Mockery::mock(ResponseInterface::class);
    $verifyStream = Mockery::mock(StreamInterface::class);
    $verifyStream->shouldReceive('__toString')->andReturn(json_encode([
        'requestSuccessful' => true,
        'responseBody' => [
            'paymentReference' => 'ref_123',
            'paymentStatus' => 'PAID',
            'amountPaid' => 10000,
            'currencyCode' => 'NGN', // Using currencyCode instead of currency
            'paidOn' => '2024-01-01T00:00:00Z',
            'paymentMethod' => 'CARD',
            'customer' => [
                'email' => 'test@example.com',
                'name' => 'Test User',
            ],
            'metaData' => [],
        ],
    ]));
    $verifyResponse->shouldReceive('getBody')->andReturn($verifyStream);

    $client->shouldReceive('request')
        ->with('POST', '/api/v1/auth/login', Mockery::any())
        ->andReturn($authResponse);

    $client->shouldReceive('request')
        ->with('GET', Mockery::on(function ($uri) {
            return str_contains($uri, '/api/v2/merchant/transactions/query')
                && str_contains($uri, 'paymentReference=ref_123');
        }), Mockery::any())
        ->andReturn($verifyResponse);

    $driver->setClient($client);

    $result = $driver->verify('ref_123');

    expect($result->currency)->toBe('NGN');
});
