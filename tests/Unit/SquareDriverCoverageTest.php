<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\Drivers\SquareDriver;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;

test('square driver getIdempotencyHeader returns correct header', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('getIdempotencyHeader');
    $method->setAccessible(true);
    
    $result = $method->invoke($driver, 'test_key');
    
    expect($result)->toBe(['Idempotency-Key' => 'test_key']);
});

test('square driver getDefaultHeaders includes Square-Version', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('getDefaultHeaders');
    $method->setAccessible(true);
    
    $result = $method->invoke($driver);
    
    expect($result)->toHaveKeys(['Authorization', 'Content-Type', 'Square-Version'])
        ->and($result['Square-Version'])->toBe('2024-01-18')
        ->and($result['Authorization'])->toBe('Bearer EAAAxxx');
});

test('square driver healthCheck returns true for 2xx responses', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $client = Mockery::mock(Client::class);
    $response = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);
    
    $client->shouldReceive('request')
        ->once()
        ->andReturn($response);
    
    $driver->setClient($client);
    
    expect($driver->healthCheck())->toBeTrue();
});

test('square driver healthCheck returns true for 4xx errors', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $client = Mockery::mock(Client::class);
    $response = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(404);
    
    $client->shouldReceive('request')
        ->once()
        ->andThrow(new ClientException('Not Found', new Request('GET', '/v2/locations'), $response));
    
    $driver->setClient($client);
    
    expect($driver->healthCheck())->toBeTrue();
});

test('square driver healthCheck returns false for network errors', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('request')
        ->once()
        ->andThrow(new ConnectException('Connection timeout', new Request('GET', '/v2/locations')));
    
    $driver->setClient($client);
    
    expect($driver->healthCheck())->toBeFalse();
});

test('square driver validateConfig requires access_token', function () {
    new SquareDriver([
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
})->throws(InvalidConfigurationException::class, 'Square access token is required');

test('square driver validateConfig requires location_id', function () {
    new SquareDriver([
        'access_token' => 'EAAAxxx',
        'currencies' => ['USD'],
    ]);
})->throws(InvalidConfigurationException::class, 'Square location ID is required');

test('square driver extractWebhookReference extracts from payment object', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $payload = [
        'data' => [
            'object' => [
                'payment' => [
                    'reference_id' => 'SQUARE_1234567890_abc123',
                ],
            ],
        ],
    ];
    
    $result = $driver->extractWebhookReference($payload);
    
    expect($result)->toBe('SQUARE_1234567890_abc123');
});

test('square driver extractWebhookReference falls back to data id', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $payload = [
        'data' => [
            'id' => 'payment_123',
        ],
    ];
    
    $result = $driver->extractWebhookReference($payload);
    
    expect($result)->toBe('payment_123');
});

test('square driver extractWebhookReference returns null when not found', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $payload = ['data' => []];
    
    $result = $driver->extractWebhookReference($payload);
    
    expect($result)->toBeNull();
});

test('square driver extractWebhookStatus extracts from payment object', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $payload = [
        'data' => [
            'object' => [
                'payment' => [
                    'status' => 'COMPLETED',
                ],
            ],
        ],
    ];
    
    $result = $driver->extractWebhookStatus($payload);
    
    expect($result)->toBe('COMPLETED');
});

test('square driver extractWebhookStatus falls back to type', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $payload = [
        'type' => 'payment.created',
    ];
    
    $result = $driver->extractWebhookStatus($payload);
    
    expect($result)->toBe('payment.created');
});

test('square driver extractWebhookStatus returns unknown when not found', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $payload = [];
    
    $result = $driver->extractWebhookStatus($payload);
    
    expect($result)->toBe('unknown');
});

test('square driver extractWebhookChannel extracts source_type', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $payload = [
        'data' => [
            'object' => [
                'payment' => [
                    'source_type' => 'CARD',
                ],
            ],
        ],
    ];
    
    $result = $driver->extractWebhookChannel($payload);
    
    expect($result)->toBe('CARD');
});

test('square driver extractWebhookChannel defaults to card', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $payload = ['data' => ['object' => []]];
    
    $result = $driver->extractWebhookChannel($payload);
    
    expect($result)->toBe('card');
});

test('square driver resolveVerificationId returns providerId', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $result = $driver->resolveVerificationId('SQUARE_123', 'payment_abc123');
    
    expect($result)->toBe('payment_abc123');
});

test('square driver validateWebhook returns false when signature missing', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'webhook_signature_key' => 'test_key',
        'currencies' => ['USD'],
    ]);
    
    $result = $driver->validateWebhook([], 'test body');
    
    expect($result)->toBeFalse();
});

test('square driver validateWebhook returns false when signature key missing', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $result = $driver->validateWebhook(['x-square-signature' => ['signature']], 'test body');
    
    expect($result)->toBeFalse();
});

