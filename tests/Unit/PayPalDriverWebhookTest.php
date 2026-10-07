<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Exceptions\WebhookException;

test('paypal driver rejects webhook with missing transmission id', function (): void {
    config([
        'payments.providers.paypal' => [
            'driver' => 'paypal',
            'client_id' => 'test_client_id',
            'client_secret' => 'test_secret',
            'webhook_id' => 'test_webhook_id',
            'mode' => 'sandbox',
            'enabled' => true,
        ],
    ]);

    $driver = new PayPalDriver(config('payments.providers.paypal'));

    $headers = [
        'paypal-transmission-time' => [now()->toIso8601String()],
        'paypal-cert-url' => ['https://api.paypal.com/cert'],
        'paypal-auth-algo' => ['SHA256withRSA'],
        'paypal-transmission-sig' => ['signature_123'],
    ];

    $result = $driver->validateWebhook($headers, '{}');

    expect($result)->toBeFalse();
});

test('paypal driver rejects webhook with missing transmission time', function (): void {
    config([
        'payments.providers.paypal' => [
            'driver' => 'paypal',
            'client_id' => 'test_client_id',
            'client_secret' => 'test_secret',
            'webhook_id' => 'test_webhook_id',
            'mode' => 'sandbox',
            'enabled' => true,
        ],
    ]);

    $driver = new PayPalDriver(config('payments.providers.paypal'));

    $headers = [
        'paypal-transmission-id' => ['transmission_123'],
        'paypal-cert-url' => ['https://api.paypal.com/cert'],
        'paypal-auth-algo' => ['SHA256withRSA'],
        'paypal-transmission-sig' => ['signature_123'],
    ];

    $result = $driver->validateWebhook($headers, '{}');

    expect($result)->toBeFalse();
});

test('paypal driver rejects webhook with missing cert url', function (): void {
    config([
        'payments.providers.paypal' => [
            'driver' => 'paypal',
            'client_id' => 'test_client_id',
            'client_secret' => 'test_secret',
            'webhook_id' => 'test_webhook_id',
            'mode' => 'sandbox',
            'enabled' => true,
        ],
    ]);

    $driver = new PayPalDriver(config('payments.providers.paypal'));

    $headers = [
        'paypal-transmission-id' => ['transmission_123'],
        'paypal-transmission-time' => [now()->toIso8601String()],
        'paypal-auth-algo' => ['SHA256withRSA'],
        'paypal-transmission-sig' => ['signature_123'],
    ];

    $result = $driver->validateWebhook($headers, '{}');

    expect($result)->toBeFalse();
});

test('paypal driver rejects webhook with missing auth algo', function (): void {
    config([
        'payments.providers.paypal' => [
            'driver' => 'paypal',
            'client_id' => 'test_client_id',
            'client_secret' => 'test_secret',
            'webhook_id' => 'test_webhook_id',
            'mode' => 'sandbox',
            'enabled' => true,
        ],
    ]);

    $driver = new PayPalDriver(config('payments.providers.paypal'));

    $headers = [
        'paypal-transmission-id' => ['transmission_123'],
        'paypal-transmission-time' => [now()->toIso8601String()],
        'paypal-cert-url' => ['https://api.paypal.com/cert'],
        'paypal-transmission-sig' => ['signature_123'],
    ];

    $result = $driver->validateWebhook($headers, '{}');

    expect($result)->toBeFalse();
});

test('paypal driver rejects webhook with missing transmission sig', function (): void {
    config([
        'payments.providers.paypal' => [
            'driver' => 'paypal',
            'client_id' => 'test_client_id',
            'client_secret' => 'test_secret',
            'webhook_id' => 'test_webhook_id',
            'mode' => 'sandbox',
            'enabled' => true,
        ],
    ]);

    $driver = new PayPalDriver(config('payments.providers.paypal'));

    $headers = [
        'paypal-transmission-id' => ['transmission_123'],
        'paypal-transmission-time' => [now()->toIso8601String()],
        'paypal-cert-url' => ['https://api.paypal.com/cert'],
        'paypal-auth-algo' => ['SHA256withRSA'],
    ];

    $result = $driver->validateWebhook($headers, '{}');

    expect($result)->toBeFalse();
});

