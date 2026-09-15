<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Schema;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Enums\TraceDirection;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Services\TraceTimelineBuilder;
use Tests\Helpers\PaystackDriverTestHelper;

/**
 * The verification half of a payment's timeline.
 *
 * Two things this makes answerable. First, the redirect-versus-webhook race
 * the README describes: both paths now land under one reference with real
 * timestamps, so which arrived first stops being a guess. Second, the quiet
 * failure - the provider confirms a payment and the local row does not get
 * updated - which previously produced a reconciliation mismatch with nothing
 * anywhere to explain it.
 */
function verifyingDriver(string $name, string $status = 'success', ?Throwable $throws = null): DriverInterface
{
    return new class($name, $status, $throws) implements DriverInterface
    {
        public int $verifyCalls = 0;

        public function __construct(
            private readonly string $driverName,
            private readonly string $status,
            private readonly ?Throwable $throws
        ) {}

        public function verify(string $reference): VerificationResponseDTO
        {
            $this->verifyCalls++;

            if ($this->throws !== null) {
                throw $this->throws;
            }

            return new VerificationResponseDTO(
                reference: $reference,
                status: $this->status,
                amount: 100.0,
                currency: 'NGN',
                channel: 'card',
                provider: $this->driverName,
            );
        }

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            return new ChargeResponseDTO(
                reference: (string) $request->reference,
                authorizationUrl: 'https://example.test/pay',
                accessCode: 'code_'.$this->driverName,
                status: 'pending',
                provider: $this->driverName,
            );
        }

        public function validateWebhook(array $headers, string $body): bool
        {
            return true;
        }

        public function healthCheck(): bool
        {
            return true;
        }

        public function getName(): string
        {
            return $this->driverName;
        }

        public function getSupportedCurrencies(): array
        {
            return ['NGN'];
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
function verifyingManager(array $drivers): PaymentManager
{
    $manager = new PaymentManager;
    $property = (new ReflectionClass($manager))->getProperty('drivers');
    $property->setAccessible(true);
    $property->setValue($manager, $drivers);

    return $manager;
}

/**
 * @return array<int, string>
 */
function verifyTraceEvents(string $reference): array
{
    return PaymentTraceEvent::where('reference', $reference)->orderBy('id')
        ->pluck('event')->map(fn (TraceEvent $e): string => $e->value)->all();
}

beforeEach(function () {
    app()->forgetInstance('payments.config');

    config([
        'payments.features.trace' => true,
        'payments.trace.async' => false,
        'payments.logging.enabled' => false,
        'payments.default' => 'primary',
        'payments.health_check.enabled' => false,
        'payments.providers' => [
            'primary' => ['driver' => 'primary', 'enabled' => true, 'secret_key' => 'test'],
            'secondary' => ['driver' => 'secondary', 'enabled' => true, 'secret_key' => 'test'],
        ],
    ]);
});

// ---------------------------------------------------------------------------
// Asking, and getting an answer
// ---------------------------------------------------------------------------

test('a verification records that it started and how it ended', function () {
    verifyingManager(['primary' => verifyingDriver('primary')])
        ->verify('PZ_1755000000_abcdef01', 'primary');

    expect(verifyTraceEvents('PZ_1755000000_abcdef01'))
        ->toBe(['verification.started', 'verification.completed']);

    $completed = PaymentTraceEvent::where('event', TraceEvent::VERIFICATION_COMPLETED->value)->sole();

    expect($completed->provider)->toBe('primary')
        ->and($completed->payload['status'])->toBe('success')
        ->and($completed->payload['channel'])->toBe('card');
});

test('completed means a definitive answer, not necessarily a successful payment', function () {
    // A provider saying "this payment failed" is a verification that worked.
    // Recording it as verification.failed would conflate the two.
    verifyingManager(['primary' => verifyingDriver('primary', status: 'failed')])
        ->verify('PZ_1755000000_abcdef01', 'primary');

    $completed = PaymentTraceEvent::where('event', TraceEvent::VERIFICATION_COMPLETED->value)->sole();

    expect($completed->payload['status'])->toBe('failed')
        ->and($completed->event->isError())->toBeFalse();
});

test('the providers about to be tried are recorded, including the slow fallthrough', function () {
    // With no cached session, no transaction row and no explicit provider,
    // verify() asks every enabled provider in turn. That is the cost of a
    // provider-neutral reference, and it is worth being able to see it.
    verifyingManager([
        'primary' => verifyingDriver('primary', throws: new RuntimeException('not mine')),
        'secondary' => verifyingDriver('secondary'),
    ])->verify('PZ_1755000000_abcdef01');

    $started = PaymentTraceEvent::where('event', TraceEvent::VERIFICATION_STARTED->value)->sole();

    expect($started->payload['providers_to_try'])->toBe(['primary', 'secondary'])
        ->and($started->payload['provider_was_explicit'])->toBeFalse();
});

test('an explicit provider is recorded as such', function () {
    verifyingManager(['primary' => verifyingDriver('primary')])
        ->verify('PZ_1755000000_abcdef01', 'primary');

    $started = PaymentTraceEvent::where('event', TraceEvent::VERIFICATION_STARTED->value)->sole();

    expect($started->payload['provider_was_explicit'])->toBeTrue()
        ->and($started->payload['providers_to_try'])->toBe(['primary']);
});

// ---------------------------------------------------------------------------
// Not getting an answer
// ---------------------------------------------------------------------------

test('one provider failing to answer is not the verification failing', function () {
    // Same split the charge chain uses: PROVIDER_ERROR per provider,
    // VERIFICATION_FAILED only once nobody could answer.
    verifyingManager([
        'primary' => verifyingDriver('primary', throws: new RuntimeException('not mine')),
        'secondary' => verifyingDriver('secondary'),
    ])->verify('PZ_1755000000_abcdef01');

    expect(verifyTraceEvents('PZ_1755000000_abcdef01'))->toBe([
        'verification.started',
        'provider.error',
        'verification.completed',
    ]);

    $errored = PaymentTraceEvent::where('event', TraceEvent::PROVIDER_ERROR->value)->sole();

    expect($errored->provider)->toBe('primary')
        ->and($errored->payload['during'])->toBe('verification');
});

test('a verification nobody could answer is recorded once, with every reason', function () {
    $manager = verifyingManager([
        'primary' => verifyingDriver('primary', throws: new RuntimeException('primary down')),
        'secondary' => verifyingDriver('secondary', throws: new RuntimeException('secondary down')),
    ]);

    expect(fn () => $manager->verify('PZ_1755000000_abcdef01'))->toThrow(ProviderException::class);

    expect(verifyTraceEvents('PZ_1755000000_abcdef01'))->toBe([
        'verification.started',
        'provider.error',
        'provider.error',
        'verification.failed',
    ]);

    $failed = PaymentTraceEvent::where('event', TraceEvent::VERIFICATION_FAILED->value)->sole();

    expect($failed->payload['providers_tried'])->toBe(['primary', 'secondary'])
        ->and($failed->payload['errors'])->toBe([
            'primary' => 'primary down',
            'secondary' => 'secondary down',
        ]);
});

test('a failed verification does not mark the payment terminal', function () {
    // The payment may still be fine; PayZephyr just could not ask about it.
    $manager = verifyingManager([
        'primary' => verifyingDriver('primary', throws: new RuntimeException('down')),
    ]);

    expect(fn () => $manager->verify('PZ_1755000000_abcdef01', 'primary'))->toThrow(ProviderException::class);

    $timeline = app(TraceTimelineBuilder::class)->build('PZ_1755000000_abcdef01');

    expect($timeline->terminal())->toBeNull()
        ->and($timeline->succeeded())->toBeFalse()
        ->and($timeline->failed())->toBeFalse()
        ->and($timeline->errors())->toHaveCount(2);
});

// ---------------------------------------------------------------------------
// The quiet failure
// ---------------------------------------------------------------------------

test('a verification the provider confirmed but the database refused is recorded', function () {
    // The caller is about to be told this payment succeeded while
    // payment_transactions still says otherwise. Without this the divergence
    // surfaces weeks later as a reconciliation mismatch with no explanation.
    config(['payments.logging.enabled' => true]);
    app()->forgetInstance('payments.config');

    $repository = Mockery::mock(TransactionRepositoryInterface::class);
    $repository->shouldReceive('findByReference')->andReturnNull();
    $repository->shouldReceive('updateIfNotSuccessful')->andThrow(new RuntimeException('deadlock'));
    app()->instance(TransactionRepositoryInterface::class, $repository);

    $response = verifyingManager(['primary' => verifyingDriver('primary')])
        ->verify('PZ_1755000000_abcdef01', 'primary');

    $notPersisted = PaymentTraceEvent::where('event', TraceEvent::VERIFICATION_NOT_PERSISTED->value)->sole();

    expect($response->status)->toBe('success')
        ->and($notPersisted->payload['error'])->toBe('deadlock')
        ->and($notPersisted->payload['provider_status'])->toBe('success')
        ->and($notPersisted->event->isError())->toBeTrue()
        ->and($notPersisted->event->isTerminal())->toBeFalse();
});

// ---------------------------------------------------------------------------
// The provider round trip during a verify
// ---------------------------------------------------------------------------

test('the provider call made during a verification is on the timeline', function () {
    // A verification has no ChargeRequestDTO, so AbstractDriver cannot recover
    // the reference from $currentRequest the way it does during a charge.
    // Without the explicit trace context these round trips went unrecorded.
    config(['payments.health_check.enabled' => false]);
    app()->forgetInstance('payments.config');

    $driver = PaystackDriverTestHelper::createWithMock([
        new Response(200, [], (string) json_encode([
            'status' => true,
            'data' => ['reference' => 'order_verify', 'status' => 'success', 'amount' => 10000, 'currency' => 'NGN'],
        ])),
    ]);

    verifyingManager(['primary' => $driver])->verify('order_verify', 'primary');

    $sent = PaymentTraceEvent::where('event', TraceEvent::PROVIDER_REQUEST_SENT->value)->sole();
    $received = PaymentTraceEvent::where('event', TraceEvent::PROVIDER_RESPONSE_RECEIVED->value)->sole();

    expect($sent->reference)->toBe('order_verify')
        ->and($sent->direction)->toBe(TraceDirection::OUTBOUND)
        ->and($sent->http_method)->toBe('GET')
        ->and($sent->http_url)->toContain('order_verify')
        ->and($received->http_status_code)->toBe(200)
        ->and($received->correlation_id)->toBe($sent->correlation_id);
});

test('the verify trace context does not leak into the next operation', function () {
    config(['payments.health_check.enabled' => false]);
    app()->forgetInstance('payments.config');

    $responses = [
        new Response(200, [], (string) json_encode([
            'status' => true,
            'data' => ['reference' => 'order_one', 'status' => 'success', 'amount' => 10000, 'currency' => 'NGN'],
        ])),
        new Response(200, [], (string) json_encode([
            'status' => true,
            'data' => ['reference' => 'order_two', 'status' => 'success', 'amount' => 10000, 'currency' => 'NGN'],
        ])),
    ];

    $manager = verifyingManager(['primary' => PaystackDriverTestHelper::createWithMock($responses)]);

    $manager->verify('order_one', 'primary');
    $manager->verify('order_two', 'primary');

    $first = PaymentTraceEvent::where('reference', 'order_one')
        ->where('event', TraceEvent::PROVIDER_REQUEST_SENT->value)->sole();
    $second = PaymentTraceEvent::where('reference', 'order_two')
        ->where('event', TraceEvent::PROVIDER_REQUEST_SENT->value)->sole();

    expect($second->correlation_id)->not->toBe($first->correlation_id)
        ->and(PaymentTraceEvent::where('reference', 'order_one')->count())->toBe(4);
});

// ---------------------------------------------------------------------------
// One payment, one timeline
// ---------------------------------------------------------------------------

test('a charge and its later verification share one timeline', function () {
    // The point of keying everything by reference: the redirect path and the
    // charge that preceded it are readable together, in order.
    $manager = verifyingManager(['primary' => verifyingDriver('primary')]);

    $charge = $manager->chargeWithFallback(ChargeRequestDTO::fromArray([
        'amount' => 100.00,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => 'order_one_timeline',
    ]));

    $manager->verify($charge->reference, 'primary');

    expect(verifyTraceEvents('order_one_timeline'))->toBe([
        'payment.initiated',
        'payment.completed',
        'verification.started',
        'verification.completed',
    ]);
});

// ---------------------------------------------------------------------------
// Tracing never matters to the verification
// ---------------------------------------------------------------------------

test('with tracing off a verification behaves as before and records nothing', function () {
    config(['payments.features.trace' => false]);
    app()->forgetInstance('payments.config');

    $response = verifyingManager(['primary' => verifyingDriver('primary')])
        ->verify('PZ_1755000000_abcdef01', 'primary');

    expect($response->status)->toBe('success')
        ->and(PaymentTraceEvent::count())->toBe(0);
});

test('a verification still succeeds when the trace table was never migrated', function () {
    Schema::drop('payment_trace_events');

    $response = verifyingManager(['primary' => verifyingDriver('primary')])
        ->verify('PZ_1755000000_abcdef01', 'primary');

    expect($response->status)->toBe('success')
        ->and(Schema::hasTable('payment_trace_events'))->toBeFalse();
});
