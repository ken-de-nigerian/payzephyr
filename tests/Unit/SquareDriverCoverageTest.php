<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use KenDeNigerian\PayZephyr\Drivers\SquareDriver;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

test('square driver getIdempotencyHeader returns correct header', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);

    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('getIdempotencyHeader');

    $result = $method->invoke($driver, 'test_key');

    expect($result)->toBe(['Idempotency-Key' => 'test_key']);
});

test('square driver getDefaultHeaders includes Square-Version', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);

    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('getDefaultHeaders');

    $result = $method->invoke($driver);

    expect($result)->toHaveKeys(['Authorization', 'Content-Type', 'Square-Version'])
        ->and($result['Square-Version'])->toBe('2024-10-18')
        ->and($result['Authorization'])->toBe('Bearer EAAAxxx');
});

test('square driver healthCheck returns true for 2xx responses', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);

    $client = Mockery::mock(Client::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);

    $client->shouldReceive('request')
        ->once()
        ->with('GET', '/v2/locations', Mockery::any())
        ->andReturn($response);

    $driver->setClient($client);

    expect($driver->healthCheck())->toBeTrue();
});

test('square driver healthCheck returns true for 4xx errors', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);

    $client = Mockery::mock(Client::class);
    $request = Mockery::mock(RequestInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(404);

    $client->shouldReceive('request')
        ->once()
        ->with('GET', '/v2/locations', Mockery::any())
        ->andThrow(new ClientException('Not Found', $request, $response));

    $driver->setClient($client);

    expect($driver->healthCheck())->toBeTrue();
});

test('square driver healthCheck returns false for network errors', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);

    $client = Mockery::mock(Client::class);
    $request = Mockery::mock(RequestInterface::class);
    $client->shouldReceive('request')
        ->once()
        ->with('GET', '/v2/locations', Mockery::any())
        ->andThrow(new ConnectException('Connection timeout', $request));

    $driver->setClient($client);

    expect($driver->healthCheck())->toBeFalse();
});

test('square driver validateConfig requires access_token', function (): void {
    new SquareDriver([
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);
})->throws(InvalidConfigurationException::class, 'Square access token is required');

test('square driver validateConfig requires location_id', function (): void {
    new SquareDriver([
        'access_token' => 'EAAAxxx',
        'currencies' => ['USD'],
    ]);
})->throws(InvalidConfigurationException::class, 'Square location ID is required');

test('square driver extractWebhookReference extracts from payment object', function (): void {
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

test('square driver extractWebhookReference falls back to data id', function (): void {
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

test('square driver extractWebhookReference returns null when not found', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);

    $payload = ['data' => []];

    $result = $driver->extractWebhookReference($payload);

    expect($result)->toBeNull();
});

test('square driver extractWebhookStatus extracts from payment object', function (): void {
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

test('square driver extractWebhookStatus falls back to type', function (): void {
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

test('square driver extractWebhookStatus returns unknown when not found', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);

    $payload = [];

    $result = $driver->extractWebhookStatus($payload);

    expect($result)->toBe('unknown');
});

test('square driver extractWebhookChannel extracts source_type', function (): void {
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

test('square driver extractWebhookChannel returns null when source_type is absent', function (): void {
    // Previously defaulted to 'card', which recorded a card payment for
    // instruments that were never cards (wallet, bank transfer, gift card).
    // An absent source_type is an unknown channel, not a card one.
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);

    expect($driver->extractWebhookChannel(['data' => ['object' => []]]))->toBeNull();
});

test('square driver extractWebhookChannel returns the reported source_type', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);

    $payload = ['data' => ['object' => ['payment' => ['source_type' => 'BANK_ACCOUNT']]]];

    expect($driver->extractWebhookChannel($payload))->toBe('BANK_ACCOUNT');
});

test('square driver extractWebhookChannel treats an empty source_type as unknown', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);

    $payload = ['data' => ['object' => ['payment' => ['source_type' => '']]]];

    expect($driver->extractWebhookChannel($payload))->toBeNull();
});

test('square driver resolveVerificationId returns providerId', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);

    $result = $driver->resolveVerificationId('SQUARE_123', 'payment_abc123');

    expect($result)->toBe('payment_abc123');
});

test('square driver validateWebhook returns false when signature missing', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'webhook_signature_key' => 'test_key',
        'currencies' => ['USD'],
    ]);

    $result = $driver->validateWebhook([], 'test body');

    expect($result)->toBeFalse();
});

test('square driver validateWebhook returns false when signature key missing', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);

    $result = $driver->validateWebhook(['x-square-hmacsha256-signature' => ['signature']], 'test body');

    expect($result)->toBeFalse();
});

test('square driver validateWebhook validates correct signature', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'webhook_signature_key' => 'test_secret_key',
        'currencies' => ['USD'],
    ]);

    // Real Square envelopes carry a top-level 'created_at' - see ADR-0001.
    $body = json_encode(['test' => 'data', 'created_at' => now()->toIso8601String()]);
    $expectedSignature = squareWebhookSignature($body, 'test_secret_key');

    $result = $driver->validateWebhook(['x-square-hmacsha256-signature' => [$expectedSignature]], $body);

    expect($result)->toBeTrue();
});

test('square driver validateWebhook rejects invalid signature', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'webhook_signature_key' => 'test_secret_key',
        'currencies' => ['USD'],
    ]);

    $body = '{"test": "data"}';
    $invalidSignature = 'invalid_signature';

    $result = $driver->validateWebhook(['x-square-hmacsha256-signature' => [$invalidSignature]], $body);

    expect($result)->toBeFalse();
});

test('square driver validateWebhook handles case-insensitive header', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'webhook_signature_key' => 'test_secret_key',
        'currencies' => ['USD'],
    ]);

    $body = json_encode(['test' => 'data', 'created_at' => now()->toIso8601String()]);
    $expectedSignature = squareWebhookSignature($body, 'test_secret_key');

    $result = $driver->validateWebhook(['X-Square-HmacSha256-Signature' => [$expectedSignature]], $body);

    expect($result)->toBeTrue();
});

test('square driver mapFromPayment maps COMPLETED to success', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);

    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPayment');

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

test('square driver mapFromPayment maps APPROVED to success', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);

    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPayment');

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

test('square driver mapFromPayment maps FAILED to failed', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);

    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPayment');

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

test('square driver mapFromPayment maps CANCELED to failed', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);

    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPayment');

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

test('square driver mapFromPayment maps unknown status to pending', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);

    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPayment');

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

test('square driver mapFromPayment includes metadata', function (): void {
    $driver = new SquareDriver([
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'currencies' => ['USD'],
    ]);

    $reflection = new \ReflectionClass($driver);
    $method = $reflection->getMethod('mapFromPayment');

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
