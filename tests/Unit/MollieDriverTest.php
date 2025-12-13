<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\MollieDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

beforeEach(function () {
    $this->config = [
        'api_key' => 'test_mollie_api_key',
        'base_url' => 'https://api.mollie.com',
        'currencies' => ['EUR', 'USD', 'GBP'],
    ];
});

test('mollie driver initializes correctly', function () {
    $driver = new MollieDriver($this->config);

    expect($driver->getName())->toBe('mollie')
        ->and($driver->getSupportedCurrencies())->toBe(['EUR', 'USD', 'GBP'])
        ->and($driver->isCurrencySupported('EUR'))->toBeTrue()
        ->and($driver->isCurrencySupported('JPY'))->toBeFalse();
});

test('mollie driver throws exception for missing api key', function () {
    unset($this->config['api_key']);

    new MollieDriver($this->config);
})->throws(InvalidConfigurationException::class, 'Mollie API key is required');

test('mollie driver charges successfully', function () {
    $mock = new MockHandler([
        new Response(201, [], json_encode([
            'id' => 'tr_WDqYK6vllg',
            'status' => 'open',
            'amount' => [
                'value' => '10.00',
                'currency' => 'EUR',
            ],
            '_links' => [
                'checkout' => [
                    'href' => 'https://www.mollie.com/payscreen/select-method/WDqYK6vllg',
                ],
            ],
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $request = new ChargeRequestDTO(
        amount: 10.00,
        currency: 'EUR',
        email: 'test@example.com',
        callbackUrl: 'https://example.com/callback',
    );

    $response = $driver->charge($request);

    expect($response->reference)->toBeString()
        ->and($response->authorizationUrl)->toBe('https://www.mollie.com/payscreen/select-method/WDqYK6vllg')
        ->and($response->accessCode)->toBe('tr_WDqYK6vllg')
        ->and($response->status)->toBe('pending')
        ->and($response->provider)->toBe('mollie');
});

test('mollie driver verifies payment successfully', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'id' => 'tr_WDqYK6vllg',
            'status' => 'paid',
            'amount' => [
                'value' => '10.00',
                'currency' => 'EUR',
            ],
            'metadata' => [
                'reference' => 'MOLLIE_123456',
            ],
            'paidAt' => '2024-01-15T10:30:00.000Z',
            'method' => 'ideal',
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $verification = $driver->verify('tr_WDqYK6vllg');

    expect($verification->reference)->toBe('MOLLIE_123456')
        ->and($verification->status)->toBe('success')
        ->and($verification->amount)->toBe(10.00)
        ->and($verification->currency)->toBe('EUR')
        ->and($verification->channel)->toBe('ideal')
        ->and($verification->provider)->toBe('mollie');
});

test('mollie driver validates webhook by fetching payment from API', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'id' => 'tr_WDqYK6vllg',
            'status' => 'paid',
            'amount' => [
                'value' => '10.00',
                'currency' => 'EUR',
            ],
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $payload = json_encode([
        'id' => 'tr_WDqYK6vllg',
        'createdAt' => date('c'),
    ]);

    $isValid = $driver->validateWebhook([], $payload);

    expect($isValid)->toBeTrue();
});

test('mollie driver rejects webhook without payment id', function () {
    $driver = new MollieDriver($this->config);

    $payload = json_encode([
        'status' => 'paid',
    ]);

    $isValid = $driver->validateWebhook([], $payload);

    expect($isValid)->toBeFalse();
});

test('mollie driver rejects webhook when payment not found in API', function () {
    $mock = new MockHandler([
        new ClientException(
            'Not Found',
            new Request('GET', '/v2/payments/tr_invalid'),
            new Response(404, [], json_encode([
                'status' => 404,
                'title' => 'Not Found',
                'detail' => 'No payment exists with id tr_invalid',
            ]))
        ),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $payload = json_encode([
        'id' => 'tr_invalid',
        'createdAt' => date('c'),
    ]);

    $isValid = $driver->validateWebhook([], $payload);

    expect($isValid)->toBeFalse();
});

test('mollie driver webhook validation handles ClientException with response', function () {
    $mock = new MockHandler([
        new ClientException(
            'Bad Request',
            new Request('GET', '/v2/payments/tr_test'),
            new Response(400, [], json_encode([
                'status' => 400,
                'title' => 'Bad Request',
                'detail' => 'Invalid payment ID',
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

test('mollie driver health check succeeds', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'count' => 13,
            '_embedded' => [
                'methods' => [],
            ],
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    expect($driver->healthCheck())->toBeTrue();
});

test('mollie driver extracts webhook reference', function () {
    $driver = new MollieDriver($this->config);

    $payload = [
        'id' => 'tr_WDqYK6vllg',
    ];

    expect($driver->extractWebhookReference($payload))->toBe('tr_WDqYK6vllg');
});

test('mollie driver extracts webhook status', function () {
    $driver = new MollieDriver($this->config);

    $payload = [
        'status' => 'paid',
    ];

    expect($driver->extractWebhookStatus($payload))->toBe('paid');
});

test('mollie driver extracts webhook channel', function () {
    $driver = new MollieDriver($this->config);

    $payload = [
        'method' => 'ideal',
    ];

    expect($driver->extractWebhookChannel($payload))->toBe('ideal');
});

test('mollie driver resolves verification id', function () {
    $driver = new MollieDriver($this->config);

    $verificationId = $driver->resolveVerificationId('MOLLIE_123', 'tr_WDqYK6vllg');

    expect($verificationId)->toBe('tr_WDqYK6vllg');
});

test('mollie driver normalizes statuses correctly', function () {
    $driver = new MollieDriver($this->config);

    // Use reflection to test protected method
    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('normalizeStatus');

    expect($method->invoke($driver, 'paid'))->toBe('success')
        ->and($method->invoke($driver, 'authorized'))->toBe('success')
        ->and($method->invoke($driver, 'failed'))->toBe('failed')
        ->and($method->invoke($driver, 'canceled'))->toBe('failed')
        ->and($method->invoke($driver, 'expired'))->toBe('failed')
        ->and($method->invoke($driver, 'open'))->toBe('pending')
        ->and($method->invoke($driver, 'pending'))->toBe('pending');
});

test('mollie driver handles charge failure', function () {
    $mock = new MockHandler([
        new Response(422, [], json_encode([
            'status' => 422,
            'title' => 'Unprocessable Entity',
            'detail' => 'The amount is higher than the maximum',
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $request = new ChargeRequestDTO(
        amount: 10000000.00,
        currency: 'EUR',
        email: 'test@example.com',
        callbackUrl: 'https://example.com/callback',
    );

    $driver->charge($request);
})->throws(ChargeException::class);

test('mollie driver handles verification failure', function () {
    $mock = new MockHandler([
        new Response(404, [], json_encode([
            'status' => 404,
            'title' => 'Not Found',
            'detail' => 'No payment exists with id tr_invalid',
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $driver->verify('tr_invalid');
})->throws(VerificationException::class);

test('mollie driver formats amounts correctly', function () {
    $driver = new MollieDriver($this->config);

    // Use reflection to test protected method
    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('formatAmount');

    expect($method->invoke($driver, 10.00, 'EUR'))->toBe('10.00')
        ->and($method->invoke($driver, 10.5, 'EUR'))->toBe('10.50')
        ->and($method->invoke($driver, 10, 'EUR'))->toBe('10.00');
});

test('mollie driver with channels', function () {
    $mock = new MockHandler([
        new Response(201, [], json_encode([
            'id' => 'tr_WDqYK6vllg',
            'status' => 'open',
            'amount' => [
                'value' => '10.00',
                'currency' => 'EUR',
            ],
            '_links' => [
                'checkout' => [
                    'href' => 'https://www.mollie.com/payscreen/select-method/WDqYK6vllg',
                ],
            ],
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $request = new ChargeRequestDTO(
        amount: 10.00,
        currency: 'EUR',
        email: 'test@example.com',
        callbackUrl: 'https://example.com/callback',
        channels: ['card', 'bank_transfer'],
    );

    $response = $driver->charge($request);

    expect($response->reference)->toBeString()
        ->and($response->status)->toBe('pending');
});

test('mollie driver with metadata', function () {
    $mock = new MockHandler([
        new Response(201, [], json_encode([
            'id' => 'tr_WDqYK6vllg',
            'status' => 'open',
            'amount' => [
                'value' => '10.00',
                'currency' => 'EUR',
            ],
            'metadata' => [
                'order_id' => 12345,
                'reference' => 'MOLLIE_123456',
            ],
            '_links' => [
                'checkout' => [
                    'href' => 'https://www.mollie.com/payscreen/select-method/WDqYK6vllg',
                ],
            ],
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $request = new ChargeRequestDTO(
        amount: 10.00,
        currency: 'EUR',
        email: 'test@example.com',
        callbackUrl: 'https://example.com/callback',
        metadata: ['order_id' => 12345],
    );

    $response = $driver->charge($request);

    expect($response->metadata)->toHaveKey('order_id')
        ->and($response->metadata['order_id'])->toBe(12345);
});

test('mollie driver handles webhook with invalid json', function () {
    $driver = new MollieDriver($this->config);

    $payload = 'invalid json';

    $isValid = $driver->validateWebhook([], $payload);

    expect($isValid)->toBeFalse();
});

test('mollie driver handles webhook timestamp validation', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'id' => 'tr_WDqYK6vllg',
            'status' => 'paid',
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    // Test with timestamp outside tolerance (1 hour ago)
    $oldTimestamp = date('c', time() - 3600);

    $payload = json_encode([
        'id' => 'tr_WDqYK6vllg',
        'createdAt' => $oldTimestamp,
    ]);

    $isValid = $driver->validateWebhook([], $payload);

    expect($isValid)->toBeFalse();
});

test('mollie driver handles charge with custom reference', function () {
    $mock = new MockHandler([
        new Response(201, [], json_encode([
            'id' => 'tr_WDqYK6vllg',
            'status' => 'open',
            'amount' => [
                'value' => '10.00',
                'currency' => 'EUR',
            ],
            '_links' => [
                'checkout' => [
                    'href' => 'https://www.mollie.com/payscreen/select-method/WDqYK6vllg',
                ],
            ],
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $request = new ChargeRequestDTO(
        amount: 10.00,
        currency: 'EUR',
        email: 'test@example.com',
        reference: 'CUSTOM_REF_123',
        callbackUrl: 'https://example.com/callback',
    );

    $response = $driver->charge($request);

    expect($response->reference)->toBe('CUSTOM_REF_123');
});

test('mollie driver generates reference with MOLLIE prefix', function () {
    $mock = new MockHandler([
        new Response(201, [], json_encode([
            'id' => 'tr_WDqYK6vllg',
            'status' => 'open',
            'amount' => [
                'value' => '10.00',
                'currency' => 'EUR',
            ],
            '_links' => [
                'checkout' => [
                    'href' => 'https://www.mollie.com/payscreen/select-method/WDqYK6vllg',
                ],
            ],
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $request = new ChargeRequestDTO(
        amount: 10.00,
        currency: 'EUR',
        email: 'test@example.com',
        callbackUrl: 'https://example.com/callback',
    );

    $response = $driver->charge($request);

    expect($response->reference)->toStartWith('MOLLIE_');
});

test('mollie driver handles charge with idempotency key', function () {
    $mock = new MockHandler([
        new Response(201, [], json_encode([
            'id' => 'tr_WDqYK6vllg',
            'status' => 'open',
            'amount' => [
                'value' => '10.00',
                'currency' => 'EUR',
            ],
            '_links' => [
                'checkout' => [
                    'href' => 'https://www.mollie.com/payscreen/select-method/WDqYK6vllg',
                ],
            ],
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $request = new ChargeRequestDTO(
        amount: 10.00,
        currency: 'EUR',
        email: 'test@example.com',
        callbackUrl: 'https://example.com/callback',
        idempotencyKey: 'idempotent_key_123',
    );

    $response = $driver->charge($request);

    expect($response->reference)->toBeString()
        ->and($response->status)->toBe('pending');
});

test('mollie driver handles verification with missing metadata reference', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'id' => 'tr_WDqYK6vllg',
            'status' => 'paid',
            'amount' => [
                'value' => '10.00',
                'currency' => 'EUR',
            ],
            'paidAt' => '2024-01-15T10:30:00.000Z',
            'method' => 'ideal',
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $verification = $driver->verify('tr_WDqYK6vllg');

    expect($verification->reference)->toBe('tr_WDqYK6vllg')
        ->and($verification->status)->toBe('success');
});

test('mollie driver handles health check with 400 response', function () {
    $mock = new MockHandler([
        new Response(400, [], json_encode([
            'status' => 400,
            'title' => 'Bad Request',
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    expect($driver->healthCheck())->toBeTrue();
});

test('mollie driver handles health check with 404 response', function () {
    $mock = new MockHandler([
        new Response(404, [], json_encode([
            'status' => 404,
            'title' => 'Not Found',
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    expect($driver->healthCheck())->toBeTrue();
});

test('mollie driver handles health check with network error', function () {
    $mock = new MockHandler([
        new ConnectException(
            'Connection failed',
            new Request('GET', '/v2/methods')
        ),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    expect($driver->healthCheck())->toBeFalse();
});

test('mollie driver handles charge with missing checkout url', function () {
    $mock = new MockHandler([
        new Response(201, [], json_encode([
            'id' => 'tr_WDqYK6vllg',
            'status' => 'open',
            'amount' => [
                'value' => '10.00',
                'currency' => 'EUR',
            ],
            '_links' => [],
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $request = new ChargeRequestDTO(
        amount: 10.00,
        currency: 'EUR',
        email: 'test@example.com',
        callbackUrl: 'https://example.com/callback',
    );

    $driver->charge($request);
})->throws(ChargeException::class, 'No checkout URL returned by Mollie');

test('mollie driver handles verify with missing amount data', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'id' => 'tr_WDqYK6vllg',
            'status' => 'paid',
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $driver->verify('tr_WDqYK6vllg');
})->throws(VerificationException::class);
