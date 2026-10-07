<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Drivers\PayPalDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use KenDeNigerian\PayZephyr\Exceptions\WebhookException;

/*
 * What PayPalDriver sends to PayPal, what it makes of the answers, and what
 * it logs - its OAuth token, orders, captures, and PayPal's own webhook
 * verification.
 */

/**
 * @param  list<mixed>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function paypalContractDriver(array $responses = [], array &$history = [], array $config = []): PayPalDriver
{
    $driver = new PayPalDriver($config + ['client_id' => 'PP_ID', 'client_secret' => 'PP_SECRET', 'webhook_id' => 'WH_1', 'brand_name' => 'Shop', 'currencies' => ['USD', 'JPY']]);
    $headers = (new ReflectionClass($driver))->getProperty('client')->getValue($driver)->getConfig('headers');
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $driver->setClient(new Client(['handler' => $stack, 'headers' => $headers]));

    return $driver;
}

function paypalToken(?int $expiresIn = 3600, string $token = 'A21_1'): Response
{
    return new Response(200, [], (string) json_encode(array_filter(['access_token' => $token, 'expires_in' => $expiresIn], fn ($v): bool => $v !== null)));
}

function paypalOrder(array $data = []): Response
{
    return new Response(201, [], (string) json_encode($data + [
        'id' => 'ORDER_1', 'status' => 'PAYER_ACTION_REQUIRED',
        'links' => [['rel' => 'self', 'href' => 'https://api.paypal.test/self'], ['rel' => 'payer-action', 'href' => 'https://paypal.test/approve']],
    ]));
}

function paypalCharge(array $overrides = []): ChargeRequestDTO
{
    return new ChargeRequestDTO(...$overrides + ['amount' => 1234.5, 'currency' => 'USD', 'email' => 'a@b.com', 'reference' => 'PAYPAL_1', 'callbackUrl' => 'https://shop.test/return']);
}

afterEach(fn () => Carbon::setTestNow());

// ---------------------------------------------------------------------------
// Configuration and the access token
// ---------------------------------------------------------------------------

test('a PayPal driver needs both its client id and secret', function (array $config): void {
    expect(fn (): PayPalDriver => new PayPalDriver($config + ['currencies' => ['USD']]))->toThrow(InvalidConfigurationException::class);
})->with([
    'no id' => [['client_secret' => 'S']],
    'no secret' => [['client_id' => 'I']],
]);

test('a token is asked for with the client credentials, and requests then carry it as JSON', function (): void {
    $history = [];
    paypalContractDriver([paypalToken(), paypalOrder()], $history)->charge(paypalCharge());

    $tokenRequest = $history[0]['request'];
    expect((string) $tokenRequest->getUri())->toEndWith('/v1/oauth2/token')
        ->and($tokenRequest->getHeaderLine('Authorization'))->toBe('Basic '.base64_encode('PP_ID:PP_SECRET'))
        ->and($tokenRequest->getHeaderLine('Content-Type'))->toBe('application/x-www-form-urlencoded')
        ->and((string) $tokenRequest->getBody())->toBe('grant_type=client_credentials')
        ->and($history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer A21_1')
        ->and($history[1]['request']->getHeaderLine('Accept'))->toBe('application/json');
});

test('a request without a JSON body still says it is JSON', function (): void {
    $history = [];
    paypalContractDriver([paypalToken(), new Response(200, [], '{"id":"O","status":"CREATED","purchase_units":[{"amount":{"value":"1","currency_code":"USD"}}]}')], $history)->verify('O');

    expect($history[1]['request']->getHeaderLine('Content-Type'))->toBe('application/json');
});

test('the token is reused until a minute before it expires, then fetched again', function (?int $expiresIn, int $lifetime): void {
    Carbon::setTestNow('2026-10-01 12:00:00');
    $history = [];
    $driver = paypalContractDriver([paypalToken($expiresIn), paypalOrder(), paypalOrder(), paypalToken($expiresIn, 'A21_2'), paypalOrder()], $history);

    $driver->charge(paypalCharge());
    Carbon::setTestNow(now()->addSeconds($lifetime - 1));
    $driver->charge(paypalCharge());
    Carbon::setTestNow(now()->addSecond());
    $driver->charge(paypalCharge());

    $tokens = array_filter($history, fn (array $h): bool => str_ends_with((string) $h['request']->getUri(), '/oauth2/token'));
    expect($tokens)->toHaveCount(2)
        ->and(end($history)['request']->getHeaderLine('Authorization'))->toBe('Bearer A21_2');
})->with([
    'as PayPal says' => [120, 60],
    'an hour when it does not say' => [null, 3540],
]);

test('a token PayPal does not give fails the charge, coded 0, and is logged', function (string $body): void {
    $logs = captureLogs();

    try {
        paypalContractDriver([new Response(200, [], $body)])->charge(paypalCharge());
        test()->fail('Expected a ChargeException.');
    } catch (ChargeException $e) {
        expect($e->getMessage())->toBe('PayPal authentication failed: Failed to authenticate with PayPal')->and($e->getCode())->toBe(0);
    }

    expect(loggedEntry($logs, 'PayPal authentication failed')['context'])
        ->toBe(['error' => 'Failed to authenticate with PayPal', 'error_class' => ChargeException::class]);
})->with([
    'none' => ['{}'],
    'empty' => ['{"access_token":""}'],
]);

// ---------------------------------------------------------------------------
// charge()
// ---------------------------------------------------------------------------

test('a charge sends PayPal its full order', function (): void {
    $history = [];
    $result = paypalContractDriver([paypalToken(), paypalOrder()], $history)->charge(paypalCharge(['description' => 'Order 9']));

    expect(json_decode((string) $history[1]['request']->getBody(), true))->toBe([
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => 'PAYPAL_1',
            'description' => 'Order 9',
            'amount' => ['currency_code' => 'USD', 'value' => '1234.50'],
            'custom_id' => 'PAYPAL_1',
        ]],
        'payment_source' => ['paypal' => ['experience_context' => [
            'brand_name' => 'Shop',
            'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
            'landing_page' => 'GUEST_CHECKOUT',
            'user_action' => 'PAY_NOW',
            'return_url' => 'https://shop.test/return?reference=PAYPAL_1',
            'cancel_url' => 'https://shop.test/return?reference=PAYPAL_1',
        ]]],
    ])
        ->and((string) $history[1]['request']->getUri())->toEndWith('/v2/checkout/orders')
        ->and($result->authorizationUrl)->toBe('https://paypal.test/approve')
        ->and($result->status)->toBe('pending')
        ->and($result->metadata)->toBe(['order_id' => 'ORDER_1', 'links' => [
            ['rel' => 'self', 'href' => 'https://api.paypal.test/self'], ['rel' => 'payer-action', 'href' => 'https://paypal.test/approve'],
        ]]);
});

test('a charge without a description or brand falls back, and a zero-decimal currency is sent whole', function (): void {
    $history = [];
    paypalContractDriver([paypalToken(), paypalOrder()], $history, ['brand_name' => null])->charge(paypalCharge(['amount' => 1500, 'currency' => 'jpy']));

    $sent = json_decode((string) $history[1]['request']->getBody(), true);
    expect($sent['purchase_units'][0]['description'])->toBe('Payment')
        ->and($sent['purchase_units'][0]['amount']['value'])->toBe('1500')
        ->and($sent['payment_source']['paypal']['experience_context']['brand_name'])->toBe('Your Store');
});

test('every zero-decimal currency is sent whole', function (string $currency): void {
    $history = [];
    paypalContractDriver([paypalToken(), paypalOrder()], $history)->charge(paypalCharge(['amount' => 1500, 'currency' => $currency]));

    expect(json_decode((string) $history[1]['request']->getBody(), true)['purchase_units'][0]['amount']['value'])->toBe('1500');
})->with(['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF']);

test('an order without links or an id is refused, and the approve link wins over payer-action', function (): void {
    $approve = paypalOrder(['links' => [['rel' => 'payer-action', 'href' => 'https://paypal.test/action'], ['rel' => 'approve', 'href' => 'https://paypal.test/approve-old']]]);

    expect(paypalContractDriver([paypalToken(), $approve])->charge(paypalCharge())->authorizationUrl)->toBe('https://paypal.test/approve-old')
        ->and(fn (): ChargeResponseDTO => paypalContractDriver([paypalToken(), paypalOrder(['links' => []])])->charge(paypalCharge()))->toThrow(ChargeException::class, 'No approval link found in PayPal response')
        ->and(fn (): ChargeResponseDTO => paypalContractDriver([paypalToken(), new Response(201, [], '{}')])->charge(paypalCharge()))->toThrow(ChargeException::class, 'Failed to create PayPal order');
});

test('a charge without a callback URL is refused, saying how to set one', function (): void {
    expect(fn (): ChargeResponseDTO => paypalContractDriver()->charge(paypalCharge(['callbackUrl' => null])))->toThrow(
        InvalidConfigurationException::class,
        'PayPal requires a callback URL for its redirect flow. Please use ->callback() in your payment chain to set the callback URL.'
    );
});

test('an initialized charge is logged with both references, and a failed one is logged and wrapped, coded 0', function (): void {
    $logs = captureLogs();
    paypalContractDriver([paypalToken(), paypalOrder()])->charge(paypalCharge());

    try {
        paypalContractDriver([paypalToken(), fn () => throw new LogicException('handler blew up')])->charge(paypalCharge());
        test()->fail('Expected a ChargeException.');
    } catch (ChargeException $e) {
        expect($e->getMessage())->toBe('Payment initialization failed: handler blew up')->and($e->getCode())->toBe(0);
    }

    expect(loggedEntry($logs, 'Charge initialized successfully')['context'])->toBe(['reference' => 'PAYPAL_1', 'order_id' => 'ORDER_1'])
        ->and(loggedEntry($logs, 'Charge failed')['context'])->toBe(['error' => 'handler blew up', 'error_class' => LogicException::class]);
});

test('a charge leaves its key behind for no other request', function (): void {
    $history = [];
    $driver = paypalContractDriver([paypalToken(), paypalOrder(), new Response(200, [], '{}')], $history);

    $driver->charge(paypalCharge(['idempotencyKey' => 'idem-1']));

    try {
        $driver->verify('ORDER_1');
    } catch (Throwable) {
    }

    expect($history[1]['request']->getHeaderLine('PayPal-Request-Id'))->toBe('idem-1')
        ->and($history[2]['request']->hasHeader('PayPal-Request-Id'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// verify()
// ---------------------------------------------------------------------------

function paypalVerifiedOrder(array $data = []): Response
{
    return new Response(200, [], (string) json_encode($data + [
        'id' => 'ORDER 1', 'status' => 'completed',
        'purchase_units' => [[
            'custom_id' => 'PAYPAL_V', 'amount' => ['value' => '10.50', 'currency_code' => 'USD'],
            'payments' => ['captures' => [['id' => 'CAP_1', 'status' => 'completed', 'create_time' => '2026-10-01T10:00:00Z']]],
        ]],
        'payer' => ['email_address' => 'a@b.com', 'name' => ['given_name' => 'Ada']],
    ]));
}

test('an order is looked up by its id and read in full', function (): void {
    $history = [];
    $result = paypalContractDriver([paypalToken(), paypalVerifiedOrder()], $history)->verify('ORDER 1');

    expect((string) $history[1]['request']->getUri())->toEndWith('/v2/checkout/orders/ORDER%201')
        ->and($history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer A21_1')
        ->and($result->reference)->toBe('PAYPAL_V')
        ->and($result->status)->toBe('success')
        ->and($result->amount)->toBe(10.5)
        ->and($result->paidAt)->toBe('2026-10-01T10:00:00Z')
        ->and($result->metadata['order_id'])->toBe('ORDER 1')
        ->and($result->metadata['capture_id'])->toBe('CAP_1')
        ->and($result->metadata['raw']['id'])->toBe('ORDER 1')
        ->and($result->customer)->toBe(['email' => 'a@b.com', 'name' => 'Ada']);
});

test('an order\'s capture decides its status, in any case', function (string $orderStatus, string $captureStatus, string $expected): void {
    $order = paypalVerifiedOrder(['status' => $orderStatus, 'purchase_units' => [[
        'amount' => ['value' => '1', 'currency_code' => 'USD'], 'payments' => ['captures' => [['status' => $captureStatus]]],
    ]]]);

    expect(paypalContractDriver([paypalToken(), $order])->verify('ORDER_1'))
        ->status->toBe($expected)
        ->reference->toBe('ORDER_1');
})->with([
    'capture pending' => ['completed', 'pending', 'pending'],
    'capture completed' => ['created', 'completed', 'success'],
    'capture declined' => ['created', 'declined', 'pending'],
]);

test('an approved order with no capture yet is captured, and logged when that fails', function (): void {
    $logs = captureLogs();
    $history = [];
    $approved = paypalVerifiedOrder(['status' => 'approved', 'purchase_units' => [['amount' => ['value' => '1', 'currency_code' => 'USD']]]]);
    $captured = new Response(201, [], '{"purchase_units":[{"payments":{"captures":[{"id":"CAP_NEW","create_time":"2026-10-01T11:00:00Z"}]}}]}');

    $result = paypalContractDriver([paypalToken(), $approved, $captured], $history)->verify('ORDER 1');

    expect((string) $history[2]['request']->getUri())->toEndWith('/v2/checkout/orders/ORDER%201/capture')
        ->and($history[2]['request']->getHeaderLine('Authorization'))->toBe('Bearer A21_1')
        ->and($result->status)->toBe('success')
        ->and($result->metadata['capture_id'])->toBe('CAP_NEW');

    try {
        paypalContractDriver([paypalToken(), $approved, fn () => throw new LogicException('capture blew up')])->verify('ORDER 1');
        test()->fail('Expected a VerificationException.');
    } catch (VerificationException $e) {
        expect($e->getMessage())->toBe('PayPal capture failed: capture blew up')->and($e->getCode())->toBe(0);
    }

    expect(loggedEntry($logs, 'PayPal capture failed')['context'])->toBe(['error' => 'capture blew up', 'error_class' => LogicException::class]);
});

test('a verification that fails is logged and wrapped, coded 0, and a missing order is not found', function (): void {
    $logs = captureLogs();

    try {
        paypalContractDriver([paypalToken(), fn () => throw new LogicException('handler blew up')])->verify('ORDER_B');
        test()->fail('Expected a VerificationException.');
    } catch (VerificationException $e) {
        expect($e->getMessage())->toBe('Payment verification failed: handler blew up')->and($e->getCode())->toBe(0);
    }

    expect(loggedEntry($logs, 'Verification failed')['context'])->toBe(['error' => 'handler blew up', 'error_class' => LogicException::class])
        ->and(fn (): VerificationResponseDTO => paypalContractDriver([paypalToken(), new Response(200, [], '{}')])->verify('ORDER_X'))->toThrow(VerificationException::class, 'PayPal order not found: ORDER_X');
});

// ---------------------------------------------------------------------------
// Webhooks
// ---------------------------------------------------------------------------

function paypalContractWebhookHeaders(): array
{
    return [
        'paypal-transmission-id' => ['T_1'], 'paypal-transmission-time' => ['2026-10-01T10:00:00Z'],
        'paypal-cert-url' => ['https://api.paypal.com/cert'], 'paypal-auth-algo' => ['SHA256withRSA'], 'paypal-transmission-sig' => ['SIG'],
    ];
}

test('a webhook missing any of what PayPal needs to verify it is refused, saying which', function (): void {
    $logs = captureLogs();
    $headers = paypalContractWebhookHeaders();
    unset($headers['paypal-cert-url']);

    expect(paypalContractDriver()->validateWebhook($headers, '{}'))->toBeFalse()
        ->and(loggedEntry($logs, 'PayPal webhook missing required headers')['context'])->toBe([
            'has_transmission_id' => true, 'has_transmission_time' => true, 'has_cert_url' => false,
            'has_auth_algo' => true, 'has_transmission_sig' => true, 'has_webhook_id' => true,
        ]);
});

test('a webhook is sent back to PayPal exactly as it came, and accepted only on SUCCESS', function (string $status, bool $valid): void {
    $logs = captureLogs();
    $history = [];
    $createdAt = gmdate('Y-m-d\TH:i:s\Z');
    $body = '{"create_time":"'.$createdAt.'","resource":{},"event_type":"PAYMENT.CAPTURE.COMPLETED"}';

    expect(paypalContractDriver([paypalToken(), new Response(200, [], (string) json_encode(['verification_status' => $status]))], $history)
        ->validateWebhook(paypalContractWebhookHeaders(), $body))->toBe($valid);

    $request = $history[1]['request'];
    expect((string) $request->getUri())->toEndWith('/v1/notifications/verify-webhook-signature')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer A21_1')
        ->and((string) $request->getBody())->toContain('"webhook_event":{"create_time":"'.$createdAt.'","resource":{},')
        ->and(json_decode((string) $request->getBody(), true))->toMatchArray([
            'transmission_id' => 'T_1', 'transmission_time' => '2026-10-01T10:00:00Z', 'cert_url' => 'https://api.paypal.com/cert',
            'auth_algo' => 'SHA256withRSA', 'transmission_sig' => 'SIG', 'webhook_id' => 'WH_1',
        ])
        ->and(loggedEntry($logs, 'PayPal webhook validation result'))->toMatchArray([
            'level' => $valid ? 'info' : 'warning',
            'context' => ['valid' => $valid, 'status' => $status],
        ]);
})->with([
    'verified' => ['SUCCESS', true],
    'refused' => ['FAILURE', false],
]);

test('a verification answer without a status is refused and logged as unknown', function (): void {
    $logs = captureLogs();

    expect(paypalContractDriver([paypalToken(), new Response(200, [], '{}')])->validateWebhook(paypalContractWebhookHeaders(), '{}'))->toBeFalse()
        ->and(loggedEntry($logs, 'PayPal webhook validation result')['context'])->toBe(['valid' => false, 'status' => 'unknown']);
});

test('a verified webhook outside the replay window is refused, and logged', function (): void {
    $logs = captureLogs();
    $body = '{"create_time":"'.gmdate('Y-m-d\TH:i:s\Z', time() - 10 * 86400).'"}';

    expect(paypalContractDriver([paypalToken(), new Response(200, [], '{"verification_status":"SUCCESS"}')])->validateWebhook(paypalContractWebhookHeaders(), $body))->toBeFalse()
        ->and(loggedEntry($logs, 'Webhook timestamp validation failed')['level'])->toBe('warning');
});

test('a verification PayPal refuses is refused and logged; one that cannot reach PayPal is raised for retry, coded 0', function (): void {
    $logs = captureLogs();
    $request = new Request('POST', '/v1/notifications/verify-webhook-signature');

    expect(paypalContractDriver([paypalToken(), new ClientException('Bad Request', $request, new Response(400))])->validateWebhook(paypalContractWebhookHeaders(), '{}'))->toBeFalse()
        ->and(loggedEntry($logs, 'PayPal rejected the webhook verification request')['context']['error'])->toBeString()->not->toBeEmpty();

    try {
        paypalContractDriver([paypalToken(), new ServerException('Bad Gateway', $request, new Response(502))])->validateWebhook(paypalContractWebhookHeaders(), '{}');
        test()->fail('Expected a WebhookException.');
    } catch (WebhookException $e) {
        expect($e->getMessage())->toStartWith('PayPal webhook verification failed: ')
            ->and(strlen($e->getMessage()))->toBeGreaterThan(strlen('PayPal webhook verification failed: '))
            ->and($e->getCode())->toBe(0);
    }

    $context = loggedEntry($logs, 'PayPal webhook verification API failed')['context'];
    expect($context['error_class'])->toBe(ChargeException::class)
        ->and($context['error'])->toBeString()->not->toBeEmpty();
});

test('a webhook\'s reference, status and channel are read from its resource', function (): void {
    $driver = paypalContractDriver();

    expect($driver->extractWebhookReference(['resource' => ['custom_id' => 'R1', 'purchase_units' => [['custom_id' => 'R2']]]]))->toBe('R1')
        ->and($driver->extractWebhookReference(['resource' => ['purchase_units' => [['custom_id' => 'R2'], ['custom_id' => 'R3']]]]))->toBe('R2')
        ->and($driver->extractWebhookReference(['resource' => []]))->toBeNull()
        ->and($driver->extractWebhookStatus(['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['status' => 'COMPLETED']]))->toBe('COMPLETED')
        ->and($driver->extractWebhookStatus(['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => []]))->toBe('PAYMENT.CAPTURE.COMPLETED')
        ->and($driver->extractWebhookStatus([]))->toBe('unknown')
        ->and($driver->extractWebhookChannel(['resource' => ['payment_source' => ['card' => [], 'paypal' => []]]]))->toBe('card');
});