test('paypal driver rejects webhook with missing webhook id in config', function (): void {
    config([
        'payments.providers.paypal' => [
            'driver' => 'paypal',
            'client_id' => 'test_client_id',
            'client_secret' => 'test_secret',
            'mode' => 'sandbox',
            'enabled' => true,
        ],
    ]);

    $driver = new PayPalDriver(config('payments.providers.paypal'));

    $headers = [
        'paypal-transmission-id' => ['transmission_123'],
        'paypal-transmission-time' => [now()->toIso8601String()],
        'paypal-cert-url' => ['https://api.paypal.com/cert'],
        'paypal-auth-algo' => ['SHA256withRSA'],
        'paypal-transmission-sig' => ['signature_123'],
    ];

    $result = $driver->validateWebhook($headers, '{}');

    expect($result)->toBeFalse();
});

test('paypal driver accepts webhook with valid create_time within tolerance (ADR-0001)', function (): void {
    config([
        'payments.providers.paypal' => [
            'driver' => 'paypal',
            'client_id' => 'test_client_id',
            'client_secret' => 'test_secret',
            'webhook_id' => 'test_webhook_id',
            'mode' => 'sandbox',
            'enabled' => true,
        ],
    ]);

    $driver = new PayPalDriver(config('payments.providers.paypal'));

    // verifyWebhookSignatureViaAPI() makes two real HTTP calls in sequence:
    // an OAuth token request, then the verify-webhook-signature call. A
    // single unconditional mock (as other tests in this file use to test the
    // *failure* path) would make the OAuth step itself fail first and never
    // reach the verification-status check - so this needs an ordered queue.
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'access_token' => 'A21AA_test_token',
            'token_type' => 'Bearer',
            'expires_in' => 32400,
        ])),
        new Response(200, [], json_encode([
            'verification_status' => 'SUCCESS',
        ])),
    ]);
    $handlerStack = HandlerStack::create($mock);
    $driver->setClient(new Client(['handler' => $handlerStack]));

    $headers = [
        'paypal-transmission-id' => ['transmission_123'],
        'paypal-transmission-time' => [now()->toIso8601String()],
        'paypal-cert-url' => ['https://api.paypal.com/cert'],
        'paypal-auth-algo' => ['SHA256withRSA'],
        'paypal-transmission-sig' => ['signature_123'],
    ];

    // create_time is PayPal's real top-level field name (not our old
    // 'timestamp'/'created_at' fallback list) - see ADR-0001.
    $body = json_encode([
        'id' => 'WH-123',
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'create_time' => now()->toIso8601String(),
    ]);

    $result = $driver->validateWebhook($headers, $body);

    expect($result)->toBeTrue();
});

test('paypal driver rejects webhook with no recognizable create_time despite valid signature (ADR-0001)', function (): void {
    config([
        'payments.providers.paypal' => [
            'driver' => 'paypal',
            'client_id' => 'test_client_id',
            'client_secret' => 'test_secret',
            'webhook_id' => 'test_webhook_id',
            'mode' => 'sandbox',
            'enabled' => true,
        ],
    ]);

    $driver = new PayPalDriver(config('payments.providers.paypal'));

    // Verification itself succeeds (see the ordered-mock note in the test
    // above) - the false result must come solely from the missing create_time.
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'access_token' => 'A21AA_test_token',
            'token_type' => 'Bearer',
            'expires_in' => 32400,
        ])),
        new Response(200, [], json_encode([
            'verification_status' => 'SUCCESS',
        ])),
    ]);
    $handlerStack = HandlerStack::create($mock);
    $driver->setClient(new Client(['handler' => $handlerStack]));

    $headers = [
        'paypal-transmission-id' => ['transmission_123'],
        'paypal-transmission-time' => [now()->toIso8601String()],
        'paypal-cert-url' => ['https://api.paypal.com/cert'],
        'paypal-auth-algo' => ['SHA256withRSA'],
        'paypal-transmission-sig' => ['signature_123'],
    ];

    $body = json_encode(['id' => 'WH-123', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED']);

    $result = $driver->validateWebhook($headers, $body);

    expect($result)->toBeFalse();
});

test('paypal extractWebhookChannel reports the instrument the payer actually used', function (): void {
    // Regression: this returned a hardcoded 'paypal' without reading the
    // payload, so a card-funded and a Venmo-funded payment were recorded
    // identically - and redundantly with provider='paypal'.
    $driver = new PayPalDriver(config('payments.providers.paypal'));

    $payload = ['resource' => ['payment_source' => ['card' => ['brand' => 'VISA']]]];

    expect($driver->extractWebhookChannel($payload))->toBe('card');
});

