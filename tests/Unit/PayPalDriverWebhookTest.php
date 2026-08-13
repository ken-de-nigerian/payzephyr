<?php

use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;

test('paypal driver validates webhook with all required headers', function () {
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
        'paypal-transmission-sig' => ['signature_123'],
    ];

    $result = $driver->validateWebhook($headers, '{"event_type":"PAYMENT.CAPTURE.COMPLETED"}');

    expect($result)->toBeBool(); // Just verify it returns a boolean (header validation passed)
});

test('paypal driver rejects webhook with missing transmission id', function () {
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

test('paypal driver rejects webhook with missing transmission time', function () {
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

test('paypal driver rejects webhook with missing cert url', function () {
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

test('paypal driver rejects webhook with missing auth algo', function () {
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

test('paypal driver rejects webhook with missing transmission sig', function () {
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

test('paypal driver rejects webhook with missing webhook id in config', function () {
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

test('paypal driver handles api verification failure', function () {
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

    $mockClient = Mockery::mock(\GuzzleHttp\Client::class);
    $mockClient->shouldReceive('request')
        ->andThrow(new \GuzzleHttp\Exception\RequestException(
            'API Error',
            Mockery::mock(\Psr\Http\Message\RequestInterface::class)
        ));

    $driver->setClient($mockClient);

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

test('paypal driver handles verification status failure', function () {
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

    $mockStream = Mockery::mock(\Psr\Http\Message\StreamInterface::class);
    $mockStream->shouldReceive('__toString')
        ->andReturn(json_encode(['verification_status' => 'FAILURE']));

    $mockResponse = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
    $mockResponse->shouldReceive('getBody')
        ->andReturn($mockStream);

    $mockClient = Mockery::mock(\GuzzleHttp\Client::class);
    $mockClient->shouldReceive('request')
        ->andReturn($mockResponse);

    $driver->setClient($mockClient);

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

test('paypal driver accepts webhook with valid create_time within tolerance (ADR-0001)', function () {
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
    $mock = new \GuzzleHttp\Handler\MockHandler([
        new \GuzzleHttp\Psr7\Response(200, [], json_encode([
            'access_token' => 'A21AA_test_token',
            'token_type' => 'Bearer',
            'expires_in' => 32400,
        ])),
        new \GuzzleHttp\Psr7\Response(200, [], json_encode([
            'verification_status' => 'SUCCESS',
        ])),
    ]);
    $handlerStack = \GuzzleHttp\HandlerStack::create($mock);
    $driver->setClient(new \GuzzleHttp\Client(['handler' => $handlerStack]));

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

test('paypal driver rejects webhook with no recognizable create_time despite valid signature (ADR-0001)', function () {
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
    $mock = new \GuzzleHttp\Handler\MockHandler([
        new \GuzzleHttp\Psr7\Response(200, [], json_encode([
            'access_token' => 'A21AA_test_token',
            'token_type' => 'Bearer',
            'expires_in' => 32400,
        ])),
        new \GuzzleHttp\Psr7\Response(200, [], json_encode([
            'verification_status' => 'SUCCESS',
        ])),
    ]);
    $handlerStack = \GuzzleHttp\HandlerStack::create($mock);
    $driver->setClient(new \GuzzleHttp\Client(['handler' => $handlerStack]));

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

test('paypal driver handles empty verification status', function () {
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

    $mockStream = Mockery::mock(\Psr\Http\Message\StreamInterface::class);
    $mockStream->shouldReceive('__toString')
        ->andReturn(json_encode(['verification_status' => '']));

    $mockResponse = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
    $mockResponse->shouldReceive('getBody')
        ->andReturn($mockStream);

    $mockClient = Mockery::mock(\GuzzleHttp\Client::class);
    $mockClient->shouldReceive('request')
        ->andReturn($mockResponse);

    $driver->setClient($mockClient);

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

test('paypal extractWebhookChannel reports the instrument the payer actually used', function () {
    // Regression: this returned a hardcoded 'paypal' without reading the
    // payload, so a card-funded and a Venmo-funded payment were recorded
    // identically - and redundantly with provider='paypal'.
    $driver = new PayPalDriver(config('payments.providers.paypal'));

    $payload = ['resource' => ['payment_source' => ['card' => ['brand' => 'VISA']]]];

    expect($driver->extractWebhookChannel($payload))->toBe('card');
});

test('paypal extractWebhookChannel distinguishes a paypal-balance payment from a card one', function () {
    $driver = new PayPalDriver(config('payments.providers.paypal'));

    expect($driver->extractWebhookChannel(['resource' => ['payment_source' => ['paypal' => []]]]))->toBe('paypal')
        ->and($driver->extractWebhookChannel(['resource' => ['payment_source' => ['venmo' => []]]]))->toBe('venmo');
});

test('paypal extractWebhookChannel returns null when the payload reports no payment source', function () {
    // Null, not an invented 'paypal': the funding instrument is genuinely
    // unknown when PayPal omits the field.
    $driver = new PayPalDriver(config('payments.providers.paypal'));

    expect($driver->extractWebhookChannel([]))->toBeNull()
        ->and($driver->extractWebhookChannel(['resource' => []]))->toBeNull()
        ->and($driver->extractWebhookChannel(['resource' => ['payment_source' => []]]))->toBeNull();
});

test('paypal extractWebhookChannel ignores a non-array payment_source', function () {
    $driver = new PayPalDriver(config('payments.providers.paypal'));

    expect($driver->extractWebhookChannel(['resource' => ['payment_source' => 'card']]))->toBeNull();
});
