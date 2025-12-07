<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

function createPayPalDriverWithMock(array $responses): PayPalDriver
{
    $config = [
        'client_id' => 'CLIENT_ID_xxx',
        'client_secret' => 'CLIENT_SECRET_xxx',
        'mode' => 'sandbox',
        'currencies' => ['USD', 'EUR', 'GBP'],
        'callback_url' => 'https://example.com/callback',
    ];

    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);
    $client = new Client(['handler' => $handlerStack]);

    $driver = new class($config) extends PayPalDriver
    {
        public function setClient(Client $client): void
        {
            $this->client = $client;
        }
    };

    $driver->setClient($client);

    return $driver;
}

test('paypal authenticates and charges successfully', function () {
    $driver = createPayPalDriverWithMock([
        // Auth
        new Response(200, [], json_encode([
            'access_token' => 'A21AAxx',
            'expires_in' => 32400,
            'token_type' => 'Bearer',
        ])),
        // Create order
        new Response(201, [], json_encode([
            'id' => 'ORDER_ID_123',
            'status' => 'CREATED',
            'links' => [
                ['rel' => 'approve', 'href' => 'https://www.paypal.com/checkoutnow?token=ORDER_ID_123'],
            ],
        ])),
    ]);

    $request = new ChargeRequestDTO(10000, 'USD', 'test@example.com', 'pp_ref_123');
    $response = $driver->charge($request);

    expect($response->reference)->toBe('pp_ref_123')
        ->and($response->authorizationUrl)->toContain('paypal.com/checkoutnow')
        ->and($response->status)->toBe('pending');
});

test('paypal charge handles authentication failure', function () {
    $driver = createPayPalDriverWithMock([
        new Response(401, [], json_encode([
            'error' => 'invalid_client',
            'error_description' => 'Client Authentication failed',
        ])),
    ]);

    $driver->charge(new ChargeRequestDTO(10000, 'USD', 'test@example.com'));
})->throws(ChargeException::class);

test('paypal charge handles api error', function () {
    $driver = createPayPalDriverWithMock([
        new Response(200, [], json_encode([
            'access_token' => 'token',
            'expires_in' => 32400,
        ])),
        new Response(400, [], json_encode([
            'name' => 'INVALID_REQUEST',
            'message' => 'Invalid currency code',
        ])),
    ]);

    // This throws InvalidArgumentException because ChargeRequestDTO validation runs first
    $driver->charge(new ChargeRequestDTO(10000, 'INVALID', 'test@example.com'));
})->throws(InvalidArgumentException::class);

test('paypal verify returns success', function () {
    $driver = createPayPalDriverWithMock([
        new Response(200, [], json_encode([
            'access_token' => 'token',
            'expires_in' => 32400,
        ])),
        new Response(200, [], json_encode([
            'id' => 'ORDER_ID_123',
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'amount' => ['value' => '100.00', 'currency_code' => 'USD'],
                'payments' => [
                    'captures' => [[
                        'id' => 'CAPTURE_123',
                        'status' => 'COMPLETED',
                    ]],
                ],
            ]],
            'payer' => ['email_address' => 'test@example.com'],
        ])),
    ]);

    $result = $driver->verify('pp_ref_123');

    expect($result->status)->toBe('success')
        ->and($result->amount)->toBe(100.0)
        ->and($result->isSuccessful())->toBeTrue();
});

test('paypal verify returns pending', function () {
    $driver = createPayPalDriverWithMock([
        new Response(200, [], json_encode(['access_token' => 'token', 'expires_in' => 32400])),
        new Response(200, [], json_encode([
            'id' => 'ORDER_ID_123',
            'status' => 'APPROVED',
            'purchase_units' => [[
                'amount' => ['value' => '100.00', 'currency_code' => 'USD'],
            ]],
        ])),
    ]);

    $result = $driver->verify('pp_pending');
    expect($result->isPending())->toBeTrue();
});

test('paypal verify handles not found', function () {
    $driver = createPayPalDriverWithMock([
        new Response(200, [], json_encode(['access_token' => 'token', 'expires_in' => 32400])),
        new Response(404, [], json_encode([
            'name' => 'RESOURCE_NOT_FOUND',
            'message' => 'The specified resource does not exist',
        ])),
    ]);

    $driver->verify('pp_nonexistent');
})->throws(VerificationException::class);

test('paypal handles network error', function () {
    $mock = new MockHandler([
        new ConnectException('Timeout', new Request('POST', '/v1/oauth2/token')),
    ]);

    $client = new Client(['handler' => HandlerStack::create($mock)]);
    // We add callback_url to config to avoid crash during network error test
    $driver = new class(['client_id' => 'test', 'client_secret' => 'test', 'mode' => 'sandbox', 'currencies' => ['USD'], 'callback_url' => 'http://test']) extends PayPalDriver
    {
        public function setClient(Client $client): void
        {
            $this->client = $client;
        }
    };
    $driver->setClient($client);

    $driver->charge(new ChargeRequestDTO(10000, 'USD', 'test@example.com'));
})->throws(ChargeException::class);
