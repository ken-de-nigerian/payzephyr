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
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\MollieDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use KenDeNigerian\PayZephyr\Exceptions\WebhookException;

/*
 * What MollieDriver sends to Mollie, what it makes of the answers, and what it
 * logs - including the three ways a Mollie webhook is checked: by signature,
 * as a hook.ping, and by looking the payment up.
 */

/**
 * @param  list<mixed>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function mollieContractDriver(array $responses, array &$history = [], ?string $secret = null): MollieDriver
{
    $driver = new MollieDriver(array_filter(['api_key' => 'test_key', 'webhook_secret' => $secret, 'currencies' => ['EUR']]));
    $headers = (new ReflectionClass($driver))->getProperty('client')->getValue($driver)->getConfig('headers');
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $driver->setClient(new Client(['handler' => $stack, 'headers' => $headers]));

    return $driver;
}

function molliePayment(array $overrides = []): Response
{
    return new Response(201, [], (string) json_encode($overrides + [
        'id' => 'tr_1', 'status' => 'open', '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/tr_1']],
    ]));
}

function mollieCharge(array $overrides = []): ChargeRequestDTO
{
    return new ChargeRequestDTO(...$overrides + ['amount' => 1234.5, 'currency' => 'EUR', 'email' => 'a@b.com', 'reference' => 'MOLLIE_1', 'callbackUrl' => 'https://shop.test/return']);
}

// ---------------------------------------------------------------------------
// charge()
// ---------------------------------------------------------------------------

test('a charge sends Mollie its full payload, authenticated, as JSON', function (): void {
    $history = [];
    $result = mollieContractDriver([molliePayment()], $history)->charge(mollieCharge([
        'description' => 'Order 9', 'metadata' => ['order' => 9], 'channels' => ['card'], 'idempotencyKey' => 'idem-1',
    ]));

    $request = $history[0]['request'];
    expect(json_decode((string) $request->getBody(), true))->toBe([
        'amount' => ['currency' => 'EUR', 'value' => '1234.50'],
        'description' => 'Order 9',
        'redirectUrl' => 'https://shop.test/return?reference=MOLLIE_1',
        'metadata' => ['order' => 9, 'reference' => 'MOLLIE_1'],
        'method' => ['creditcard'],
    ])
        ->and((string) $request->getUri())->toEndWith('/v2/payments')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer test_key')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($request->getHeaderLine('Accept'))->toBe('application/json')
        ->and($request->getHeaderLine('Idempotency-Key'))->toBe('idem-1')
        ->and($result->metadata)->toBe(['order' => 9, 'mollie_id' => 'tr_1', 'reference' => 'MOLLIE_1']);
});

test('a charge without a description says "Payment", and sends no method it was not given', function (): void {
    $history = [];
    mollieContractDriver([molliePayment()], $history)->charge(mollieCharge());

    $sent = json_decode((string) $history[0]['request']->getBody(), true);
    expect($sent['description'])->toBe('Payment')
        ->and($sent)->not->toHaveKey('method');
});

test('an initialized charge is logged with its references and whether it was idempotent', function (?string $key, bool $idempotent): void {
    $logs = captureLogs();

    mollieContractDriver([molliePayment()])->charge(mollieCharge(['idempotencyKey' => $key]));

    expect(loggedEntry($logs, 'Charge initialized successfully')['context'])->toBe(['reference' => 'MOLLIE_1', 'mollie_id' => 'tr_1', 'idempotent' => $idempotent]);
})->with([
    'with a key' => ['idem-2', true],
    'without' => [null, false],
]);

test('an unexpected failure inside a charge is logged and wrapped, coded 0, and leaves no key behind', function (): void {
    $logs = captureLogs();
    $history = [];
    $driver = mollieContractDriver([fn () => throw new LogicException('handler blew up'), new Response(200, [], '{}')], $history);

    try {
        $driver->charge(mollieCharge(['idempotencyKey' => 'idem-3']));
        test()->fail('Expected a ChargeException.');
    } catch (ChargeException $e) {
        expect($e->getMessage())->toBe('Payment initialization failed: handler blew up')->and($e->getCode())->toBe(0);
    }

    try {
        $driver->verify('tr_after');
    } catch (Throwable) {
    }

    expect(loggedEntry($logs, 'Charge failed')['context'])->toBe(['error' => 'handler blew up', 'error_class' => LogicException::class])
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->hasHeader('Idempotency-Key'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// verify()
// ---------------------------------------------------------------------------

test('a payment is looked up by its id and read in full', function (): void {
    $logs = captureLogs();
    $history = [];
    $driver = mollieContractDriver([new Response(200, [], (string) json_encode([
        'id' => 'tr 1', 'status' => 'paid', 'amount' => ['value' => '12.50', 'currency' => 'EUR'], 'metadata' => ['reference' => 'MOLLIE_V', 'order' => 1],
        'paidAt' => '2026-10-01T10:00:00+00:00', 'method' => 'ideal',
    ]))], $history);

    $result = $driver->verify('tr 1');

    expect((string) $history[0]['request']->getUri())->toEndWith('/v2/payments/tr%201')
        ->and($result->reference)->toBe('MOLLIE_V')
        ->and($result->metadata)->toBe(['reference' => 'MOLLIE_V', 'order' => 1])
        ->and(loggedEntry($logs, 'Payment verified')['context'])->toBe(['reference' => 'tr 1', 'status' => 'paid']);
});

test('a verification that fails is logged and wrapped, coded 0', function (): void {
    $logs = captureLogs();

    try {
        mollieContractDriver([fn () => throw new LogicException('handler blew up')])->verify('tr_b');
        test()->fail('Expected a VerificationException.');
    } catch (VerificationException $e) {
        expect($e->getMessage())->toBe('Payment verification failed: handler blew up')->and($e->getCode())->toBe(0);
    }

    expect(loggedEntry($logs, 'Verification failed')['context'])
        ->toBe(['reference' => 'tr_b', 'error' => 'handler blew up', 'error_class' => LogicException::class]);
});

// ---------------------------------------------------------------------------
// Webhooks: by signature
// ---------------------------------------------------------------------------

test('the signature header is read in either case', function (string $header): void {
    $body = '{"resource":"event","id":"event_1","type":"payment-link.paid"}';

    expect(mollieContractDriver([], secret: 'whsec')->validateWebhook([$header => ['sha256='.hash_hmac('sha256', $body, 'whsec')]], $body))->toBeTrue();
})->with(['x-mollie-signature', 'X-Mollie-Signature']);

test('each signed webhook outcome is logged with what an operator needs', function (): void {
    $logs = captureLogs();
    $driver = mollieContractDriver([], secret: 'whsec');
    $sign = fn (string $body): array => ['x-mollie-signature' => [hash_hmac('sha256', $body, 'whsec')]];
    $ping = '{"resource":"event","id":"event_ping","type":"hook.ping"}';
    $typed = '{"resource":"event","id":"event_2","type":"payment-link.paid"}';

    expect($driver->validateWebhook([], '{}'))->toBeFalse()
        ->and($driver->validateWebhook(['x-mollie-signature' => ['forged']], '{}'))->toBeFalse()
        ->and($driver->validateWebhook($sign($ping), $ping))->toBeTrue()
        ->and($driver->validateWebhook($sign($typed), $typed))->toBeTrue()
        ->and(loggedEntry($logs, 'Webhook signature missing')['context']['hint'])->toContain('X-Mollie-Signature')
        ->and(loggedEntry($logs, 'Webhook signature validation failed')['context']['hint'])->toContain('MOLLIE_WEBHOOK_SECRET')
        ->and(loggedEntry($logs, 'hook.ping test event')['context'])->toBe(['event_id' => 'event_ping'])
        ->and(loggedEntry($logs, 'via signature verification')['context'])->toBe(['event_type' => 'payment-link.paid']);
});

// ---------------------------------------------------------------------------
// Webhooks: by looking the payment up
// ---------------------------------------------------------------------------

test('without a secret, a classic webhook is checked by looking its payment up, and logged', function (): void {
    $logs = captureLogs();
    $history = [];

    expect(mollieContractDriver([new Response(200, [], '{"id":"tr 9","status":"paid"}')], $history)->validateWebhook([], '{"id":"tr 9"}'))->toBeTrue()
        ->and((string) $history[0]['request']->getUri())->toEndWith('/v2/payments/tr%209')
        ->and(loggedEntry($logs, 'via API verification')['context'])->toBe([
            'payment_id' => 'tr 9', 'payment_status' => 'paid', 'hint' => 'Consider configuring MOLLIE_WEBHOOK_SECRET for more secure signature-based validation',
        ]);
});

test('without a secret, a webhook that is not JSON, names no payment, or is typed is refused and logged', function (string $body, string $message): void {
    $logs = captureLogs();

    expect(mollieContractDriver([])->validateWebhook([], $body))->toBeFalse()
        ->and(loggedEntry($logs, $message)['level'])->toBe('warning')
        // Refused at the first reason, not at a later one as well.
        ->and(array_values(array_filter(array_column($logs->getArrayCopy(), 'message'), fn (string $m): bool => str_starts_with($m, '[mollie]'))))->toBe(['[mollie] '.$message]);
})->with([
    'not JSON' => ['not json', 'Webhook payload is invalid JSON'],
    'no payment id' => ['{"resource":"payment"}', 'Webhook missing payment ID'],
]);

test('without a secret, a typed webhook is refused with its type and how to fix it', function (): void {
    $logs = captureLogs();

    expect(mollieContractDriver([])->validateWebhook([], '{"type":"hook.ping","id":"event_1"}'))->toBeFalse()
        ->and(loggedEntry($logs, 'Rejected a typed Mollie webhook')['context'])->toBe([
            'event_type' => 'hook.ping',
            'hint' => 'Mollie signs typed webhooks. Set MOLLIE_WEBHOOK_SECRET to the secret shown when the webhook was created.',
        ]);
});

test('a looked-up payment that is not the one named is refused, and logged with both ids', function (string $answer, ?string $received): void {
    $logs = captureLogs();

    expect(mollieContractDriver([new Response(200, [], $answer)])->validateWebhook([], '{"id":"tr_named"}'))->toBeFalse()
        ->and(loggedEntry($logs, 'payment ID mismatch')['context'])->toBe(['expected' => 'tr_named', 'received' => $received]);
})->with([
    'another payment' => ['{"id":"tr_other"}', 'tr_other'],
    'no id' => ['{"status":"paid"}', null],
]);

test('a looked-up payment Mollie does not have is refused, and logged', function (): void {
    $logs = captureLogs();
    $missing = new ClientException('Not Found', new Request('GET', '/v2/payments/tr_x'), new Response(404));

    expect(mollieContractDriver([$missing])->validateWebhook([], '{"id":"tr_x"}'))->toBeFalse()
        ->and(loggedEntry($logs, 'Mollie rejected the payment lookup')['context']['error'])->toBeString()->not->toBeEmpty();
});

test('a lookup that cannot reach Mollie is raised, coded 0, so the delivery is retried', function (): void {
    $logs = captureLogs();
    $down = new ServerException('Bad Gateway', new Request('GET', '/v2/payments/tr_y'), new Response(502));

    try {
        mollieContractDriver([$down])->validateWebhook([], '{"id":"tr_y"}');
        test()->fail('Expected a WebhookException.');
    } catch (WebhookException $e) {
        expect($e->getMessage())->toStartWith('Mollie webhook verification failed: ')
            ->and(strlen($e->getMessage()))->toBeGreaterThan(strlen('Mollie webhook verification failed: '))
            ->and($e->getCode())->toBe(0);
    }

    $context = loggedEntry($logs, 'Webhook validation could not reach Mollie')['context'];
    expect($context['error_class'])->toBe(ChargeException::class)
        ->and($context['error'])->toBeString()->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Health and amounts
// ---------------------------------------------------------------------------

test('the health check counts Mollie\'s 400 and 404 as healthy, and logs anything else', function (): void {
    $logs = captureLogs();
    $request = new Request('GET', '/v2/methods');

    expect(mollieContractDriver([new ClientException('Not Found', $request, new Response(404))])->healthCheck())->toBeTrue()
        ->and(loggedEntry($logs, 'Health check successful')['level'])->toBe('info')
        ->and(mollieContractDriver([fn () => throw new RuntimeException('not ours', 0, new ClientException('x', $request, new Response(400)))])->healthCheck())->toBeFalse()
        ->and(loggedEntry($logs, 'Health check failed')['context'])->toBe(['error' => 'not ours']);
});

test('an amount is sent with two decimals and no thousands separator', function (): void {
    expect((new ReflectionClass(MollieDriver::class))->getMethod('formatAmount')->invoke(mollieContractDriver([]), 1234567.891, 'EUR'))->toBe('1234567.89');
});
