<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\PaddleDriver;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;

/*
 * What PaddleDriver sends to Paddle, what it makes of the answers, and what
 * it logs - including how it reads Paddle's signature header.
 */

/**
 * @param  list<mixed>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function paddleContractDriver(array $responses = [], array &$history = [], array $config = []): PaddleDriver
{
    $driver = new PaddleDriver($config + ['api_key' => 'pdl_key', 'webhook_secret' => 'pdl_secret', 'currencies' => ['USD', 'JPY']]);
    $headers = (new ReflectionClass($driver))->getProperty('client')->getValue($driver)->getConfig('headers');
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $driver->setClient(new Client(['handler' => $stack, 'headers' => $headers]));

    return $driver;
}

function paddleTransaction(array $data = []): Response
{
    return new Response(201, [], (string) json_encode(['data' => $data + ['id' => 'txn_1', 'status' => 'ready', 'checkout' => ['url' => 'https://pay.paddle.test/txn_1']]]));
}

function paddleCharge(array $overrides = []): ChargeRequestDTO
{
    return new ChargeRequestDTO(...$overrides + ['amount' => 10.5, 'currency' => 'usd', 'email' => 'a@b.com', 'reference' => 'PADDLE_1', 'callbackUrl' => 'https://shop.test/return']);
}

function paddleSignature(string $body, int $ts, string $secret = 'pdl_secret'): string
{
    return hash_hmac('sha256', $ts.':'.$body, $secret);
}

afterEach(fn () => Carbon::setTestNow());

// ---------------------------------------------------------------------------
// charge()
// ---------------------------------------------------------------------------

test('a charge sends Paddle its full transaction, authenticated, as JSON', function (): void {
    $history = [];
    $result = paddleContractDriver([paddleTransaction(['status' => 'completed'])], $history, ['product_name' => 'Shop', 'tax_category' => 'digital-goods'])
        ->charge(paddleCharge(['description' => 'Order 9', 'metadata' => ['order' => 9]]));

    $request = $history[0]['request'];
    expect(json_decode((string) $request->getBody(), true))->toBe([
        'items' => [[
            'quantity' => 1,
            'price' => [
                'description' => 'Order 9',
                'name' => 'Order 9',
                'unit_price' => ['amount' => '1050', 'currency_code' => 'USD'],
                'product' => ['name' => 'Shop', 'tax_category' => 'digital-goods'],
            ],
        ]],
        'currency_code' => 'USD',
        'collection_mode' => 'automatic',
        'custom_data' => ['order' => 9, 'reference' => 'PADDLE_1', 'email' => 'a@b.com'],
        'checkout' => ['url' => 'https://shop.test/return?reference=PADDLE_1'],
    ])
        ->and((string) $request->getUri())->toEndWith('/transactions')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer pdl_key')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($request->getHeaderLine('Accept'))->toBe('application/json')
        ->and($result->status)->toBe('success')
        ->and($result->metadata)->toBe(['order' => 9, 'paddle_transaction_id' => 'txn_1', 'reference' => 'PADDLE_1']);
});

test('a charge without a description, product or callback falls back, and sends no checkout', function (): void {
    $history = [];
    $result = paddleContractDriver([paddleTransaction(['status' => null])], $history)->charge(paddleCharge(['callbackUrl' => null]));

    $sent = json_decode((string) $history[0]['request']->getBody(), true);
    expect($sent['items'][0]['price']['description'])->toBe('Payment')
        ->and($sent['items'][0]['price']['name'])->toBe('Payment')
        ->and($sent['items'][0]['price']['product'])->toBe(['name' => 'Payment', 'tax_category' => 'standard'])
        ->and($sent)->not->toHaveKey('checkout')
        // No status reads as a draft, which is pending.
        ->and($result->status)->toBe('pending');
});

test('an amount is sent in minor units, rounded, and whole for a zero-decimal currency', function (float $amount, string $currency, string $minor): void {
    $history = [];
    paddleContractDriver([paddleTransaction()], $history)->charge(paddleCharge(['amount' => $amount, 'currency' => $currency]));

    expect(json_decode((string) $history[0]['request']->getBody(), true)['items'][0]['price']['unit_price']['amount'])->toBe($minor);
})->with([
    'rounds up' => [10.006, 'USD', '1001'],
    'rounds down' => [10.004, 'USD', '1000'],
    'zero-decimal up' => [1000.6, 'JPY', '1001'],
    'zero-decimal down' => [1000.4, 'JPY', '1000'],
]);

test('an initialized charge is logged with both references', function (): void {
    $logs = captureLogs();
    paddleContractDriver([paddleTransaction()])->charge(paddleCharge());

    expect(loggedEntry($logs, 'Charge initialized successfully')['context'])->toBe(['reference' => 'PADDLE_1', 'paddle_id' => 'txn_1']);
});

test('an unexpected failure inside a charge is logged and wrapped, coded 0', function (): void {
    $logs = captureLogs();

    try {
        paddleContractDriver([fn () => throw new LogicException('handler blew up')])->charge(paddleCharge());
        test()->fail('Expected a ChargeException.');
    } catch (ChargeException $e) {
        expect($e->getMessage())->toBe('Payment initialization failed: handler blew up')->and($e->getCode())->toBe(0);
    }

    expect(loggedEntry($logs, 'Charge failed')['context'])->toBe(['error' => 'handler blew up', 'error_class' => LogicException::class]);
});

test('a charge does not leave its request behind for the next one', function (): void {
    $driver = paddleContractDriver([paddleTransaction()]);
    $driver->charge(paddleCharge(['idempotencyKey' => 'idem-1']));

    expect((new ReflectionClass($driver))->getProperty('currentRequest')->getValue($driver))->toBeNull();
});

// ---------------------------------------------------------------------------
// verify()
// ---------------------------------------------------------------------------

function paddleVerified(array $data): Response
{
    return new Response(200, [], (string) json_encode(['data' => $data + [
        'id' => 'txn_V', 'status' => 'completed', 'currency_code' => 'usd', 'details' => ['totals' => ['grand_total' => '1050']],
        'billed_at' => '2026-10-01T10:00:00Z',
        'payments' => [['method_details' => ['type' => 'card', 'card' => ['type' => 'visa', 'cardholder_name' => 'Ada']]]],
    ]]));
}

test('a transaction is looked up by its id and read in full', function (): void {
    $logs = captureLogs();
    $history = [];
    $result = paddleContractDriver([paddleVerified(['custom_data' => ['reference' => 'PADDLE_V', 'email' => 'a@b.com']])], $history)->verify('txn V');

    expect((string) $history[0]['request']->getUri())->toEndWith('/transactions/txn%20V')
        // No JSON body to set it, so it comes from the driver.
        ->and($history[0]['request']->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($result->reference)->toBe('PADDLE_V')
        ->and($result->currency)->toBe('USD')
        ->and($result->amount)->toBe(10.5)
        ->and($result->customer)->toBe(['email' => 'a@b.com', 'name' => 'Ada'])
        ->and(loggedEntry($logs, 'Payment verified')['context'])->toBe(['reference' => 'txn V', 'status' => 'completed']);
});

test('a transaction without its own reference is read by Paddle\'s id, or by the one asked for', function (array $data, string $reference): void {
    expect(paddleContractDriver([paddleVerified($data)])->verify('txn_asked')->reference)->toBe($reference);
})->with([
    'Paddle id' => [[], 'txn_V'],
    'no id' => [['id' => null], 'txn_asked'],
]);

test('a verification that fails is logged and wrapped, coded 0', function (): void {
    $logs = captureLogs();

    try {
        paddleContractDriver([fn () => throw new LogicException('handler blew up')])->verify('txn_B');
        test()->fail('Expected a VerificationException.');
    } catch (VerificationException $e) {
        expect($e->getMessage())->toBe('Payment verification failed: handler blew up')->and($e->getCode())->toBe(0);
    }

    expect(loggedEntry($logs, 'Verification failed')['context'])
        ->toBe(['reference' => 'txn_B', 'error' => 'handler blew up', 'error_class' => LogicException::class]);
});

// ---------------------------------------------------------------------------
// Webhooks
// ---------------------------------------------------------------------------

test('a signature header is read in either case, with spaces, after junk, and with any h1 matching', function (string $name, string $header): void {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));
    $body = '{"event_type":"transaction.completed"}';
    $header = str_replace('SIG', paddleSignature($body, 1_800_000_000), $header);

    expect(paddleContractDriver()->validateWebhook([$name => [$header]], $body))->toBeTrue();
})->with([
    'lower case' => ['paddle-signature', 'ts=1800000000;h1=SIG'],
    'title case' => ['Paddle-Signature', 'ts=1800000000;h1=SIG'],
    'spaces' => ['paddle-signature', ' ts = 1800000000 ; h1 = SIG '],
    'junk first' => ['paddle-signature', 'junk;ts=1800000000;h1=SIG'],
    'rotation' => ['paddle-signature', 'ts=1800000000;h1=old;h1=SIG'],
]);

test('a malformed signature header is refused as malformed', function (string $header): void {
    $logs = captureLogs();

    expect(paddleContractDriver()->validateWebhook(['paddle-signature' => [$header]], '{}'))->toBeFalse();

    $paddle = array_values(array_filter(array_column($logs->getArrayCopy(), 'message'), fn (string $m): bool => str_starts_with($m, '[paddle]')));
    expect($paddle)->toBe(['[paddle] Webhook signature header malformed']);
})->with([
    'no timestamp' => ['h1=abc'],
    'no signature' => ['ts=1800000000'],
    'timestamp not a number' => ['ts=soon;h1=abc'],
]);

test('a signature containing "=" is compared, not dropped as malformed', function (): void {
    $logs = captureLogs();

    expect(paddleContractDriver()->validateWebhook(['paddle-signature' => ['ts=1800000000;h1=a=b']], '{}'))->toBeFalse()
        ->and(loggedEntry($logs, 'Webhook signature validation failed')['context']['hint'])->toContain('PADDLE_WEBHOOK_SECRET');
});

test('without a secret, or a signature header, a webhook is refused and says why', function (): void {
    $logs = captureLogs();

    expect(paddleContractDriver(config: ['webhook_secret' => null])->validateWebhook(['paddle-signature' => ['x']], '{}'))->toBeFalse()
        ->and(paddleContractDriver()->validateWebhook([], '{}'))->toBeFalse()
        ->and(loggedEntry($logs, 'no webhook secret configured')['context']['hint'])->toContain('PADDLE_WEBHOOK_SECRET')
        ->and(loggedEntry($logs, 'Webhook signature missing')['context']['hint'])->toContain('Paddle-Signature');
});

test('a signature at the edge of the window is accepted, and one second past it is refused and logged', function (int $age, bool $accepted): void {
    config(['payments.security.webhook_timestamp_tolerance' => 300]);
    app()->forgetInstance('payments.config');
    Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000 + $age));
    $logs = captureLogs();
    $body = '{}';

    expect(paddleContractDriver()->validateWebhook(['paddle-signature' => ['ts=1800000000;h1='.paddleSignature($body, 1_800_000_000)]], $body))->toBe($accepted);

    if (! $accepted) {
        expect(loggedEntry($logs, 'outside tolerance window')['context'])->toBe(['timestamp' => 1_800_000_000, 'tolerance_seconds' => 300]);
    }
})->with([
    'at the edge' => [300, true],
    'past it' => [301, false],
]);

test('only a transaction event carries a payment status', function (): void {
    $driver = paddleContractDriver();

    expect($driver->extractWebhookStatus(['event_type' => 'transaction.completed', 'data' => ['status' => 'completed']]))->toBe('completed')
        ->and($driver->extractWebhookStatus(['event_type' => 'subscription.created', 'data' => ['status' => 'active']]))->toBe('unknown')
        ->and($driver->extractWebhookStatus(['data' => ['status' => 'completed']]))->toBe('unknown');
});

// ---------------------------------------------------------------------------
// Health
// ---------------------------------------------------------------------------

test('the health check counts Paddle\'s 400 and 404 as healthy, and anything else as down', function (): void {
    $logs = captureLogs();
    $request = new Request('GET', '/event-types');

    expect(paddleContractDriver([new ClientException('Not Found', $request, new Response(404))])->healthCheck())->toBeTrue()
        ->and(loggedEntry($logs, 'Health check successful')['level'])->toBe('info')
        ->and(paddleContractDriver([fn () => throw new RuntimeException('not ours', 0, new ClientException('x', $request, new Response(400)))])->healthCheck())->toBeFalse()
        ->and(paddleContractDriver([new ConnectException('refused', $request)])->healthCheck())->toBeFalse()
        ->and(array_column(array_filter($logs->getArrayCopy(), fn (array $r): bool => str_contains($r['message'], 'Health check failed')), 'context')[0])->toBe(['error' => 'not ours']);
});

test('without a configured product name, the product is named after the charge', function (): void {
    $history = [];
    paddleContractDriver([paddleTransaction()], $history)->charge(paddleCharge(['description' => 'Order 9']));

    expect(json_decode((string) $history[0]['request']->getBody(), true)['items'][0]['price']['product']['name'])->toBe('Order 9');
});

test('a charge\'s round trip to Paddle is recorded on its own timeline', function (): void {
    config(['payments.features.trace' => true, 'payments.trace.async' => false]);
    app()->forgetInstance('payments.config');

    paddleContractDriver([paddleTransaction()])->charge(paddleCharge());

    expect(PaymentTraceEvent::where('reference', 'PADDLE_1')->pluck('event')->map->value->all())
        ->toBe([TraceEvent::PROVIDER_REQUEST_SENT->value, TraceEvent::PROVIDER_RESPONSE_RECEIVED->value]);
});
