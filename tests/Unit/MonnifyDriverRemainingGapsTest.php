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

test('monnify charge names the field when a success response carries no checkout url', function () {
    // This used to reach the DTO as a null and come back as a TypeError wrapped
    // in "Monnify charge failed". It now says which field Monnify left out.
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
})->throws(ChargeException::class, '[monnify] omitted the required field [checkoutUrl] from its charge response');

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

test('monnify refuses a login that reports success but carries no access token', function () {
    // Without this the null was stored as the token and returned from a
    // method declared to return a string.
    $driver = createMonnifyRemainingGapsDriver([
        new Response(200, [], json_encode(['requestSuccessful' => true, 'responseBody' => ['expiresIn' => 3600]])),
    ]);

    $driver->charge(new ChargeRequestDTO(10000, 'NGN', 'test@example.com'));
})->throws(ChargeException::class, 'returned no access token');

test('monnify reads the reference, status and channel from eventData in a current-format webhook', function () {
    // The three extractors read the top level, where Monnify's current format
    // has only eventType and eventData - so the reference was null, the status
    // "unknown", and the transaction was never updated from a webhook.
    $driver = new MonnifyDriver(['api_key' => 'k', 'secret_key' => 's', 'contract_code' => 'c', 'currencies' => ['NGN']]);
    $payload = ['eventType' => 'SUCCESSFUL_TRANSACTION', 'eventData' => [
        'paymentReference' => 'ORDER_1001',
        'transactionReference' => 'MNFY|20|1',
        'paymentStatus' => 'PAID',
        'paymentMethod' => 'ACCOUNT_TRANSFER',
    ]];

    expect($driver->extractWebhookReference($payload))->toBe('ORDER_1001')
        ->and($driver->extractWebhookStatus($payload))->toBe('PAID')
        ->and($driver->extractWebhookChannel($payload))->toBe('ACCOUNT_TRANSFER');
});

test('monnify still reads a legacy flat webhook, and falls back to the transaction reference', function () {
    $driver = new MonnifyDriver(['api_key' => 'k', 'secret_key' => 's', 'contract_code' => 'c', 'currencies' => ['NGN']]);

    expect($driver->extractWebhookReference(['paymentReference' => 'LEGACY_1', 'paymentStatus' => 'PAID']))->toBe('LEGACY_1')
        ->and($driver->extractWebhookReference(['eventData' => ['transactionReference' => 'MNFY|20|2']]))->toBe('MNFY|20|2')
        ->and($driver->extractWebhookStatus(['eventData' => []]))->toBe('unknown')
        ->and($driver->extractWebhookChannel([]))->toBeNull();
});

test('a monnify webhook updates the transaction it names', function () {
    \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
        'reference' => 'ORDER_2002', 'provider' => 'monnify', 'status' => 'pending',
        'amount' => 5000, 'currency' => 'NGN', 'email' => 'a@b.test',
    ]);

    app()->call([new \KenDeNigerian\PayZephyr\Jobs\ProcessWebhook('monnify', ['eventType' => 'SUCCESSFUL_TRANSACTION', 'eventData' => [
        'paymentReference' => 'ORDER_2002', 'paymentStatus' => 'PAID', 'paymentMethod' => 'CARD',
    ]]), 'handle']);

    expect(\KenDeNigerian\PayZephyr\Models\PaymentTransaction::where('reference', 'ORDER_2002')->value('status'))->toBe('success');
});

test('monnify charge wraps a failure that is not an http error in a charge exception', function () {
    // The login succeeds; the charge request then fails beneath the HTTP call
    // with something that is not a Guzzle exception.
    $driver = createMonnifyRemainingGapsDriver([
        new Response(200, [], json_encode([
            'requestSuccessful' => true,
            'responseBody' => ['accessToken' => 'token', 'expiresIn' => 3600],
        ])),
        new RuntimeException('stream wrapper failed'),
    ]);

    try {
        $driver->charge(new ChargeRequestDTO(10000, 'NGN', 'test@example.com'));
        $this->fail('Expected a ChargeException');
    } catch (ChargeException $e) {
        expect($e->getMessage())->toBe('Monnify charge failed: stream wrapper failed')
            ->and($e->getPrevious())->toBeInstanceOf(RuntimeException::class);
    }
});
