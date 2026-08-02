<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

/**
 * Closes remaining coverage gaps in PayPalDriver::charge()/verify()/healthCheck()
 * that are not exercised by PayPalDriverCoverageTest, PayPalDriverIntegrationTest,
 * PayPalDriverWebhookTest, or PayPalAsyncWebhookVerificationTest.
 */
function paypalRemainingGapsConfig(): array
{
    return [
        'client_id' => 'CLIENT_ID_xxx',
        'client_secret' => 'CLIENT_SECRET_xxx',
        'mode' => 'sandbox',
        'currencies' => ['USD', 'EUR'],
    ];
}

function paypalRemainingGapsDriver(array $responses): PayPalDriver
{
    $mock = new MockHandler($responses);
    $client = new Client(['handler' => HandlerStack::create($mock)]);

    $driver = new PayPalDriver(paypalRemainingGapsConfig());
    $driver->setClient($client);

    return $driver;
}

test('paypal driver charge throws invalid configuration exception when callback url is missing', function () {
    // InvalidConfigurationException is thrown from inside charge()'s try
    // block, so it is not a ChargeException itself and gets caught by the
    // generic catch (Throwable $e) block, re-wrapped as a ChargeException
    // whose message carries the original text.
    $driver = new PayPalDriver(paypalRemainingGapsConfig());

    $driver->charge(new ChargeRequestDTO(1000, 'USD', 'test@example.com'));
})->throws(ChargeException::class, 'PayPal requires a callback URL for its redirect flow');

test('paypal driver charge throws when order id missing from creation response', function () {
    $driver = paypalRemainingGapsDriver([
        new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
        new Response(201, [], json_encode(['status' => 'CREATED'])),
    ]);

    $driver->charge(new ChargeRequestDTO(1000, 'USD', 'test@example.com', null, 'https://example.com/callback'));
})->throws(ChargeException::class, 'Failed to create PayPal order');

test('paypal driver charge falls back to payer-action link when approve link is absent', function () {
    $driver = paypalRemainingGapsDriver([
        new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
        new Response(201, [], json_encode([
            'id' => 'ORDER_PAYER_ACTION',
            'status' => 'PAYER_ACTION_REQUIRED',
            'links' => [
                ['rel' => 'payer-action', 'href' => 'https://www.paypal.com/payer-action'],
            ],
        ])),
    ]);

    $response = $driver->charge(new ChargeRequestDTO(1000, 'USD', 'test@example.com', null, 'https://example.com/callback'));

    expect($response->authorizationUrl)->toBe('https://www.paypal.com/payer-action');
});

test('paypal driver charge throws when no approval link is present at all', function () {
    $driver = paypalRemainingGapsDriver([
        new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
        new Response(201, [], json_encode([
            'id' => 'ORDER_NO_LINKS',
            'status' => 'CREATED',
            'links' => [
                ['rel' => 'self', 'href' => 'https://www.paypal.com/self'],
            ],
        ])),
    ]);

    $driver->charge(new ChargeRequestDTO(1000, 'USD', 'test@example.com', null, 'https://example.com/callback'));
})->throws(ChargeException::class, 'No approval link found in PayPal response');

test('paypal driver charge wraps unexpected non-charge errors in a charge exception', function () {
    // Order response has an 'id' and an approvable link, but is missing
    // 'status'. normalizeStatus() has a `string $status` parameter, and this
    // file declares strict_types=1, so passing the resulting null triggers a
    // TypeError - a Throwable that is not already a ChargeException/
    // VerificationException - which must be caught by the generic
    // catch (Throwable $e) block instead of the earlier catch (ChargeException).
    $driver = paypalRemainingGapsDriver([
        new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
        new Response(201, [], json_encode([
            'id' => 'ORDER_NO_STATUS',
            'links' => [
                ['rel' => 'approve', 'href' => 'https://www.paypal.com/checkoutnow'],
            ],
        ])),
    ]);

    $driver->charge(new ChargeRequestDTO(1000, 'USD', 'test@example.com', null, 'https://example.com/callback'));
})->throws(ChargeException::class, 'Payment initialization failed');

test('paypal driver verify throws when order id missing from lookup response', function () {
    $driver = paypalRemainingGapsDriver([
        new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
        new Response(200, [], json_encode(['status' => 'CREATED'])),
    ]);

    $driver->verify('ORDER_MISSING');
})->throws(VerificationException::class, 'PayPal order not found: ORDER_MISSING');

test('paypal driver verify captures an approved order with no existing capture', function () {
    $driver = paypalRemainingGapsDriver([
        new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
        new Response(200, [], json_encode([
            'id' => 'ORDER_TO_CAPTURE',
            'status' => 'APPROVED',
            'purchase_units' => [[
                'amount' => ['value' => '50.00', 'currency_code' => 'USD'],
                'custom_id' => 'ref_to_capture',
            ]],
            'payer' => ['email_address' => 'buyer@example.com', 'name' => ['given_name' => 'Buyer']],
        ])),
        new Response(200, [], json_encode([
            'purchase_units' => [[
                'payments' => [
                    'captures' => [[
                        'id' => 'CAPTURE_NEW',
                        'status' => 'COMPLETED',
                        'create_time' => '2024-01-01T00:00:00Z',
                    ]],
                ],
            ]],
        ])),
    ]);

    $result = $driver->verify('ORDER_TO_CAPTURE');

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->metadata['capture_id'])->toBe('CAPTURE_NEW')
        ->and($result->paidAt)->toBe('2024-01-01T00:00:00Z');
});

test('paypal driver verify propagates a verification exception when the auto-capture call fails', function () {
    $driver = paypalRemainingGapsDriver([
        new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
        new Response(200, [], json_encode([
            'id' => 'ORDER_CAPTURE_FAILS',
            'status' => 'APPROVED',
            'purchase_units' => [[
                'amount' => ['value' => '50.00', 'currency_code' => 'USD'],
            ]],
        ])),
        new Response(400, [], json_encode(['name' => 'UNPROCESSABLE_ENTITY', 'message' => 'Order already captured'])),
    ]);

    $driver->verify('ORDER_CAPTURE_FAILS');
})->throws(VerificationException::class);

test('paypal driver healthCheck returns true when access token retrieval succeeds', function () {
    $driver = paypalRemainingGapsDriver([
        new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
    ]);

    expect($driver->healthCheck())->toBeTrue();
});

test('paypal driver healthCheck returns true for a 401 auth failure - API is reachable, just misconfigured', function () {
    // Regression: healthCheck() previously had no getPrevious() check at
    // all, so every failure (bad credentials or genuinely unreachable API)
    // fell through to the generic catch and returned false. A 401 here
    // means PayPal's API answered - it's a credentials problem, not an
    // outage - which MonnifyDriver/SquareDriver's own healthCheck() already
    // treat as "healthy" for the same reason.
    $driver = paypalRemainingGapsDriver([
        new Response(401, [], json_encode(['error' => 'invalid_client'])),
    ]);

    expect($driver->healthCheck())->toBeTrue();
});

test('paypal driver healthCheck returns false for a non-HTTP connection failure', function () {
    $driver = paypalRemainingGapsDriver([
        new \GuzzleHttp\Exception\ConnectException(
            'Connection timed out',
            new \GuzzleHttp\Psr7\Request('POST', '/v1/oauth2/token')
        ),
    ]);

    expect($driver->healthCheck())->toBeFalse();
});
