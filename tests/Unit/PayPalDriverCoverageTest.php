<?php

use GuzzleHttp\Exception\GuzzleException;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

test('paypal driver getCurrencyDecimals returns 0 for zero-decimal currencies', function () {
    $driver = new PayPalDriver([
        'client_id' => 'test',
        'client_secret' => 'test',
        'mode' => 'sandbox',
        'currencies' => ['USD', 'JPY'],
    ]);
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('getCurrencyDecimals');
    $method->setAccessible(true);
    
    expect($method->invoke($driver, 'JPY'))->toBe(0)
        ->and($method->invoke($driver, 'KRW'))->toBe(0)
        ->and($method->invoke($driver, 'CLP'))->toBe(0)
        ->and($method->invoke($driver, 'BIF'))->toBe(0);
});

test('paypal driver getCurrencyDecimals returns 2 for standard currencies', function () {
    $driver = new PayPalDriver([
        'client_id' => 'test',
        'client_secret' => 'test',
        'mode' => 'sandbox',
        'currencies' => ['USD', 'EUR'],
    ]);
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('getCurrencyDecimals');
    $method->setAccessible(true);
    
    expect($method->invoke($driver, 'USD'))->toBe(2)
        ->and($method->invoke($driver, 'EUR'))->toBe(2)
        ->and($method->invoke($driver, 'NGN'))->toBe(2)
        ->and($method->invoke($driver, 'GBP'))->toBe(2);
});

test('paypal driver captureOrder throws verification exception on error', function () {
    $driver = new PayPalDriver([
        'client_id' => 'test',
        'client_secret' => 'test',
        'mode' => 'sandbox',
        'currencies' => ['USD'],
    ]);
    
    $client = Mockery::mock(\GuzzleHttp\Client::class);
    $request = Mockery::mock(\Psr\Http\Message\RequestInterface::class);
    $response = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(400);
    
    // Mock getAccessToken first (it's called by captureOrder)
    $tokenResponse = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
    $tokenStream = Mockery::mock(\Psr\Http\Message\StreamInterface::class);
    $tokenStream->shouldReceive('__toString')->andReturn(json_encode([
        'access_token' => 'token',
        'expires_in' => 3600,
    ]));
    $tokenResponse->shouldReceive('getBody')->andReturn($tokenStream);
    
    $client->shouldReceive('request')
        ->with('POST', '/v1/oauth2/token', Mockery::any())
        ->andReturn($tokenResponse);
    
    // Then mock the capture request to throw exception
    $client->shouldReceive('request')
        ->with('POST', '/v2/checkout/orders/ORDER_123/capture', Mockery::any())
        ->andThrow(new \GuzzleHttp\Exception\ClientException('Error', $request, $response));
    
    $driver->setClient($client);
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('captureOrder');
    $method->setAccessible(true);
    
    // getAccessToken() will throw ChargeException (wrapping ClientException)
    // which will be caught and rethrown as VerificationException by captureOrder
    // But if getAccessToken fails, it throws ChargeException first
    // Let's check what actually happens - captureOrder calls getAccessToken first
    // If getAccessToken throws ChargeException, it won't reach the capture request
    // So we need to mock getAccessToken to succeed, then capture to fail
    $tokenResponse = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
    $tokenStream = Mockery::mock(\Psr\Http\Message\StreamInterface::class);
    $tokenStream->shouldReceive('__toString')->andReturn(json_encode([
        'access_token' => 'token',
        'expires_in' => 3600,
    ]));
    $tokenResponse->shouldReceive('getBody')->andReturn($tokenStream);
    
    $client->shouldReceive('request')
        ->with('POST', '/v1/oauth2/token', Mockery::any())
        ->andReturn($tokenResponse);
    
    // Now the capture request should throw
    $client->shouldReceive('request')
        ->with('POST', '/v2/checkout/orders/ORDER_123/capture', Mockery::any())
        ->andThrow(new \GuzzleHttp\Exception\ClientException('Error', $request, $response));
    
    expect(fn() => $method->invoke($driver, 'ORDER_123'))
        ->toThrow(VerificationException::class);
});

test('paypal driver verify handles capture with pending status', function () {
    $driver = new PayPalDriver([
        'client_id' => 'test',
        'client_secret' => 'test',
        'mode' => 'sandbox',
        'currencies' => ['USD'],
    ]);
    
    $client = Mockery::mock(\GuzzleHttp\Client::class);
    
    // Mock getAccessToken response
    $tokenResponse = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
    $tokenStream = Mockery::mock(\Psr\Http\Message\StreamInterface::class);
    $tokenStream->shouldReceive('__toString')->andReturn(json_encode([
        'access_token' => 'token',
        'expires_in' => 3600,
    ]));
    $tokenResponse->shouldReceive('getBody')->andReturn($tokenStream);
    
    // Mock order retrieval response
    $orderResponse = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
    $orderStream = Mockery::mock(\Psr\Http\Message\StreamInterface::class);
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

test('paypal driver verify handles capture with completed status', function () {
    $driver = new PayPalDriver([
        'client_id' => 'test',
        'client_secret' => 'test',
        'mode' => 'sandbox',
        'currencies' => ['USD'],
    ]);
    
    $client = Mockery::mock(\GuzzleHttp\Client::class);
    
    // Mock getAccessToken response
    $tokenResponse = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
    $tokenStream = Mockery::mock(\Psr\Http\Message\StreamInterface::class);
    $tokenStream->shouldReceive('__toString')->andReturn(json_encode([
        'access_token' => 'token',
        'expires_in' => 3600,
    ]));
    $tokenResponse->shouldReceive('getBody')->andReturn($tokenStream);
    
    // Mock order retrieval response
    $orderResponse = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
    $orderStream = Mockery::mock(\Psr\Http\Message\StreamInterface::class);
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
