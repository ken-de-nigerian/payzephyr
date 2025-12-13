<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\MollieDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

beforeEach(function () {
    $this->config = [
        'api_key' => 'test_mollie_api_key',
        'base_url' => 'https://api.mollie.com',
        'currencies' => ['EUR', 'USD', 'GBP'],
    ];
});

test('mollie driver handles charge with zero amount', function () {
    new ChargeRequestDTO(
        amount: 0.00,
        currency: 'EUR',
        email: 'test@example.com',
        callbackUrl: 'https://example.com/callback',
    );
})->throws(\InvalidArgumentException::class, 'Amount must be greater than zero');

test('mollie driver handles charge with unsupported currency', function () {
    $mock = new MockHandler([
        new Response(422, [], json_encode([
            'status' => 422,
            'title' => 'Unprocessable Entity',
            'detail' => 'Currency JPY is not supported',
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $request = new ChargeRequestDTO(
        amount: 10.00,
        currency: 'JPY',
        email: 'test@example.com',
        callbackUrl: 'https://example.com/callback',
    );

    $driver->charge($request);
})->throws(ChargeException::class);

test('mollie driver handles network timeout during charge', function () {
    $mock = new MockHandler([
        new ConnectException(
            'Connection timeout',
            new Request('POST', '/v2/payments')
        ),
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
})->throws(ChargeException::class);

test('mollie driver handles network timeout during verify', function () {
    $mock = new MockHandler([
        new ConnectException(
            'Connection timeout',
            new Request('GET', '/v2/payments/tr_test')
        ),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $driver->verify('tr_test');
})->throws(VerificationException::class);

test('mollie driver handles charge with missing callback url', function () {
    $mock = new MockHandler([
        new Response(422, [], json_encode([
            'status' => 422,
            'title' => 'Unprocessable Entity',
            'detail' => 'redirectUrl is required',
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $request = new ChargeRequestDTO(
        amount: 10.00,
        currency: 'EUR',
        email: 'test@example.com',
    );

    $driver->charge($request);
})->throws(ChargeException::class);

test('mollie driver handles verify with unauthorized access', function () {
    $mock = new MockHandler([
        new Response(401, [], json_encode([
            'status' => 401,
            'title' => 'Unauthorized',
            'detail' => 'Invalid API key',
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $driver->verify('tr_test');
})->throws(VerificationException::class);

test('mollie driver handles verify with malformed response', function () {
    $mock = new MockHandler([
        new Response(200, [], 'invalid json'),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $driver->verify('tr_test');
})->throws(VerificationException::class);

test('mollie driver handles webhook validation with API timeout', function () {
    $mock = new MockHandler([
        new ConnectException(
            'Connection timeout',
            new Request('GET', '/v2/payments/tr_test')
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

test('mollie driver handles webhook validation with payment ID mismatch', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'id' => 'tr_DIFFERENT',
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

test('mollie driver handles charge with empty description', function () {
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
        description: '',
    );

    $response = $driver->charge($request);

    expect($response->status)->toBe('pending');
});

test('mollie driver handles verify with null paidAt', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'id' => 'tr_WDqYK6vllg',
            'status' => 'open',
            'amount' => [
                'value' => '10.00',
                'currency' => 'EUR',
            ],
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $verification = $driver->verify('tr_WDqYK6vllg');

    expect($verification->paidAt)->toBeNull();
});

test('mollie driver handles verify with missing method field', function () {
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

    $verification = $driver->verify('tr_WDqYK6vllg');

    expect($verification->channel)->toBeNull();
});

test('mollie driver handles charge with very long description', function () {
    $longDescription = str_repeat('A', 1000);
    $mock = new MockHandler([
        new Response(422, [], json_encode([
            'status' => 422,
            'title' => 'Unprocessable Entity',
            'detail' => 'Description is too long',
        ])),
    ]);

    $driver = new MollieDriver($this->config);
    $driver->setClient(new Client(['handler' => HandlerStack::create($mock)]));

    $request = new ChargeRequestDTO(
        amount: 10.00,
        currency: 'EUR',
        email: 'test@example.com',
        callbackUrl: 'https://example.com/callback',
        description: $longDescription,
    );

    $driver->charge($request);
})->throws(ChargeException::class);

test('mollie driver handles charge with special characters in metadata', function () {
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
        metadata: [
            'order_id' => '123',
            'special' => 'Value with "quotes" & <tags>',
            'unicode' => '🎉 Test',
        ],
    );

    $response = $driver->charge($request);

    expect($response->metadata)->toHaveKey('special')
        ->and($response->metadata['special'])->toBe('Value with "quotes" & <tags>');
});
