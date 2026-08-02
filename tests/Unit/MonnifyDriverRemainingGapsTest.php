<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\MonnifyDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

function createMonnifyRemainingGapsDriver(array $responses): MonnifyDriver
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

    $driver = new MonnifyDriver($config);
    $driver->setClient($client);

    return $driver;
}

test('monnify reuses cached access token across multiple calls', function () {
    $driver = createMonnifyRemainingGapsDriver([
        new Response(200, [], json_encode([
            'requestSuccessful' => true,
            'responseBody' => [
                'accessToken' => 'bearer_token_xyz',
                'expiresIn' => 3600,
            ],
        ])),
        new Response(200, [], json_encode([
            'requestSuccessful' => true,
            'responseBody' => [
                'checkoutUrl' => 'https://checkout.monnify.com/first',
                'transactionReference' => 'mn_ref_first',
            ],
        ])),
        new Response(200, [], json_encode([
            'requestSuccessful' => true,
            'responseBody' => [
                'checkoutUrl' => 'https://checkout.monnify.com/second',
                'transactionReference' => 'mn_ref_second',
            ],
        ])),
    ]);

    $first = $driver->charge(new ChargeRequestDTO(10000, 'NGN', 'test@example.com', 'mn_ref_first'));
    $second = $driver->charge(new ChargeRequestDTO(10000, 'NGN', 'test@example.com', 'mn_ref_second'));

    expect($first->reference)->toBe('mn_ref_first')
        ->and($second->reference)->toBe('mn_ref_second');
});

test('monnify getAccessToken throws when auth response reports requestSuccessful false with 200 status', function () {
    $driver = createMonnifyRemainingGapsDriver([
        new Response(200, [], json_encode([
            'requestSuccessful' => false,
            'responseMessage' => 'Invalid API key supplied',
        ])),
    ]);

    $driver->charge(new ChargeRequestDTO(10000, 'NGN', 'test@example.com'));
})->throws(ChargeException::class, 'Monnify authentication failed');

test('monnify charge throws when init-transaction reports requestSuccessful false with 200 status', function () {
    $driver = createMonnifyRemainingGapsDriver([
        new Response(200, [], json_encode([
            'requestSuccessful' => true,
            'responseBody' => ['accessToken' => 'token', 'expiresIn' => 3600],
        ])),
        new Response(200, [], json_encode([
            'requestSuccessful' => false,
            'responseMessage' => 'Merchant is not permitted for this operation',
        ])),
    ]);

    $driver->charge(new ChargeRequestDTO(10000, 'NGN', 'test@example.com'));
})->throws(ChargeException::class, 'Merchant is not permitted for this operation');

test('monnify charge wraps unexpected throwables from a malformed success response', function () {
    $driver = createMonnifyRemainingGapsDriver([
        new Response(200, [], json_encode([
            'requestSuccessful' => true,
            'responseBody' => ['accessToken' => 'token', 'expiresIn' => 3600],
        ])),
        new Response(200, [], json_encode([
            'requestSuccessful' => true,
            'responseBody' => [
                'checkoutUrl' => null,
                'transactionReference' => 'mn_ref_123',
            ],
        ])),
    ]);

    $driver->charge(new ChargeRequestDTO(10000, 'NGN', 'test@example.com'));
})->throws(ChargeException::class, 'Monnify charge failed');

test('monnify verify throws when query reports requestSuccessful false with 200 status', function () {
    $driver = createMonnifyRemainingGapsDriver([
        new Response(200, [], json_encode([
            'requestSuccessful' => true,
            'responseBody' => ['accessToken' => 'token', 'expiresIn' => 3600],
        ])),
        new Response(200, [], json_encode([
            'requestSuccessful' => false,
            'responseMessage' => 'Transaction reference is invalid',
        ])),
    ]);

    $driver->verify('mn_bad_ref');
})->throws(VerificationException::class, 'Transaction reference is invalid');

test('monnify validateWebhook returns false when no signature header is present', function () {
    $driver = new MonnifyDriver([
        'api_key' => 'test_key',
        'secret_key' => 'test_secret',
        'contract_code' => 'test_contract',
        'currencies' => ['NGN'],
    ]);

    $body = json_encode(['eventType' => 'SUCCESSFUL_TRANSACTION', 'eventData' => []]);

    expect($driver->validateWebhook([], $body))->toBeFalse();
});

test('monnify validateWebhook returns false when signature does not match', function () {
    $driver = new MonnifyDriver([
        'api_key' => 'test_key',
        'secret_key' => 'test_secret',
        'contract_code' => 'test_contract',
        'currencies' => ['NGN'],
    ]);

    $body = json_encode(['eventType' => 'SUCCESSFUL_TRANSACTION', 'eventData' => []]);

    expect($driver->validateWebhook(['monnify-signature' => ['not-the-real-signature']], $body))->toBeFalse();
});

test('monnify extractWebhookStatus defaults to unknown when paymentStatus is absent', function () {
    $driver = new MonnifyDriver([
        'api_key' => 'test_key',
        'secret_key' => 'test_secret',
        'contract_code' => 'test_contract',
        'currencies' => ['NGN'],
    ]);

    expect($driver->extractWebhookStatus(['eventType' => 'SUCCESSFUL_TRANSACTION']))->toBe('unknown');
});

test('monnify resolveVerificationId returns the provider id as-is', function () {
    $driver = new MonnifyDriver([
        'api_key' => 'test_key',
        'secret_key' => 'test_secret',
        'contract_code' => 'test_contract',
        'currencies' => ['NGN'],
    ]);

    expect($driver->resolveVerificationId('MON_REF_123', 'provider_tx_456'))->toBe('provider_tx_456');
});
