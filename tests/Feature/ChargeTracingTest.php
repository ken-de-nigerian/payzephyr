<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Schema;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Enums\TraceDirection;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Services\TraceTimelineBuilder;
use Psr\Http\Message\StreamInterface;
use Tests\Helpers\PaystackDriverTestHelper;

/**
 * What the trace feature exists for.
 *
 * PayZephyr can silently route a payment to a provider the caller never chose.
 * These assertions are the receipt for that: one reference, every decision in
 * order, and enough context afterwards to answer "why did this happen?"
 * without grepping a log file.
 */
function tracingDriver(string $name, ?Throwable $throws = null, bool $healthy = true, array $currencies = ['NGN']): DriverInterface
{
    return new class($name, $throws, $healthy, $currencies) implements DriverInterface
    {
        public int $chargeCalls = 0;

        public function __construct(
            private readonly string $driverName,
            private readonly ?Throwable $throws,
            private readonly bool $healthy,
            private readonly array $currencies
        ) {}

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            $this->chargeCalls++;

            if ($this->throws !== null) {
                throw $this->throws;
            }

            return new ChargeResponseDTO(
                reference: (string) $request->reference,
                authorizationUrl: 'https://example.test/pay',
                accessCode: 'code_'.$this->driverName,
                status: 'pending',
                provider: $this->driverName,
            );
        }

        public function verify(string $reference): VerificationResponseDTO
        {
            return new VerificationResponseDTO($reference, 'success', 100.0, 'NGN', provider: $this->driverName);
        }

        public function validateWebhook(array $headers, string $body): bool
        {
            return true;
        }

        public function healthCheck(): bool
        {
            return $this->healthy;
        }

        public function getName(): string
        {
            return $this->driverName;
        }

        public function getSupportedCurrencies(): array
        {
            return $this->currencies;
        }

        public function extractWebhookReference(array $payload): ?string
        {
            return null;
        }

        public function extractWebhookStatus(array $payload): string
        {
            return 'success';
        }

        public function extractWebhookChannel(array $payload): ?string
        {
            return null;
        }

        public function resolveVerificationId(string $reference, string $providerId): string
        {
            return $reference;
        }
    };
}

/**
 * @param  array<string, DriverInterface>  $drivers
 */
function tracingManager(array $drivers): PaymentManager
{
    $manager = new PaymentManager;
    $property = (new ReflectionClass($manager))->getProperty('drivers');
    $property->setAccessible(true);
    $property->setValue($manager, $drivers);

    return $manager;
}

/**
 * A manager fronting the real Paystack driver over a mocked transport, so the
 * HTTP-level instrumentation in AbstractDriver::makeRequest() is exercised for
 * real rather than simulated.
 *
 * Health checks are off: they issue their own request, which would consume the
 * queued responses this driver is standing on.
 *
 * @param  array<int, Response>  $responses
 */
function paystackTracingManager(array $responses): PaymentManager
{
    config(['payments.health_check.enabled' => false]);
    app()->forgetInstance('payments.config');

    return tracingManager(['primary' => PaystackDriverTestHelper::createWithMock($responses)]);
}

/**
 * A charge response shaped the way Paystack shapes one.
 */
function paystackChargeResponse(string $accessCode = 'ac', string $url = 'https://x.test', string $reference = 'ref'): Response
{
    return new Response(200, [], (string) json_encode([
        'status' => true,
        'data' => ['reference' => $reference, 'authorization_url' => $url, 'access_code' => $accessCode],
    ]));
}

/**
 * A request carrying a caller-supplied reference.
 *
 * PaystackDriver builds its ChargeResponseDTO from the reference the *provider*
 * echoes back, so a test that wants to look events up afterwards has to pin
 * both ends rather than rely on an auto-generated one it never sees.
 */
function tracingRequestWith(string $reference): ChargeRequestDTO
{
    return ChargeRequestDTO::fromArray([
        'amount' => 100.00,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => $reference,
    ]);
}

function tracingRequest(string $currency = 'NGN'): ChargeRequestDTO
{
    return ChargeRequestDTO::fromArray([
        'amount' => 100.00,
        'currency' => $currency,
        'email' => 'test@example.com',
    ]);
}

/**
 * @return array<int, string>
 */
function recordedEvents(string $reference): array
{
    return PaymentTraceEvent::where('reference', $reference)
        ->orderBy('id')
        ->pluck('event')
        ->map(fn (TraceEvent $event): string => $event->value)
        ->all();
}

