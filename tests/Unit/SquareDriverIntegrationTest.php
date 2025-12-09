<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\SquareDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

function createSquareDriverWithMock(array $responses): SquareDriver
{
    $config = [
        'access_token' => 'EAAAxxx',
        'location_id' => 'location_xxx',
        'base_url' => 'https://connect.squareup.com',
        'currencies' => ['USD', 'CAD', 'GBP', 'AUD'],
    ];

    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);
    $client = new Client(['handler' => $handlerStack]);

    $driver = new class($config) extends SquareDriver
    {
        public function setClient(Client $client): void
        {
            $this->client = $client;
        }
    };

    $driver->setClient($client);

    return $driver;
}

test('square charge succeeds with valid response', function () {
    $driver = createSquareDriverWithMock([
        new Response(200, [], json_encode([
            'payment_link' => [
                'id' => 'payment_link_123',
                'url' => 'https://square.link/checkout/abc123',
                'order_id' => 'order_456',
            ],
        ])),
    ]);

    $request = new ChargeRequestDTO(10000, 'USD', 'test@example.com', 'SQUARE_1234567890_abc123', 'https://example.com/callback');
    $response = $driver->charge($request);

    expect($response->reference)->toBe('SQUARE_1234567890_abc123')
        ->and($response->authorizationUrl)->toBe('https://square.link/checkout/abc123')
        ->and($response->accessCode)->toBe('payment_link_123')
        ->and($response->status)->toBe('pending')
        ->and($response->metadata)->toHaveKeys(['payment_link_id', 'order_id']);
});

test('square charge generates reference when not provided', function () {
    $driver = createSquareDriverWithMock([
        new Response(200, [], json_encode([
            'payment_link' => [
                'id' => 'payment_link_123',
                'url' => 'https://square.link/checkout/abc123',
                'order_id' => 'order_456',
            ],
        ])),
    ]);

    $request = new ChargeRequestDTO(10000, 'USD', 'test@example.com', null, 'https://example.com/callback');
    $response = $driver->charge($request);

    expect($response->reference)->toStartWith('SQUARE_')
        ->and($response->authorizationUrl)->toBe('https://square.link/checkout/abc123');
});

test('square charge includes metadata in payload', function () {
    $driver = createSquareDriverWithMock([
        new Response(200, [], json_encode([
            'payment_link' => [
                'id' => 'payment_link_123',
                'url' => 'https://square.link/checkout/abc123',
                'order_id' => 'order_456',
            ],
        ])),
    ]);

    $request = new ChargeRequestDTO(50000, 'USD', 'test@example.com', null, 'https://example.com/callback', ['order_id' => 12345]);
    $response = $driver->charge($request);

    expect($response->metadata)->toHaveKey('order_id');
});

test('square charge throws exception on api error', function () {
    $driver = createSquareDriverWithMock([
        new Response(400, [], json_encode([
            'errors' => [
                ['message' => 'Invalid amount'],
            ],
        ])),
    ]);

    $request = new ChargeRequestDTO(10000, 'USD', 'test@example.com', null, 'https://example.com/callback');

    $driver->charge($request);
})->throws(ChargeException::class);

test('square charge throws exception when payment_link missing', function () {
    $driver = createSquareDriverWithMock([
        new Response(200, [], json_encode([
            'data' => [],
        ])),
    ]);

    $request = new ChargeRequestDTO(10000, 'USD', 'test@example.com', null, 'https://example.com/callback');

    $driver->charge($request);
})->throws(ChargeException::class, 'Failed to create Square payment link');

test('square charge handles network error', function () {
    $mock = new MockHandler([
        new ConnectException('Timeout', new Request('POST', '/v2/online-checkout/payment-links')),
    ]);

    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $driver = new class(['access_token' => 'test', 'location_id' => 'test', 'currencies' => ['USD']]) extends SquareDriver
    {
        public function setClient(Client $client): void
        {
            $this->client = $client;
        }
    };
    $driver->setClient($client);

    $request = new ChargeRequestDTO(10000, 'USD', 'test@example.com', null, 'https://example.com/callback');

    $driver->charge($request);
})->throws(ChargeException::class);

test('square verify by payment ID returns success', function () {
    $driver = createSquareDriverWithMock([
        new Response(200, [], json_encode([
            'payment' => [
                'id' => 'payment_123',
                'reference_id' => 'SQUARE_1234567890_abc123',
                'status' => 'COMPLETED',
                'amount_money' => [
                    'amount' => 10000,
                    'currency' => 'USD',
                ],
                'source_type' => 'CARD',
                'updated_at' => '2024-01-01T12:00:00Z',
                'card_details' => [
                    'card' => [
                        'card_brand' => 'VISA',
                    ],
                ],
                'buyer_email_address' => 'test@example.com',
            ],
        ])),
    ]);

    $result = $driver->verify('payment_123');

    expect($result->status)->toBe('success')
        ->and($result->amount)->toBe(100.0)
        ->and($result->isSuccessful())->toBeTrue()
        ->and($result->reference)->toBe('SQUARE_1234567890_abc123');
});

