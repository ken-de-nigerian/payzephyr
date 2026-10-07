<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\MollieDriver;

test('mollie integration - full payment flow', function (): void {
    // Create a mock Mollie driver
    $config = [
        'api_key' => 'test_xxx',
        'base_url' => 'https://api.mollie.com',
        'currencies' => ['EUR', 'USD', 'GBP'],
    ];

    $mock = new MockHandler([
        new Response(201, [], json_encode([
            'id' => 'tr_test123',
            'mode' => 'test',
            'createdAt' => '2024-01-01T12:00:00+00:00',
            'status' => 'open',
            'amount' => [
                'value' => '10.00',
                'currency' => 'EUR',
            ],
            'description' => 'Test Payment',
            'metadata' => [],
            'redirectUrl' => 'https://www.mollie.com/checkout/select-method/7UhSN1zuXS',
            'webhookUrl' => 'https://example.com/callback',
            '_links' => [
                'checkout' => [
                    'href' => 'https://www.mollie.com/checkout/select-method/7UhSN1zuXS',
                    'type' => 'text/html',
                ],
            ],
        ])),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $client = new Client(['handler' => $handlerStack]);

    $driver = new MollieDriver($config);
    $driver->setClient($client);

    // Create payment request
    $request = new ChargeRequestDTO(
        amount: 10.00,
        currency: 'EUR',
        email: 'test@example.com',
        callbackUrl: 'https://example.com/callback',
        metadata: ['description' => 'Test Payment']
    );

    // Charge payment directly through driver
    $response = $driver->charge($request);

    // Assertions
    expect($response->reference)->toBeString()
        ->and($response->authorizationUrl)->toStartWith('https://www.mollie.com')
        ->and($response->status)->toBe('pending')
        ->and($response->provider)->toBe('mollie');
});
