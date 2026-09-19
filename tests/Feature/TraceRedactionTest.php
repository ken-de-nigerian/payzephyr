<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\TraceEventDTO;
use KenDeNigerian\PayZephyr\Enums\TraceDirection;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Facades\Trace;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Services\PayloadRedactor;
use Tests\Helpers\PaystackDriverTestHelper;

/**
 * Redaction where it actually matters.
 *
 * PayloadRedactorTest proves the redactor scrubs what it is handed. These
 * prove it is handed the right things: that a card number in a real provider
 * request body, a token in a real provider response, and a CVV in a real
 * webhook all reach the database already scrubbed. A redactor nothing calls on
 * the paths that carry provider data is worth nothing.
 */
function redactionManager(array $drivers): PaymentManager
{
    $manager = new PaymentManager;
    $property = (new ReflectionClass($manager))->getProperty('drivers');
    $property->setAccessible(true);
    $property->setValue($manager, $drivers);

    return $manager;
}

function redactionWebhookDriver(): DriverInterface
{
    $driver = Mockery::mock(DriverInterface::class);
    $driver->shouldReceive('extractWebhookReference')->andReturn('PZ_1755000000_abcdef01');
    $driver->shouldReceive('extractWebhookStatus')->andReturn('success');
    $driver->shouldReceive('extractWebhookChannel')->andReturn('card');
    $driver->shouldReceive('getName')->andReturn('paystack');

    return $driver;
}

/**
 * The whole stored row as one string, so a test can assert that a secret
 * appears nowhere in it rather than guessing which column it landed in.
 */
function storedTraceJson(): string
{
    return (string) json_encode(
        PaymentTraceEvent::all()->map(fn (PaymentTraceEvent $e): array => $e->toArray())->all()
    );
}

beforeEach(function () {
    app()->forgetInstance('payments.config');

    config([
        'payments.features.trace' => true,
        'payments.trace.async' => false,
        'payments.trace.record_http_bodies' => true,
        'payments.logging.enabled' => false,
        'payments.health_check.enabled' => false,
        'payments.webhook.verify_signature' => false,
        'payments.default' => 'primary',
        'payments.providers' => [
            'primary' => ['driver' => 'primary', 'enabled' => true, 'secret_key' => 'test'],
            'paystack' => ['driver' => 'paystack', 'enabled' => true, 'secret_key' => 'test'],
        ],
    ]);
});

// ---------------------------------------------------------------------------
// Provider traffic
// ---------------------------------------------------------------------------

test('a card number in an outbound provider request never reaches the table', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], (string) json_encode([
            'status' => true,
            'data' => ['reference' => 'order_redact', 'authorization_url' => 'https://x.test', 'access_code' => 'ac'],
        ])),
    ]);

    redactionManager(['primary' => $driver])->chargeWithFallback(ChargeRequestDTO::fromArray([
        'amount' => 100.00,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => 'order_redact',
        'metadata' => ['card_number' => '4111111111111111', 'order' => 'A1'],
    ]));

    $sent = PaymentTraceEvent::where('event', TraceEvent::PROVIDER_REQUEST_SENT->value)->sole();

    expect(storedTraceJson())->not->toContain('4111111111111111')
        ->and($sent->payload['metadata']['card_number'])->toBe(PayloadRedactor::REDACTED)
        ->and($sent->payload['metadata']['order'])->toBe('A1');
});

test('a token in a provider response never reaches the table', function () {
    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], (string) json_encode([
            'status' => true,
            'data' => [
                'reference' => 'order_redact',
                'authorization_url' => 'https://x.test',
                'access_code' => 'ac',
                'access_token' => 'sk_live_supersecretvalue',
            ],
        ])),
    ]);

    redactionManager(['primary' => $driver])->chargeWithFallback(ChargeRequestDTO::fromArray([
        'amount' => 100.00,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => 'order_redact',
    ]));

    $received = PaymentTraceEvent::where('event', TraceEvent::PROVIDER_RESPONSE_RECEIVED->value)->sole();

    expect(storedTraceJson())->not->toContain('sk_live_supersecretvalue')
        ->and($received->payload['data']['access_token'])->toBe(PayloadRedactor::REDACTED)
        ->and($received->payload['data']['access_code'])->toBe('ac');
});

// ---------------------------------------------------------------------------
// Webhook bodies
// ---------------------------------------------------------------------------