test('square driver validateWebhook validates correct signature', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'webhook_signature_key' => 'test_secret_key',
        'currencies' => ['USD'],
    ]);
    
    $body = '{"test": "data"}';
    $expectedSignature = base64_encode(hash_hmac('sha256', $body, 'test_secret_key', true));
    
    $result = $driver->validateWebhook(['x-square-signature' => [$expectedSignature]], $body);
    
    expect($result)->toBeTrue();
});

test('square driver validateWebhook rejects invalid signature', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'webhook_signature_key' => 'test_secret_key',
        'currencies' => ['USD'],
    ]);
    
    $body = '{"test": "data"}';
    $invalidSignature = 'invalid_signature';
    
    $result = $driver->validateWebhook(['x-square-signature' => [$invalidSignature]], $body);
    
    expect($result)->toBeFalse();
});

test('square driver validateWebhook handles case-insensitive header', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'webhook_signature_key' => 'test_secret_key',
        'currencies' => ['USD'],
    ]);
    
    $body = '{"test": "data"}';
    $expectedSignature = base64_encode(hash_hmac('sha256', $body, 'test_secret_key', true));
    
    $result = $driver->validateWebhook(['X-Square-Signature' => [$expectedSignature]], $body);
    
    expect($result)->toBeTrue();
});

test('square driver mapFromPayment maps COMPLETED to success', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPayment');
    $method->setAccessible(true);
    
    $payment = [
        'id' => 'payment_123',
        'reference_id' => 'SQUARE_123',
        'status' => 'COMPLETED',
        'amount_money' => [
            'amount' => 10000,
            'currency' => 'USD',
        ],
        'source_type' => 'CARD',
        'updated_at' => '2024-01-01T12:00:00Z',
    ];
    
    $result = $method->invoke($driver, $payment, 'SQUARE_123');
    
    expect($result->status)->toBe('success')
        ->and($result->amount)->toBe(100.0)
        ->and($result->paidAt)->not->toBeNull();
});

test('square driver mapFromPayment maps APPROVED to success', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPayment');
    $method->setAccessible(true);
    
    $payment = [
        'id' => 'payment_123',
        'reference_id' => 'SQUARE_123',
        'status' => 'APPROVED',
        'amount_money' => [
            'amount' => 5000,
            'currency' => 'USD',
        ],
        'source_type' => 'CARD',
        'updated_at' => '2024-01-01T12:00:00Z',
    ];
    
    $result = $method->invoke($driver, $payment, 'SQUARE_123');
    
    expect($result->status)->toBe('success');
});

test('square driver mapFromPayment maps FAILED to failed', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPayment');
    $method->setAccessible(true);
    
    $payment = [
        'id' => 'payment_123',
        'reference_id' => 'SQUARE_123',
        'status' => 'FAILED',
        'amount_money' => [
            'amount' => 10000,
            'currency' => 'USD',
        ],
        'source_type' => 'CARD',
    ];
    
    $result = $method->invoke($driver, $payment, 'SQUARE_123');
    
    expect($result->status)->toBe('failed')
        ->and($result->paidAt)->toBeNull();
});

test('square driver mapFromPayment maps CANCELED to failed', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPayment');
    $method->setAccessible(true);
    
    $payment = [
        'id' => 'payment_123',
        'reference_id' => 'SQUARE_123',
        'status' => 'CANCELED',
        'amount_money' => [
            'amount' => 10000,
            'currency' => 'USD',
        ],
        'source_type' => 'CARD',
    ];
    
    $result = $method->invoke($driver, $payment, 'SQUARE_123');
    
    expect($result->status)->toBe('failed');
});

test('square driver mapFromPayment maps unknown status to pending', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPayment');
    $method->setAccessible(true);
    
    $payment = [
        'id' => 'payment_123',
        'reference_id' => 'SQUARE_123',
        'status' => 'PENDING',
        'amount_money' => [
            'amount' => 10000,
            'currency' => 'USD',
        ],
        'source_type' => 'CARD',
    ];
    
    $result = $method->invoke($driver, $payment, 'SQUARE_123');
    
    expect($result->status)->toBe('pending');
});

test('square driver mapFromPayment includes metadata', function () {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
    
    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPayment');
    $method->setAccessible(true);
    
    $payment = [
        'id' => 'payment_123',
        'order_id' => 'order_456',
        'reference_id' => 'SQUARE_123',
        'status' => 'COMPLETED',
        'amount_money' => [
            'amount' => 10000,
            'currency' => 'USD',
        ],
        'source_type' => 'CARD',
    ];
    
    $result = $method->invoke($driver, $payment, 'SQUARE_123');
    
    expect($result->metadata)->toHaveKeys(['payment_id', 'order_id'])
        ->and($result->metadata['payment_id'])->toBe('payment_123')
        ->and($result->metadata['order_id'])->toBe('order_456');
});