test('square verify by reference_id searches orders', function () {
    $driver = createSquareDriverWithMock([
        // First call: payment ID lookup fails with 404 (not a payment ID, reference doesn't match pattern)
        // Since reference doesn't start with 'payment_' and isn't 32 chars, skip payment ID lookup
        // Second call: order search
        new Response(200, [], json_encode([
            'orders' => [
                [
                    'id' => 'order_456',
                    'reference_id' => 'SQUARE_1234567890_abc123',
                ],
            ],
        ])),
        // Third call: get order details
        new Response(200, [], json_encode([
            'order' => [
                'id' => 'order_456',
                'tenders' => [
                    [
                        'payment_id' => 'payment_789',
                    ],
                ],
            ],
        ])),
        // Fourth call: get payment details
        new Response(200, [], json_encode([
            'payment' => [
                'id' => 'payment_789',
                'reference_id' => 'SQUARE_1234567890_abc123',
                'status' => 'COMPLETED',
                'amount_money' => [
                    'amount' => 10000,
                    'currency' => 'USD',
                ],
                'source_type' => 'CARD',
                'updated_at' => '2024-01-01T12:00:00Z',
            ],
        ])),
    ]);

    $result = $driver->verify('SQUARE_1234567890_abc123');

    expect($result->status)->toBe('success')
        ->and($result->reference)->toBe('SQUARE_1234567890_abc123');
});

test('square verify returns failed status', function () {
    $driver = createSquareDriverWithMock([
        new Response(200, [], json_encode([
            'payment' => [
                'id' => 'payment_123',
                'reference_id' => 'SQUARE_123',
                'status' => 'FAILED',
                'amount_money' => [
                    'amount' => 10000,
                    'currency' => 'USD',
                ],
                'source_type' => 'CARD',
            ],
        ])),
    ]);

    $result = $driver->verify('payment_123');
    expect($result->isFailed())->toBeTrue();
});

test('square verify handles payment not found', function () {
    $driver = createSquareDriverWithMock([
        new Response(404, [], json_encode(['errors' => [['message' => 'Not found']]])),
        new Response(200, [], json_encode(['orders' => []])),
    ]);

    $driver->verify('SQUARE_nonexistent');
})->throws(VerificationException::class);

test('square verify handles order without payment', function () {
    $driver = createSquareDriverWithMock([
        // Order search succeeds
        new Response(200, [], json_encode([
            'orders' => [
                [
                    'id' => 'order_456',
                    'reference_id' => 'SQUARE_123',
                ],
            ],
        ])),
        // Order details - no tenders (empty array)
        new Response(200, [], json_encode([
            'order' => [
                'id' => 'order_456',
                'tenders' => [],
            ],
        ])),
    ]);

    $driver->verify('SQUARE_123');
})->throws(VerificationException::class, 'No payment found for order');

test('square verify converts cents to dollars', function () {
    $driver = createSquareDriverWithMock([
        new Response(200, [], json_encode([
            'payment' => [
                'id' => 'payment_123',
                'reference_id' => 'SQUARE_123',
                'status' => 'COMPLETED',
                'amount_money' => [
                    'amount' => 505050,
                    'currency' => 'USD',
                ],
                'source_type' => 'CARD',
                'updated_at' => '2024-01-01T12:00:00Z',
            ],
        ])),
    ]);

    $result = $driver->verify('payment_123');
    expect($result->amount)->toBe(5050.50);
});

test('square verify includes customer email', function () {
    $driver = createSquareDriverWithMock([
        new Response(200, [], json_encode([
            'payment' => [
                'id' => 'payment_123',
                'reference_id' => 'SQUARE_123',
                'status' => 'COMPLETED',
                'amount_money' => [
                    'amount' => 10000,
                    'currency' => 'USD',
                ],
                'source_type' => 'CARD',
                'updated_at' => '2024-01-01T12:00:00Z',
                'buyer_email_address' => 'customer@example.com',
            ],
        ])),
    ]);

    $result = $driver->verify('payment_123');
    expect($result->customer['email'])->toBe('customer@example.com');
});

test('square verify includes card brand when available', function () {
    $driver = createSquareDriverWithMock([
        new Response(200, [], json_encode([
            'payment' => [
                'id' => 'payment_123',
                'reference_id' => 'SQUARE_123',
                'status' => 'COMPLETED',
                'amount_money' => [
                    'amount' => 10000,
                    'currency' => 'USD',
                ],
                'source_type' => 'CARD',
                'updated_at' => '2024-01-01T12:00:00Z',
                'card_details' => [
                    'card' => [
                        'card_brand' => 'MASTERCARD',
                    ],
                ],
            ],
        ])),
    ]);

    $result = $driver->verify('payment_123');
    expect($result->cardType)->toBe('MASTERCARD');
});