test('paypal extractWebhookChannel distinguishes a paypal-balance payment from a card one', function (): void {
    $driver = new PayPalDriver(config('payments.providers.paypal'));

    expect($driver->extractWebhookChannel(['resource' => ['payment_source' => ['paypal' => []]]]))->toBe('paypal')
        ->and($driver->extractWebhookChannel(['resource' => ['payment_source' => ['venmo' => []]]]))->toBe('venmo');
});

test('paypal extractWebhookChannel returns null when the payload reports no payment source', function (): void {
    // Null, not an invented 'paypal': the funding instrument is genuinely
    // unknown when PayPal omits the field.
    $driver = new PayPalDriver(config('payments.providers.paypal'));

    expect($driver->extractWebhookChannel([]))->toBeNull()
        ->and($driver->extractWebhookChannel(['resource' => []]))->toBeNull()
        ->and($driver->extractWebhookChannel(['resource' => ['payment_source' => []]]))->toBeNull();
});

test('paypal extractWebhookChannel ignores a non-array payment_source', function (): void {
    $driver = new PayPalDriver(config('payments.providers.paypal'));

    expect($driver->extractWebhookChannel(['resource' => ['payment_source' => 'card']]))->toBeNull();
});

/*
 * PayPal verifies by asking PayPal: an OAuth token request, then
 * verify-webhook-signature. These tests queue both, in order, and record the
 * requests, so each one proves which step produced its outcome. A single
 * unconditional mock makes the token step fail first and never reaches the
 * verification call at all - which is how the tests these replace passed
 * without exercising the branch their names described.
 */

/**
 * @param  array<int, array<string, mixed>>  $history  filled with the requests actually sent
 */
function paypalWebhookDriver(mixed $verifyOutcome, array &$history): PayPalDriver
{
    $driver = new PayPalDriver([
        'client_id' => 'test_client_id',
        'client_secret' => 'test_secret',
        'webhook_id' => 'test_webhook_id',
        'mode' => 'sandbox',
        'currencies' => ['USD'],
    ]);

    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], (string) json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
        $verifyOutcome,
    ]));
    $stack->push(Middleware::history($history));
    $driver->setClient(new Client(['handler' => $stack]));

    return $driver;
}

function paypalWebhookHeaders(): array
{
    return [
        'paypal-transmission-id' => ['transmission_123'],
        'paypal-transmission-time' => [now()->toIso8601String()],
        'paypal-cert-url' => ['https://api.paypal.com/cert'],
        'paypal-auth-algo' => ['SHA256withRSA'],
        'paypal-transmission-sig' => ['signature_123'],
    ];
}

function paypalWebhookBody(?int $createTime = null): string
{
    return (string) json_encode([
        'id' => 'WH-123',
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'create_time' => date('c', $createTime ?? time()),
    ]);
}

function paypalVerifyCallWasSent(array $history): bool
{
    return count($history) === 2
        && str_ends_with($history[1]['request']->getUri()->getPath(), '/v1/notifications/verify-webhook-signature');
}

test('paypal rejects a webhook whose signature PayPal reports as FAILURE', function (): void {
    $history = [];
    $driver = paypalWebhookDriver(new Response(200, [], '{"verification_status":"FAILURE"}'), $history);

    expect($driver->validateWebhook(paypalWebhookHeaders(), paypalWebhookBody()))->toBeFalse()
        ->and(paypalVerifyCallWasSent($history))->toBeTrue();
});

test('paypal rejects a webhook when PayPal returns no verification status', function (): void {
    $history = [];
    $driver = paypalWebhookDriver(new Response(200, [], '{"verification_status":""}'), $history);

    expect($driver->validateWebhook(paypalWebhookHeaders(), paypalWebhookBody()))->toBeFalse()
        ->and(paypalVerifyCallWasSent($history))->toBeTrue();
});

test('paypal sends the transmission headers and the configured webhook id for verification', function (): void {
    $history = [];
    $driver = paypalWebhookDriver(new Response(200, [], '{"verification_status":"SUCCESS"}'), $history);

    expect($driver->validateWebhook(paypalWebhookHeaders(), paypalWebhookBody()))->toBeTrue();

    $sent = json_decode((string) $history[1]['request']->getBody(), true);
    expect($sent)->toMatchArray([
        'transmission_id' => 'transmission_123',
        'cert_url' => 'https://api.paypal.com/cert',
        'auth_algo' => 'SHA256withRSA',
        'transmission_sig' => 'signature_123',
        'webhook_id' => 'test_webhook_id',
    ])->and($sent['webhook_event']['id'])->toBe('WH-123');
});

