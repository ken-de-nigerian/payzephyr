<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

function createMonnifyDriverWithMock(array $responses): MonnifyDriver
{
    $config = [
        'api_key' => 'MK_TEST_xxx',
        'secret_key' => 'SK_TEST_xxx',
        'contract_code' => 'CONTRACT123',
        'base_url' => 'https://sandbox.monnify.com',
        'currencies' => ['NGN'],
    ];

    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);
    $client = new Client(['handler' => $handlerStack]);

    $driver = new class($config) extends MonnifyDriver
    {
        public function setClient(Client $client): void
        {
            $this->client = $client;
        }
    };

    $driver->setClient($client);

    return $driver;
}

test('monnify authenticates and charges successfully', function () {
    $driver = createMonnifyDriverWithMock([
        // First call: authentication
        new Response(200, [], json_encode([
            'requestSuccessful' => true,
            'responseBody' => [
                'accessToken' => 'bearer_token_xyz',
                'expiresIn' => 3600,
            ],
        ])),
        // Second call: charge
        new Response(200, [], json_encode([
            'requestSuccessful' => true,
            'responseBody' => [
                'checkoutUrl' => 'https://checkout.monnify.com/abc123',
                'transactionReference' => 'mn_ref_123',
            ],
        ])),
    ]);

    $request = new ChargeRequestDTO(20000, 'NGN', 'test@example.com', 'mn_ref_123');
    $response = $driver->charge($request);

    expect($response->reference)->toBe('mn_ref_123')
        ->and($response->authorizationUrl)->toBe('https://checkout.monnify.com/abc123')
        ->and($response->status)->toBe('pending');
});

test('monnify charge handles authentication failure', function () {
    $driver = createMonnifyDriverWithMock([
        new Response(401, [], json_encode([
            'requestSuccessful' => false,
            'responseMessage' => 'Invalid credentials',
        ])),
    ]);

    $driver->charge(new ChargeRequestDTO(10000, 'NGN', 'test@example.com'));
})->throws(ChargeException::class);

test('monnify charge handles api error', function () {
    $driver = createMonnifyDriverWithMock([
        // Auth succeeds
        new Response(200, [], json_encode([
            'requestSuccessful' => true,
            'responseBody' => ['accessToken' => 'token', 'expiresIn' => 3600],
        ])),
        // Charge fails
        new Response(400, [], json_encode([
            'requestSuccessful' => false,
            'responseMessage' => 'Invalid amount',
        ])),
    ]);

    $driver->charge(new ChargeRequestDTO(10000, 'NGN', 'test@example.com'));
})->throws(ChargeException::class);

test('monnify verify returns success', function () {
    $driver = createMonnifyDriverWithMock([
        // Auth
        new Response(200, [], json_encode([
            'requestSuccessful' => true,
            'responseBody' => ['accessToken' => 'token', 'expiresIn' => 3600],
        ])),
        // Verify
        new Response(200, [], json_encode([
            'requestSuccessful' => true,
            'responseBody' => [
                'transactionReference' => 'mn_ref_123',
                'paymentStatus' => 'PAID',
                'amountPaid' => 20000,
                'currencyCode' => 'NGN',
                'paymentMethod' => 'CARD',
                'customer' => ['email' => 'test@example.com'],
            ],
        ])),
    ]);

    $result = $driver->verify('mn_ref_123');

    expect($result->status)->toBe('success')
        ->and($result->amount)->toBe(20000.0)
        ->and($result->isSuccessful())->toBeTrue();
});

test('monnify verify returns pending', function () {
    $driver = createMonnifyDriverWithMock([
        new Response(200, [], json_encode([
            'requestSuccessful' => true,
            'responseBody' => ['accessToken' => 'token', 'expiresIn' => 3600],
        ])),
        new Response(200, [], json_encode([
            'requestSuccessful' => true,
            'responseBody' => [
                'transactionReference' => 'mn_pending',
                'paymentStatus' => 'PENDING',
                'amountPaid' => 0,
                'currencyCode' => 'NGN',
                'customer' => ['email' => 'test@example.com'],
            ],
        ])),
    ]);

    $result = $driver->verify('mn_pending');
    expect($result->isPending())->toBeTrue();
});

test('monnify verify handles not found', function () {
    $driver = createMonnifyDriverWithMock([
        new Response(200, [], json_encode([
            'requestSuccessful' => true,
            'responseBody' => ['accessToken' => 'token', 'expiresIn' => 3600],
        ])),
        new Response(404, [], json_encode([
            'requestSuccessful' => false,
            'responseMessage' => 'Transaction not found',
        ])),
    ]);

    $driver->verify('mn_nonexistent');
})->throws(VerificationException::class);

test('monnify handles network error during charge', function () {
    $mock = new MockHandler([
        new ConnectException('Timeout', new Request('POST', '/api/v1/auth/login')),
    ]);

    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $driver = new class(['api_key' => 'test', 'secret_key' => 'test', 'contract_code' => 'test', 'currencies' => ['NGN']]) extends MonnifyDriver
    {
        public function setClient(Client $client): void
        {
            $this->client = $client;
        }
    };
    $driver->setClient($client);

    $driver->charge(new ChargeRequestDTO(10000, 'NGN', 'test@example.com'));
})->throws(ChargeException::class);