beforeEach(function () {
    app()->forgetInstance('payments.config');

    config([
        'payments.features.trace' => true,
        'payments.trace.async' => false,
        'payments.logging.enabled' => false,
        'payments.default' => 'primary',
        'payments.fallback' => 'secondary',
        'payments.health_check.enabled' => true,
        'payments.providers' => [
            'primary' => ['driver' => 'primary', 'enabled' => true, 'secret_key' => 'test'],
            'secondary' => ['driver' => 'secondary', 'enabled' => true, 'secret_key' => 'test'],
        ],
    ]);
});

// ---------------------------------------------------------------------------
// The fallback chain, which is what this whole feature is for
// ---------------------------------------------------------------------------

test('a payment recovered by a fallback provider leaves one readable timeline', function () {
    // The exit criterion for this phase. Before tracing, the only record of
    // this payment was a single row saying "succeeded, via secondary" - with
    // nothing at all to say primary had been tried first, or why it lost.
    $primary = tracingDriver('primary', throws: new ChargeException('provider down'));
    $secondary = tracingDriver('secondary');

    $response = tracingManager(['primary' => $primary, 'secondary' => $secondary])
        ->chargeWithFallback(tracingRequest());

    expect(recordedEvents($response->reference))->toBe([
        'payment.initiated',
        'provider.error',
        'payment.completed',
    ]);

    $errored = PaymentTraceEvent::where('reference', $response->reference)
        ->where('event', TraceEvent::PROVIDER_ERROR->value)->sole();
    $completed = PaymentTraceEvent::where('reference', $response->reference)
        ->where('event', TraceEvent::PAYMENT_COMPLETED->value)->sole();

    expect($errored->provider)->toBe('primary')
        ->and($errored->payload['error'])->toBe('provider down')
        ->and($completed->provider)->toBe('secondary');
});

test('a recovered payment reads as succeeded, not failed', function () {
    // Timeline::terminal() takes the first terminal event, so recording a
    // single provider's failure as PAYMENT_FAILED would report a payment that
    // actually went through as having failed.
    $primary = tracingDriver('primary', throws: new ChargeException('provider down'));
    $secondary = tracingDriver('secondary');

    $response = tracingManager(['primary' => $primary, 'secondary' => $secondary])
        ->chargeWithFallback(tracingRequest());

    $timeline = app(TraceTimelineBuilder::class)->build($response->reference);

    expect($timeline->succeeded())->toBeTrue()
        ->and($timeline->failed())->toBeFalse()
        ->and($timeline->errors())->toHaveCount(1);
});

test('every provider in the chain is recorded under the one reference', function () {
    $primary = tracingDriver('primary', throws: new ChargeException('down'));
    $secondary = tracingDriver('secondary');

    $response = tracingManager(['primary' => $primary, 'secondary' => $secondary])
        ->chargeWithFallback(tracingRequest());

    expect(PaymentTraceEvent::distinct()->pluck('reference')->all())->toBe([$response->reference]);
});

// ---------------------------------------------------------------------------
// Why a provider was never contacted
// ---------------------------------------------------------------------------

test('a provider skipped for failing its health check says so', function () {
    $primary = tracingDriver('primary', healthy: false);
    $secondary = tracingDriver('secondary');

    $response = tracingManager(['primary' => $primary, 'secondary' => $secondary])
        ->chargeWithFallback(tracingRequest());

    $skipped = PaymentTraceEvent::where('event', TraceEvent::PROVIDER_SKIPPED->value)->sole();

    expect($skipped->provider)->toBe('primary')
        ->and($skipped->payload['reason'])->toBe('failed_health_check')
        ->and($primary->chargeCalls)->toBe(0)
        ->and($response->provider)->toBe('secondary');
});

test('a provider skipped for the wrong currency records the currency', function () {
    $primary = tracingDriver('primary', currencies: ['USD']);
    $secondary = tracingDriver('secondary', currencies: ['NGN']);

    tracingManager(['primary' => $primary, 'secondary' => $secondary])
        ->chargeWithFallback(tracingRequest('NGN'));

    $skipped = PaymentTraceEvent::where('event', TraceEvent::PROVIDER_SKIPPED->value)->sole();

    expect($skipped->provider)->toBe('primary')
        ->and($skipped->payload['reason'])->toBe('unsupported_currency')
        ->and($skipped->payload['currency'])->toBe('NGN');
});

// ---------------------------------------------------------------------------
// The outcomes worth waking someone up for
// ---------------------------------------------------------------------------