test('paypal treats a 4xx from the verification API as a rejection', function (): void {
    // PayPal answered, and the answer was about the request we sent it - a
    // malformed cert url, say. Retrying would get the same answer.
    $history = [];
    $driver = paypalWebhookDriver(new ClientException(
        'Bad Request',
        new Request('POST', '/v1/notifications/verify-webhook-signature'),
        new Response(400, [], '{"name":"VALIDATION_ERROR"}'),
    ), $history);

    expect($driver->validateWebhook(paypalWebhookHeaders(), paypalWebhookBody()))->toBeFalse()
        ->and(paypalVerifyCallWasSent($history))->toBeTrue();
});

test('paypal throws rather than rejecting when the verification API cannot be reached', function (): void {
    // This runs in the queued job after PayPal has been told 202. Returning
    // false would discard a genuine delivery PayPal will never resend;
    // throwing hands it to the job's retries.
    $history = [];
    $driver = paypalWebhookDriver(new ConnectException(
        'Connection timed out',
        new Request('POST', '/v1/notifications/verify-webhook-signature'),
    ), $history);

    expect(fn (): bool => $driver->validateWebhook(paypalWebhookHeaders(), paypalWebhookBody()))
        ->toThrow(WebhookException::class);
});

test('paypal throws rather than rejecting on a verification failure that is not the sender\'s doing', function (int $status): void {
    $history = [];
    $request = new Request('POST', '/v1/notifications/verify-webhook-signature');
    $response = new Response($status);
    $driver = paypalWebhookDriver(
        $status >= 500
            ? new ServerException('Server error', $request, $response)
            : new ClientException('Client error', $request, $response),
        $history,
    );

    expect(fn (): bool => $driver->validateWebhook(paypalWebhookHeaders(), paypalWebhookBody()))
        ->toThrow(WebhookException::class);
})->with([
    'unauthorized: our credentials' => [401],
    'forbidden: our credentials' => [403],
    'request timeout' => [408],
    'rate limited' => [429],
    'server error' => [500],
    'service unavailable' => [503],
]);

test('paypal throws rather than rejecting when the OAuth token cannot be obtained', function (): void {
    // No token means PayPal was never asked about this webhook at all.
    $driver = new PayPalDriver([
        'client_id' => 'test_client_id',
        'client_secret' => 'test_secret',
        'webhook_id' => 'test_webhook_id',
        'mode' => 'sandbox',
        'currencies' => ['USD'],
    ]);
    $driver->setClient(new Client(['handler' => HandlerStack::create(new MockHandler([
        new ServerException(
            'Server error',
            new Request('POST', '/v1/oauth2/token'),
            new Response(503),
        ),
    ]))]));

    expect(fn (): bool => $driver->validateWebhook(paypalWebhookHeaders(), paypalWebhookBody()))
        ->toThrow(WebhookException::class);
});

test('paypal measures the replay window from the receipt time it is given, then from now once cleared', function (): void {
    // The queued job hands the driver the moment the delivery arrived. A
    // create_time ten minutes before "now" but moments before receipt is a
    // delivery that waited in the queue, not a replay. A five-minute window
    // makes the difference between the two measurements decisive.
    config(['payments.webhook.events.replay_window' => 300]);
    app()->forgetInstance('payments.config');
    $receivedAt = time() - 600;

    $history = [];
    $driver = paypalWebhookDriver(new Response(200, [], '{"verification_status":"SUCCESS"}'), $history);
    $driver->setWebhookReceivedAt($receivedAt);
    expect($driver->validateWebhook(paypalWebhookHeaders(), paypalWebhookBody($receivedAt - 5)))->toBeTrue();

    $history = [];
    $driver = paypalWebhookDriver(new Response(200, [], '{"verification_status":"SUCCESS"}'), $history);
    $driver->setWebhookReceivedAt($receivedAt);
    $driver->setWebhookReceivedAt(null);
    expect($driver->validateWebhook(paypalWebhookHeaders(), paypalWebhookBody($receivedAt - 5)))->toBeFalse();
});
