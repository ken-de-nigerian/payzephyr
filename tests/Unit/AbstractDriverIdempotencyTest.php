<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;

test('abstract driver injects idempotency key when request has it', function (): void {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'reference' => 'ref_123',
                'authorization_url' => 'https://paystack.com/verify/ref_123',
                'access_code' => 'access_123',
            ],
        ])),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $client = new Client(['handler' => $handlerStack]);

    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $driver->setClient($client);

    $request = new ChargeRequestDTO(
        1000,
        'NGN',
        'test@example.com',
        idempotencyKey: 'idempotency_key_123'
    );

    $driver->charge($request);

    $lastRequest = $mock->getLastRequest();
    $headers = $lastRequest->getHeaders();

    expect($headers)->toHaveKey('Idempotency-Key')
        ->and($headers['Idempotency-Key'][0])->toBe('idempotency_key_123');
});

test('abstract driver does not override existing idempotency header', function (): void {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'status' => true,
            'data' => [
                'reference' => 'ref_123',
                'authorization_url' => 'https://paystack.com/verify/ref_123',
                'access_code' => 'access_123',
            ],
        ])),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $client = new Client(['handler' => $handlerStack]);

    $driver = new PaystackDriver([
        'secret_key' => 'sk_test_xxx',
        'public_key' => 'pk_test_xxx',
        'currencies' => ['NGN'],
    ]);

    $driver->setClient($client);

    $request = new ChargeRequestDTO(
        1000,
        'NGN',
        'test@example.com',
        idempotencyKey: 'idempotency_key_123'
    );

    $driver->charge($request);

    $lastRequest = $mock->getLastRequest();
    $headers = $lastRequest->getHeaders();

    expect($headers)->toHaveKey('Idempotency-Key');
});