test('an ambiguous charge outcome is recorded before the chain is abandoned', function () {
    // The single highest-value event in the system: the provider may or may
    // not have taken the customer's money, and PayZephyr refuses to retry.
    // isAmbiguousProviderOutcome() walks the exception chain rather than
    // trusting a flag, so the ambiguity has to be genuine: a request that was
    // transmitted and never answered, which is a RequestException with no
    // response. A ConnectException here would mean "never reached them" and
    // would be safe to fail over.
    $ambiguous = ChargeException::withContext('read timed out', [], new RequestException(
        'read timed out',
        new Request('POST', 'https://api.primary.test/charge'),
    ));

    $manager = tracingManager([
        'primary' => tracingDriver('primary', throws: $ambiguous),
        'secondary' => tracingDriver('secondary'),
    ]);

    expect(fn () => $manager->chargeWithFallback(tracingRequest()))
        ->toThrow(ProviderException::class);

    $recorded = PaymentTraceEvent::where('event', TraceEvent::CHARGE_AMBIGUOUS->value)->sole();

    expect($recorded->provider)->toBe('primary')
        ->and($recorded->event->isTerminal())->toBeTrue()
        ->and($recorded->event->isError())->toBeTrue();
});

test('when every provider fails the payment is recorded as failed once, with the reasons', function () {
    $manager = tracingManager([
        'primary' => tracingDriver('primary', throws: new ChargeException('primary down')),
        'secondary' => tracingDriver('secondary', throws: new ChargeException('secondary down')),
    ]);

    expect(fn () => $manager->chargeWithFallback(tracingRequest()))
        ->toThrow(ProviderException::class);

    $reference = PaymentTraceEvent::query()->value('reference');

    expect(recordedEvents($reference))->toBe([
        'payment.initiated',
        'provider.error',
        'provider.error',
        'payment.failed',
    ]);

    $failed = PaymentTraceEvent::where('event', TraceEvent::PAYMENT_FAILED->value)->sole();

    expect($failed->payload['providers_tried'])->toBe(['primary', 'secondary'])
        ->and($failed->payload['errors'])->toBe([
            'primary' => 'primary down',
            'secondary' => 'secondary down',
        ]);
});

