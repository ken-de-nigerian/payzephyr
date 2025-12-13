<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\Drivers\MollieDriver;

beforeEach(function () {
    $this->config = [
        'api_key' => 'test_mollie_api_key',
        'base_url' => 'https://api.mollie.com',
        'currencies' => ['EUR', 'USD', 'GBP'],
    ];
});

test('mollie driver getIdempotencyHeader returns correct header', function () {
    $driver = new MollieDriver($this->config);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('getIdempotencyHeader');

    $result = $method->invoke($driver, 'test_key');

    expect($result)->toBe(['Idempotency-Key' => 'test_key']);
});

test('mollie driver getDefaultHeaders includes authorization', function () {
    $driver = new MollieDriver($this->config);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('getDefaultHeaders');

    $headers = $method->invoke($driver);

    expect($headers)->toHaveKey('Authorization')
        ->and($headers['Authorization'])->toBe('Bearer test_mollie_api_key')
        ->and($headers)->toHaveKey('Content-Type')
        ->and($headers['Content-Type'])->toBe('application/json');
});

test('mollie driver getWebhookUrl returns configured url', function () {
    $config = array_merge($this->config, [
        'webhook_url' => 'https://example.com',
    ]);
    $driver = new MollieDriver($config);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('getWebhookUrl');

    $url = $method->invoke($driver);

    expect($url)->toBe('https://example.com/payments/webhook/mollie');
});

test('mollie driver getWebhookUrl returns null when not configured', function () {
    config(['app.url' => null]);
    $driver = new MollieDriver($this->config);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('getWebhookUrl');

    $url = $method->invoke($driver);

    expect($url)->toBeNull();
});

test('mollie driver verify handles all payment statuses correctly', function () {
    $statuses = [
        ['status' => 'paid', 'expected' => 'success'],
        ['status' => 'authorized', 'expected' => 'success'],
        ['status' => 'failed', 'expected' => 'failed'],
        ['status' => 'canceled', 'expected' => 'failed'],
        ['status' => 'expired', 'expected' => 'failed'],
        ['status' => 'open', 'expected' => 'pending'],
        ['status' => 'pending', 'expected' => 'pending'],
    ];

    foreach ($statuses as $statusTest) {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'id' => 'tr_WDqYK6vllg',
                'status' => $statusTest['status'],
                'amount' => [
                    'value' => '10.00',
                    'currency' => 'EUR',
                ],
            ])),
        ]);

        $driver = new MollieDriver($this->config);
        $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

        $verification = $driver->verify('tr_WDqYK6vllg');

        expect($verification->status)->toBe($statusTest['expected']);
    }
});

test('mollie driver verify handles customer data correctly', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'id' => 'tr_WDqYK6vllg',
            'status' => 'paid',
            'amount' => [
                'value' => '10.00',
                'currency' => 'EUR',
            ],
            'billingAddress' => [
                'email' => 'customer@example.com',
                'givenName' => 'John',
                'familyName' => 'Doe',
            ],
            'paidAt' => '2024-01-15T10:30:00.000Z',
            'method' => 'ideal',
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $verification = $driver->verify('tr_WDqYK6vllg');

    expect($verification->customer)->toBeArray()
        ->and($verification->customer['email'])->toBe('customer@example.com')
        ->and($verification->customer['name'])->toBe('John');
});

test('mollie driver verify handles card type and bank information', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'id' => 'tr_WDqYK6vllg',
            'status' => 'paid',
            'amount' => [
                'value' => '10.00',
                'currency' => 'EUR',
            ],
            'method' => 'creditcard',
            'details' => [
                'cardLabel' => 'Visa',
                'consumerName' => 'J. Doe',
            ],
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $verification = $driver->verify('tr_WDqYK6vllg');

    expect($verification->cardType)->toBe('Visa')
        ->and($verification->bank)->toBe('J. Doe');
});

test('mollie driver webhook validation handles payment ID mismatch', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'id' => 'tr_DIFFERENT_ID',
            'status' => 'paid',
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $payload = json_encode([
        'id' => 'tr_WDqYK6vllg',
        'createdAt' => date('c'),
    ]);

    $isValid = $driver->validateWebhook([], $payload);

    expect($isValid)->toBeFalse();
});

test('mollie driver webhook validation handles 4xx errors gracefully', function () {
    $mock = new MockHandler([
        new ClientException(
            'Bad Request',
            new Request('GET', '/v2/payments/tr_test'),
            new Response(400, [], json_encode([
                'status' => 400,
                'title' => 'Bad Request',
            ]))
        ),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $payload = json_encode([
        'id' => 'tr_test',
        'createdAt' => date('c'),
    ]);

    $isValid = $driver->validateWebhook([], $payload);

    expect($isValid)->toBeFalse();
});

test('mollie driver healthCheck returns false for 5xx errors', function () {
    $mock = new MockHandler([
        new Response(500, [], json_encode([
            'status' => 500,
            'title' => 'Internal Server Error',
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    expect($driver->healthCheck())->toBeFalse();
});

test('mollie driver extractWebhookTimestamp handles various timestamp formats', function () {
    $driver = new MollieDriver($this->config);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('extractWebhookTimestamp');

    // Test ISO 8601 format
    $timestamp = time();
    $payload1 = ['createdAt' => date('c', $timestamp)];
    $result1 = $method->invoke($driver, $payload1);
    expect($result1)->toBeInt()
        ->and(abs($result1 - $timestamp))->toBeLessThan(2); // Allow 2 second difference

    // Test unix timestamp
    $payload2 = ['timestamp' => $timestamp];
    $result2 = $method->invoke($driver, $payload2);
    expect($result2)->toBe($timestamp);

    // Test missing timestamp
    $payload3 = ['id' => 'tr_test'];
    $result3 = $method->invoke($driver, $payload3);
    expect($result3)->toBeNull();
});
