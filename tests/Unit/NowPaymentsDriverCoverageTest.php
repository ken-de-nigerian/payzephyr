<?php

use KenDeNigerian\PayZephyr\Drivers\NowPaymentsDriver;

test('nowpayments driver getIdempotencyHeader returns correct header', function () {
    $driver = new NowPaymentsDriver([
        'api_key' => 'test_api_key',
        'ipn_secret' => 'test_ipn_secret',
        'currencies' => ['USD'],
    ]);

    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('getIdempotencyHeader');
    $method->setAccessible(true);

    $result = $method->invoke($driver, 'test_key');

    expect($result)->toBe(['Idempotency-Key' => 'test_key']);
});

test('nowpayments driver healthCheck returns true for 2xx responses', function () {
    $driver = new NowPaymentsDriver([
        'api_key' => 'test_api_key',
        'ipn_secret' => 'test_ipn_secret',
        'currencies' => ['USD'],
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

test('nowpayments driver healthCheck returns true for 4xx errors', function () {
    $driver = new NowPaymentsDriver([
        'api_key' => 'test_api_key',
        'ipn_secret' => 'test_ipn_secret',
        'currencies' => ['USD'],
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

test('nowpayments driver healthCheck returns false for 5xx errors', function () {
    $driver = new NowPaymentsDriver([
        'api_key' => 'test_api_key',
        'ipn_secret' => 'test_ipn_secret',
        'currencies' => ['USD'],
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

test('nowpayments driver healthCheck returns false for network errors', function () {
    $driver = new NowPaymentsDriver([
        'api_key' => 'test_api_key',
        'ipn_secret' => 'test_ipn_secret',
        'currencies' => ['USD'],
    ]);

    $client = Mockery::mock(\GuzzleHttp\Client::class);
    $client->shouldReceive('request')
        ->once()
        ->andThrow(new \GuzzleHttp\Exception\ConnectException('Connection timeout', Mockery::mock(\Psr\Http\Message\RequestInterface::class)));

    $driver->setClient($client);

    expect($driver->healthCheck())->toBeFalse();
});

test('nowpayments driver healthCheck handles ClientException with status code', function () {
    $driver = new NowPaymentsDriver([
        'api_key' => 'test_api_key',
        'ipn_secret' => 'test_ipn_secret',
        'currencies' => ['USD'],
    ]);

    $client = Mockery::mock(\GuzzleHttp\Client::class);
    $response = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(400);

    $client->shouldReceive('request')
        ->once()
        ->andThrow(new \GuzzleHttp\Exception\ClientException('Bad Request', Mockery::mock(\Psr\Http\Message\RequestInterface::class), $response));

    $driver->setClient($client);

    expect($driver->healthCheck())->toBeTrue();
});

test('nowpayments driver healthCheck handles ClientException with 401 status code', function () {
    $driver = new NowPaymentsDriver([
        'api_key' => 'test_api_key',
        'ipn_secret' => 'test_ipn_secret',
        'currencies' => ['USD'],
    ]);

    $client = Mockery::mock(\GuzzleHttp\Client::class);
    $response = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(401);

    $client->shouldReceive('request')
        ->once()
        ->andThrow(new \GuzzleHttp\Exception\ClientException('Unauthorized', Mockery::mock(\Psr\Http\Message\RequestInterface::class), $response));

    $driver->setClient($client);

    expect($driver->healthCheck())->toBeTrue();
});

test('nowpayments driver validateWebhook returns false when signature missing', function () {
    $driver = new NowPaymentsDriver([
        'api_key' => 'test_api_key',
        'ipn_secret' => 'test_ipn_secret',
        'currencies' => ['USD'],
    ]);

    $result = $driver->validateWebhook([], 'test body');

    expect($result)->toBeFalse();
});

test('nowpayments driver validateWebhook returns false when ipn_secret missing', function () {
    $driver = new NowPaymentsDriver([
        'api_key' => 'test_api_key',
        'ipn_secret' => '',
        'currencies' => ['USD'],
    ]);

    $result = $driver->validateWebhook(['x-nowpayments-sig' => ['signature']], 'test body');

    expect($result)->toBeFalse();
});

test('nowpayments driver validateWebhook handles case-insensitive header', function () {
    $driver = new NowPaymentsDriver([
        'api_key' => 'test_api_key',
        'ipn_secret' => 'test_ipn_secret',
        'currencies' => ['USD'],
    ]);

    $body = '{"payment_id": 12345678, "payment_status": "finished"}';
    $signature = hash_hmac('sha512', $body, 'test_ipn_secret');

    $result = $driver->validateWebhook(['X-Nowpayments-Sig' => [$signature]], $body);

    expect($result)->toBeTrue();
});

test('nowpayments driver validateWebhook validates signature correctly', function () {
    $driver = new NowPaymentsDriver([
        'api_key' => 'test_api_key',
        'ipn_secret' => 'test_ipn_secret',
        'currencies' => ['USD'],
    ]);

    $body = '{"payment_id": 12345678, "payment_status": "finished"}';
    $signature = hash_hmac('sha512', $body, 'test_ipn_secret');

    $result = $driver->validateWebhook(['x-nowpayments-sig' => [$signature]], $body);

    expect($result)->toBeTrue();
});

test('nowpayments driver validateWebhook rejects invalid signature', function () {
    $driver = new NowPaymentsDriver([
        'api_key' => 'test_api_key',
        'ipn_secret' => 'test_ipn_secret',
        'currencies' => ['USD'],
    ]);

    $body = '{"payment_id": 12345678, "payment_status": "finished"}';
    $invalidSignature = 'invalid_signature';

    $result = $driver->validateWebhook(['x-nowpayments-sig' => [$invalidSignature]], $body);

    expect($result)->toBeFalse();
});

test('nowpayments driver extractWebhookReference returns order_id', function () {
    $driver = new NowPaymentsDriver([
        'api_key' => 'test_api_key',
        'ipn_secret' => 'test_ipn_secret',
        'currencies' => ['USD'],
    ]);

    $payload = [
        'order_id' => 'NOW_1234567890_abc123def456',
        'payment_id' => 12345678,
    ];

    $result = $driver->extractWebhookReference($payload);

    expect($result)->toBe('NOW_1234567890_abc123def456');
});

test('nowpayments driver extractWebhookReference falls back to payment_id', function () {
    $driver = new NowPaymentsDriver([
        'api_key' => 'test_api_key',
        'ipn_secret' => 'test_ipn_secret',
        'currencies' => ['USD'],
    ]);

    $payload = [
        'payment_id' => 12345678,
    ];

    $result = $driver->extractWebhookReference($payload);

    expect($result)->toBe('12345678'); // payment_id is converted to string
});

test('nowpayments driver extractWebhookStatus returns payment_status', function () {
    $driver = new NowPaymentsDriver([
        'api_key' => 'test_api_key',
        'ipn_secret' => 'test_ipn_secret',
        'currencies' => ['USD'],
    ]);

    $payload = [
        'payment_status' => 'finished',
    ];

    $result = $driver->extractWebhookStatus($payload);

    expect($result)->toBe('finished');
});

test('nowpayments driver extractWebhookStatus returns unknown when missing', function () {
    $driver = new NowPaymentsDriver([
        'api_key' => 'test_api_key',
        'ipn_secret' => 'test_ipn_secret',
        'currencies' => ['USD'],
    ]);

    $payload = [];

    $result = $driver->extractWebhookStatus($payload);

    expect($result)->toBe('unknown');
});

test('nowpayments driver extractWebhookChannel returns pay_currency', function () {
    $driver = new NowPaymentsDriver([
        'api_key' => 'test_api_key',
        'ipn_secret' => 'test_ipn_secret',
        'currencies' => ['USD'],
    ]);

    $payload = [
        'pay_currency' => 'btc',
    ];

    $result = $driver->extractWebhookChannel($payload);

    expect($result)->toBe('btc');
});

test('nowpayments driver resolveVerificationId returns providerId when available', function () {
    $driver = new NowPaymentsDriver([
        'api_key' => 'test_api_key',
        'ipn_secret' => 'test_ipn_secret',
        'currencies' => ['USD'],
    ]);

    $result = $driver->resolveVerificationId('NOW_1234567890_abc123def456', '12345678');

    expect($result)->toBe('12345678');
});

test('nowpayments driver resolveVerificationId returns reference when providerId empty', function () {
    $driver = new NowPaymentsDriver([
        'api_key' => 'test_api_key',
        'ipn_secret' => 'test_ipn_secret',
        'currencies' => ['USD'],
    ]);

    $result = $driver->resolveVerificationId('NOW_1234567890_abc123def456', '');

    expect($result)->toBe('NOW_1234567890_abc123def456');
});