test('a rejected duplicate submission is recorded without claiming the payment failed', function () {
    // Both submissions share a reference and therefore a timeline. Marking the
    // rejection terminal would overwrite the real outcome of the one that won.
    $manager = tracingManager(['primary' => tracingDriver('primary')]);

    $response = $manager->chargeWithFallback(tracingRequest());

    $resubmission = ChargeRequestDTO::fromArray([
        'amount' => 100.00,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => $response->reference,
    ]);

    expect(fn () => $manager->chargeWithFallback($resubmission))->toThrow(ProviderException::class);

    $rejected = PaymentTraceEvent::where('event', TraceEvent::CHARGE_DUPLICATE_REJECTED->value)->sole();
    $timeline = app(TraceTimelineBuilder::class)->build($response->reference);

    expect($rejected->event->isTerminal())->toBeFalse()
        ->and($timeline->succeeded())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Correlation: one group per provider attempt
// ---------------------------------------------------------------------------

test('each provider attempt gets its own correlation group', function () {
    // A reference spans the payment; a correlation id spans one provider
    // round-trip. Without that, a fallback chain is a flat pile of events.
    $response = paystackTracingManager([paystackChargeResponse(reference: 'order_corr')])
        ->chargeWithFallback(tracingRequestWith('order_corr'));

    $http = PaymentTraceEvent::where('reference', $response->reference)
        ->whereIn('event', [
            TraceEvent::PROVIDER_REQUEST_SENT->value,
            TraceEvent::PROVIDER_RESPONSE_RECEIVED->value,
        ])->orderBy('id')->get();

    expect($http)->toHaveCount(2)
        ->and($http[0]->correlation_id)->not->toBeNull()
        ->and($http[1]->correlation_id)->toBe($http[0]->correlation_id);
});

test('the correlation id does not leak into the next payment', function () {
    // PaymentManager caches drivers by name, so a second charge reuses the
    // same object. A leaked correlation id would file one customer's provider
    // round-trip under another customer's attempt.
    $manager = paystackTracingManager([
        paystackChargeResponse(reference: 'order_leak_one'),
        paystackChargeResponse(reference: 'order_leak_two'),
    ]);

    $first = $manager->chargeWithFallback(tracingRequestWith('order_leak_one'));
    $second = $manager->chargeWithFallback(tracingRequestWith('order_leak_two'));

    $firstGroup = PaymentTraceEvent::where('reference', $first->reference)
        ->where('event', TraceEvent::PROVIDER_REQUEST_SENT->value)->sole()->correlation_id;
    $secondGroup = PaymentTraceEvent::where('reference', $second->reference)
        ->where('event', TraceEvent::PROVIDER_REQUEST_SENT->value)->sole()->correlation_id;

    expect($firstGroup)->not->toBeNull()
        ->and($secondGroup)->not->toBe($firstGroup);
});

// ---------------------------------------------------------------------------
// The HTTP round trip, recorded once for all eight drivers
// ---------------------------------------------------------------------------

test('the provider round trip is recorded with its method, url, status and timing', function () {
    $response = paystackTracingManager([paystackChargeResponse()])->chargeWithFallback(tracingRequest());

    $sent = PaymentTraceEvent::where('event', TraceEvent::PROVIDER_REQUEST_SENT->value)->sole();
    $received = PaymentTraceEvent::where('event', TraceEvent::PROVIDER_RESPONSE_RECEIVED->value)->sole();

    expect($sent->direction)->toBe(TraceDirection::OUTBOUND)
        ->and($sent->http_method)->toBe('POST')
        ->and($sent->http_url)->toContain('/transaction/initialize')
        ->and($sent->payload['email'])->toBe('test@example.com')
        ->and($received->direction)->toBe(TraceDirection::INBOUND)
        ->and($received->http_status_code)->toBe(200)
        ->and($received->response_time_ms)->toBeGreaterThanOrEqual(0)
        ->and($received->payload['data']['access_code'])->toBe('ac')
        ->and($response->accessCode)->toBe('ac');
});

test('reading the response body for the timeline does not consume it for the driver', function () {
    // peekResponseBody() rewinds, because parseResponse() reads the same
    // stream immediately afterwards. Getting this wrong breaks every charge.
    $response = paystackTracingManager([paystackChargeResponse('ac_123', 'https://paystack.test/pay')])->chargeWithFallback(tracingRequest());

    expect($response->authorizationUrl)->toBe('https://paystack.test/pay')
        ->and($response->accessCode)->toBe('ac_123');
});

test('with body capture off the round trip is still recorded, without the bodies', function () {
    config(['payments.trace.record_http_bodies' => false]);
    app()->forgetInstance('payments.config');

    paystackTracingManager([paystackChargeResponse()])->chargeWithFallback(tracingRequest());

    $sent = PaymentTraceEvent::where('event', TraceEvent::PROVIDER_REQUEST_SENT->value)->sole();
    $received = PaymentTraceEvent::where('event', TraceEvent::PROVIDER_RESPONSE_RECEIVED->value)->sole();

    expect($sent->payload)->toBe([])
        ->and($received->payload)->toBe([])
        ->and($received->http_status_code)->toBe(200);
});

// ---------------------------------------------------------------------------
// Tracing is never allowed to matter to the payment
// ---------------------------------------------------------------------------

test('with tracing off a charge behaves exactly as before and records nothing', function () {
    config(['payments.features.trace' => false]);
    app()->forgetInstance('payments.config');

    $response = tracingManager([
        'primary' => tracingDriver('primary', throws: new ChargeException('down')),
        'secondary' => tracingDriver('secondary'),
    ])->chargeWithFallback(tracingRequest());

    expect($response->provider)->toBe('secondary')
        ->and(PaymentTraceEvent::count())->toBe(0);
});

test('a charge still completes when the trace table was never migrated', function () {
    // The end-to-end half of the fail-safe promise, which Phase 3 could only
    // prove at the recorder. The realistic case: PAYZEPHYR_FEATURE_TRACE=true
    // before `payzephyr:install --features=trace` has been run.
    Schema::drop('payment_trace_events');

    $response = tracingManager([
        'primary' => tracingDriver('primary', throws: new ChargeException('down')),
        'secondary' => tracingDriver('secondary'),
    ])->chargeWithFallback(tracingRequest());

    expect($response->provider)->toBe('secondary')
        ->and($response->accessCode)->toBe('code_secondary');
});

// ---------------------------------------------------------------------------
// Classifying a failed round trip
// ---------------------------------------------------------------------------

test('a connect timeout is recorded as a timeout', function () {
    $manager = paystackTracingManager([
        new ConnectException('cURL error 28: Operation timed out after 30000 ms', new Request('POST', 'https://api.paystack.co')),
    ]);

    expect(fn () => $manager->chargeWithFallback(tracingRequestWith('order_timeout')))
        ->toThrow(ProviderException::class);

    expect(PaymentTraceEvent::where('event', TraceEvent::PROVIDER_TIMEOUT->value)->exists())->toBeTrue();
});

test('a refused connection is not recorded as a timeout', function () {
    // Connection refused, DNS failure and connect timeouts all arrive as
    // ConnectException. Calling them all timeouts sends whoever reads the
    // timeline looking for a slow provider when the answer is a bad host.
    $manager = paystackTracingManager([
        new ConnectException('cURL error 7: Failed to connect to api.paystack.co port 443: Connection refused', new Request('POST', 'https://api.paystack.co')),
    ]);

    expect(fn () => $manager->chargeWithFallback(tracingRequestWith('order_refused')))
        ->toThrow(ProviderException::class);

    expect(PaymentTraceEvent::where('event', TraceEvent::PROVIDER_EXCEPTION->value)->exists())->toBeTrue()
        ->and(PaymentTraceEvent::where('event', TraceEvent::PROVIDER_TIMEOUT->value)->exists())->toBeFalse();
});

test('a provider error response is recorded with its status code', function () {
    $manager = paystackTracingManager([
        new RequestException(
            'Unauthorized',
            new Request('POST', 'https://api.paystack.co'),
            new Response(401, [], (string) json_encode(['status' => false, 'message' => 'Invalid key'])),
        ),
    ]);

    expect(fn () => $manager->chargeWithFallback(tracingRequestWith('order_denied')))
        ->toThrow(ProviderException::class);

    $recorded = PaymentTraceEvent::where('event', TraceEvent::PROVIDER_ERROR->value)
        ->whereNotNull('http_status_code')->sole();

    expect($recorded->http_status_code)->toBe(401)
        ->and($recorded->provider)->toBe('paystack');
});

test('a response body that cannot be rewound costs the timeline its payload and nothing else', function () {
    // Draining a non-seekable stream to describe the charge would break the
    // charge: parseResponse() reads the same stream immediately afterwards.
    $body = json_encode(['status' => true, 'data' => [
        'reference' => 'order_nonseek', 'authorization_url' => 'https://x.test', 'access_code' => 'ac',
    ]]);

    $manager = paystackTracingManager([
        new Response(200, [], new NoSeekStream(Utils::streamFor((string) $body))),
    ]);

    $response = $manager->chargeWithFallback(tracingRequestWith('order_nonseek'));

    $received = PaymentTraceEvent::where('event', TraceEvent::PROVIDER_RESPONSE_RECEIVED->value)->sole();

    expect($response->accessCode)->toBe('ac')
        ->and($received->payload)->toBe([])
        ->and($received->http_status_code)->toBe(200);
});

test('a body that explodes while being read costs the timeline its payload and nothing else', function () {
    // peekResponseBody() runs on the payment path. A stream that claims to be
    // seekable and then misbehaves must not be able to fail the charge it is
    // only there to describe.
    $hostile = new class implements StreamInterface
    {
        public function __toString(): string
        {
            throw new RuntimeException('stream exploded');
        }

        public function isSeekable(): bool
        {
            return true;
        }

        public function getContents(): string
        {
            return '{"status":true,"data":{"reference":"order_hostile","authorization_url":"https://x.test","access_code":"ac"}}';
        }

        public function close(): void {}

        public function detach() {}

        public function getSize(): ?int
        {
            return null;
        }

        public function tell(): int
        {
            return 0;
        }

        public function eof(): bool
        {
            return false;
        }

        public function seek(int $offset, int $whence = SEEK_SET): void {}

        public function rewind(): void {}

        public function isWritable(): bool
        {
            return false;
        }

        public function write(string $string): int
        {
            return 0;
        }

        public function isReadable(): bool
        {
            return true;
        }

        public function read(int $length): string
        {
            return '';
        }

        public function getMetadata(?string $key = null)
        {
            return null;
        }
    };

    $manager = paystackTracingManager([new Response(200, [], $hostile)]);

    expect(fn () => $manager->chargeWithFallback(tracingRequestWith('order_hostile')))
        ->toThrow(ProviderException::class);

    // The round trip is still on the timeline, just without a body.
    $received = PaymentTraceEvent::where('event', TraceEvent::PROVIDER_RESPONSE_RECEIVED->value)->sole();

    expect($received->payload)->toBe([])
        ->and($received->http_status_code)->toBe(200);
});