test('a CVV in a webhook body never reaches the table', function () {
    // Phase 6 started keeping webhook bodies, which webhook_events never did.
    // That is only acceptable because they arrive scrubbed.
    $manager = app(PaymentManager::class);
    $property = (new ReflectionClass($manager))->getProperty('drivers');
    $property->setAccessible(true);
    $property->setValue($manager, ['paystack' => redactionWebhookDriver()]);

    $job = new ProcessWebhook('paystack', [
        'event' => 'charge.success',
        'data' => ['reference' => 'PZ_1755000000_abcdef01', 'cvv' => '123', 'amount' => 5000],
    ]);

    app()->call([$job, 'handle']);

    $received = PaymentTraceEvent::where('event', TraceEvent::WEBHOOK_RECEIVED->value)->sole();

    expect(storedTraceJson())->not->toContain('"cvv":"123"')
        ->and($received->payload['data']['cvv'])->toBe(PayloadRedactor::REDACTED)
        ->and($received->payload['data']['amount'])->toBe(5000);
});

// ---------------------------------------------------------------------------
// Metadata, not just payload
// ---------------------------------------------------------------------------

test('metadata is scrubbed on the same terms as payload', function () {
    // PayZephyr's own call sites only put an event key and an IP in metadata,
    // but the DTO takes whatever it is given and the Trace facade is public.
    // Without this, the same key would be scrubbed in one column and stored in
    // clear in the other.
    Trace::record(new TraceEventDTO(
        reference: 'PZ_1755000000_abcdef01',
        event: TraceEvent::CUSTOM,
        direction: TraceDirection::INTERNAL,
        payload: ['api_key' => 'payload-secret'],
        metadata: ['api_key' => 'metadata-secret', 'ip' => '127.0.0.1'],
    ));

    $stored = PaymentTraceEvent::sole();

    expect(storedTraceJson())->not->toContain('metadata-secret')
        ->and($stored->metadata['api_key'])->toBe(PayloadRedactor::REDACTED)
        ->and($stored->payload['api_key'])->toBe(PayloadRedactor::REDACTED)
        ->and($stored->metadata['ip'])->toBe('127.0.0.1');
});

// ---------------------------------------------------------------------------
// Async
// ---------------------------------------------------------------------------

test('nothing sensitive is handed to the queue in async mode', function () {
    // Redaction happens before dispatch, so a secret never sits serialized in
    // the queue backend waiting to be written.
    config(['payments.trace.async' => true]);
    app()->forgetInstance('payments.config');

    Trace::record(new TraceEventDTO(
        reference: 'PZ_1755000000_abcdef01',
        event: TraceEvent::CUSTOM,
        direction: TraceDirection::INTERNAL,
        payload: ['cvv' => '999'],
        metadata: ['secret_key' => 'queued-secret'],
    ));

    $stored = PaymentTraceEvent::sole();

    expect($stored->payload['cvv'])->toBe(PayloadRedactor::REDACTED)
        ->and($stored->metadata['secret_key'])->toBe(PayloadRedactor::REDACTED);
});

// ---------------------------------------------------------------------------
// Configurability
// ---------------------------------------------------------------------------

test('the redacted field list governs provider traffic too, not only direct calls', function () {
    config(['payments.trace.redact_fields' => ['order_notes']]);
    app()->forgetInstance('payments.config');

    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], (string) json_encode([
            'status' => true,
            'data' => ['reference' => 'order_redact', 'authorization_url' => 'https://x.test', 'access_code' => 'ac'],
        ])),
    ]);

    redactionManager(['primary' => $driver])->chargeWithFallback(ChargeRequestDTO::fromArray([
        'amount' => 100.00,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => 'order_redact',
        'metadata' => ['order_notes' => 'private note', 'cvv' => '123'],
    ]));

    $sent = PaymentTraceEvent::where('event', TraceEvent::PROVIDER_REQUEST_SENT->value)->sole();

    // cvv is no longer in the list, so it is no longer redacted - the list is
    // the whole policy, and replacing it replaces the defaults too.
    expect($sent->payload['metadata']['order_notes'])->toBe(PayloadRedactor::REDACTED)
        ->and($sent->payload['metadata']['cvv'])->toBe('123');
});

test('with bodies switched off there is nothing to redact in the first place', function () {
    config(['payments.trace.record_http_bodies' => false]);
    app()->forgetInstance('payments.config');

    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], (string) json_encode([
            'status' => true,
            'data' => ['reference' => 'order_redact', 'authorization_url' => 'https://x.test', 'access_code' => 'ac'],
        ])),
    ]);

    redactionManager(['primary' => $driver])->chargeWithFallback(ChargeRequestDTO::fromArray([
        'amount' => 100.00,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => 'order_redact',
        'metadata' => ['card_number' => '4111111111111111'],
    ]));

    expect(storedTraceJson())->not->toContain('4111111111111111')
        ->and(PaymentTraceEvent::where('event', TraceEvent::PROVIDER_REQUEST_SENT->value)->sole()->payload)
        ->toBe([]);
});
