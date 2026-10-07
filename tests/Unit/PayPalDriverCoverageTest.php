<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

test('paypal driver getCurrencyDecimals returns 0 for zero-decimal currencies', function (): void {
    $driver = new PayPalDriver([
        'client_id' => 'test',
        'client_secret' => 'test',
        'mode' => 'sandbox',
        'currencies' => ['USD', 'JPY'],
    ]);

    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('getCurrencyDecimals');

    expect($method->invoke($driver, 'JPY'))->toBe(0)
        ->and($method->invoke($driver, 'KRW'))->toBe(0)
        ->and($method->invoke($driver, 'CLP'))->toBe(0)
        ->and($method->invoke($driver, 'BIF'))->toBe(0);
});

test('paypal driver getCurrencyDecimals returns 2 for standard currencies', function (): void {
    $driver = new PayPalDriver([
        'client_id' => 'test',
        'client_secret' => 'test',
        'mode' => 'sandbox',
        'currencies' => ['USD', 'EUR'],
    ]);

    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('getCurrencyDecimals');

    expect($method->invoke($driver, 'USD'))->toBe(2)
        ->and($method->invoke($driver, 'EUR'))->toBe(2)
        ->and($method->invoke($driver, 'NGN'))->toBe(2)
        ->and($method->invoke($driver, 'GBP'))->toBe(2);
});

test('paypal driver captureOrder throws verification exception on error', function (): void {
    $driver = new PayPalDriver([
        'client_id' => 'test',
        'client_secret' => 'test',
        'mode' => 'sandbox',
        'currencies' => ['USD'],
    ]);

    $client = Mockery::mock(Client::class);
    $request = Mockery::mock(RequestInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(400);

    $tokenResponse = Mockery::mock(ResponseInterface::class);
    $tokenStream = Mockery::mock(StreamInterface::class);
    $tokenStream->shouldReceive('__toString')->andReturn(json_encode([
        'access_token' => 'token',
        'expires_in' => 3600,
    ]));
    $tokenResponse->shouldReceive('getBody')->andReturn($tokenStream);

    $client->shouldReceive('request')
        ->with('POST', '/v1/oauth2/token', Mockery::any())
        ->andReturn($tokenResponse);

    $client->shouldReceive('request')
        ->with('POST', '/v2/checkout/orders/ORDER_123/capture', Mockery::any())
        ->andThrow(new ClientException('Error', $request, $response));

    $driver->setClient($client);

    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('captureOrder');

    $tokenResponse = Mockery::mock(ResponseInterface::class);
    $tokenStream = Mockery::mock(StreamInterface::class);
    $tokenStream->shouldReceive('__toString')->andReturn(json_encode([
        'access_token' => 'token',
        'expires_in' => 3600,
    ]));
    $tokenResponse->shouldReceive('getBody')->andReturn($tokenStream);

    $client->shouldReceive('request')
        ->with('POST', '/v1/oauth2/token', Mockery::any())
        ->andReturn($tokenResponse);

    $client->shouldReceive('request')
        ->with('POST', '/v2/checkout/orders/ORDER_123/capture', Mockery::any())
        ->andThrow(new ClientException('Error', $request, $response));

    expect(fn (): mixed => $method->invoke($driver, 'ORDER_123'))
        ->toThrow(VerificationException::class);
});

test('paypal driver verify handles capture with pending status', function (): void {
    $driver = new PayPalDriver([
        'client_id' => 'test',
        'client_secret' => 'test',
        'mode' => 'sandbox',
        'currencies' => ['USD'],
    ]);

    $client = Mockery::mock(Client::class);

    $tokenResponse = Mockery::mock(ResponseInterface::class);
    $tokenStream = Mockery::mock(StreamInterface::class);
    $tokenStream->shouldReceive('__toString')->andReturn(json_encode([
        'access_token' => 'token',
        'expires_in' => 3600,
    ]));
    $tokenResponse->shouldReceive('getBody')->andReturn($tokenStream);

    $orderResponse = Mockery::mock(ResponseInterface::class);
    $orderStream = Mockery::mock(StreamInterface::class);
    $orderStream->shouldReceive('__toString')->andReturn(json_encode([
        'id' => 'ORDER_123',
        'status' => 'APPROVED',
        'purchase_units' => [[
            'amount' => ['value' => '100.00', 'currency_code' => 'USD'],
            'payments' => [
                'captures' => [
                    [
                        'id' => 'CAPTURE_123',
                        'status' => 'PENDING',
                        'create_time' => '2024-01-01T00:00:00Z',
                    ],
                ],
            ],
        ]],
        'payer' => [
            'email_address' => 'test@example.com',
            'name' => ['given_name' => 'Test'],
        ],
    ]));
    $orderResponse->shouldReceive('getBody')->andReturn($orderStream);

    $client->shouldReceive('request')
        ->with('POST', '/v1/oauth2/token', Mockery::any())
        ->andReturn($tokenResponse);

    $client->shouldReceive('request')
        ->with('GET', '/v2/checkout/orders/ORDER_123', Mockery::any())
        ->andReturn($orderResponse);

    $driver->setClient($client);

    $result = $driver->verify('ORDER_123');

    expect($result->isPending())->toBeTrue();
});

test('paypal driver verify handles capture with completed status', function (): void {
    $driver = new PayPalDriver([
        'client_id' => 'test',
        'client_secret' => 'test',
        'mode' => 'sandbox',
        'currencies' => ['USD'],
    ]);

    $client = Mockery::mock(Client::class);

    $tokenResponse = Mockery::mock(ResponseInterface::class);
    $tokenStream = Mockery::mock(StreamInterface::class);
    $tokenStream->shouldReceive('__toString')->andReturn(json_encode([
        'access_token' => 'token',
        'expires_in' => 3600,
    ]));
    $tokenResponse->shouldReceive('getBody')->andReturn($tokenStream);

    $orderResponse = Mockery::mock(ResponseInterface::class);
    $orderStream = Mockery::mock(StreamInterface::class);
    $orderStream->shouldReceive('__toString')->andReturn(json_encode([
        'id' => 'ORDER_123',
        'status' => 'APPROVED',
        'purchase_units' => [[
            'amount' => ['value' => '100.00', 'currency_code' => 'USD'],
            'payments' => [
                'captures' => [
                    [
                        'id' => 'CAPTURE_123',
                        'status' => 'COMPLETED',
                        'create_time' => '2024-01-01T00:00:00Z',
                    ],
                ],
            ],
        ]],
        'payer' => [
            'email_address' => 'test@example.com',
            'name' => ['given_name' => 'Test'],
        ],
    ]));
    $orderResponse->shouldReceive('getBody')->andReturn($orderStream);

    $client->shouldReceive('request')
        ->with('POST', '/v1/oauth2/token', Mockery::any())
        ->andReturn($tokenResponse);

    $client->shouldReceive('request')
        ->with('GET', '/v2/checkout/orders/ORDER_123', Mockery::any())
        ->andReturn($orderResponse);

    $driver->setClient($client);

    $result = $driver->verify('ORDER_123');

    expect($result->isSuccessful())->toBeTrue();
});
