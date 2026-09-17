<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;
use KenDeNigerian\PayZephyr\PaymentManager;

/**
 * The guards around the in-flight claim, exercised rather than assumed.
 *
 * Every branch here exists so that some piece of PayZephyr's own bookkeeping
 * failing cannot change what the caller is told about a real payment. They are
 * the last places where a broken cache or a broken logger could still turn a
 * successful charge into a reported failure, or an unsafe retry into a
 * permitted one.
 */
/**
 * Defined here rather than reused from another test file: Pest only makes
 * cross-file helpers visible during a full-suite run, so a single-file run of
 * this file would not resolve one.
 */
function claimTestDriver(string $name, ?Throwable $throws = null): DriverInterface
{
    return new class($name, $throws) implements DriverInterface
    {
        public function __construct(
            private readonly string $driverName,
            private readonly ?Throwable $throws
        ) {}

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
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

function claimTestRequest(string $reference): ChargeRequestDTO
{
    return ChargeRequestDTO::fromArray([
        'amount' => 100.00,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => $reference,
    ]);
}

function claimTestManager(array $drivers): PaymentManager
{
    $manager = new PaymentManager;
    $property = (new ReflectionClass($manager))->getProperty('drivers');
    $property->setAccessible(true);
    $property->setValue($manager, $drivers);

    return $manager;
}

beforeEach(function () {
    app()->forgetInstance('payments.config');
    Cache::flush();

    config([
        'payments.default' => 'primary',
        'payments.fallback' => 'secondary',
        'payments.health_check.enabled' => false,
        'payments.logging.enabled' => false,
        'payments.features.trace' => false,
        'payments.providers' => [
            'primary' => ['driver' => 'primary', 'enabled' => true, 'secret_key' => 'test'],
            'secondary' => ['driver' => 'secondary', 'enabled' => true, 'secret_key' => 'test'],
        ],
    ]);
});

test('a cache that cannot release the claim does not replace the real failure', function () {
    // The caller asked why the charge failed. "Could not forget a cache key"
    // is PayZephyr talking about itself, and would bury the provider's answer.
    $manager = claimTestManager([
        'primary' => claimTestDriver('primary', throws: new ChargeException('card declined')),
    ]);

    Cache::shouldReceive('add')->andReturnTrue();
    Cache::shouldReceive('forget')->andThrow(new RuntimeException('cache backend unreachable'));

    $logged = [];
    Log::listen(function (MessageLogged $message) use (&$logged) {
        $logged[] = $message;
    });

    expect(fn () => $manager->chargeWithFallback(claimTestRequest('order_release_fault')))
        ->toThrow(ProviderException::class, 'All payment providers failed');

    $entry = collect($logged)->firstWhere('message', 'Failed to release charge in-flight claim');

    expect($entry)->not->toBeNull()
        ->and($entry->level)->toBe('warning')
        ->and($entry->context['error'])->toBe('cache backend unreachable');
});

test('an ambiguous outcome keeps its claim, so nothing can retry it', function () {
    // The single most safety-critical branch in the class. Releasing the claim
    // here would reopen the reference for a retry against a provider that may
    // already have taken the customer's money.
    $ambiguous = ChargeException::withContext('read timed out', [], new GuzzleHttp\Exception\RequestException(
        'read timed out',
        new GuzzleHttp\Psr7\Request('POST', 'https://api.primary.test/charge'),
    ));

    $manager = claimTestManager([
        'primary' => claimTestDriver('primary', throws: $ambiguous),
    ]);

    expect(fn () => $manager->chargeWithFallback(claimTestRequest('order_ambiguous_claim')))
        ->toThrow(ProviderException::class);

    // The claim is still held, so a second submission is turned away rather
    // than sent to a provider.
    $second = claimTestManager([
        'primary' => claimTestDriver('primary'),
    ]);

    expect(fn () => $second->chargeWithFallback(claimTestRequest('order_ambiguous_claim')))
        ->toThrow(ProviderException::class, 'already in progress');
});

test('a definitive failure releases its claim, so a legitimate retry is allowed', function () {
    // The other side of the same decision, asserted alongside it: a charge
    // that provably did not happen must not leave its reference unusable.
    $manager = claimTestManager([
        'primary' => claimTestDriver('primary', throws: new ChargeException('card declined')),
    ]);

    expect(fn () => $manager->chargeWithFallback(claimTestRequest('order_definitive')))
        ->toThrow(ProviderException::class);

    $retry = claimTestManager(['primary' => claimTestDriver('primary')]);

    expect($retry->chargeWithFallback(claimTestRequest('order_definitive'))->reference)
        ->toBe('order_definitive');
});

test('a logger that fails after a successful charge still reports the charge as successful', function () {
    // The last guard in completeSuccessfulCharge(). The money has moved; the
    // caller must hear that, whatever state PayZephyr's logging is in.
    //
    // Only the success line is broken, rather than the whole facade. Breaking
    // Log::channel() outright takes down getCacheContext() first, which logs
    // from inside its own catch and is reached before any charge happens - so
    // a blunter mock would never get as far as the guard under test.
    $manager = claimTestManager(['primary' => claimTestDriver('primary')]);

    $channel = Mockery::mock();
    $channel->shouldReceive('info')->andThrow(new RuntimeException('logging is down'));
    $channel->shouldReceive('debug', 'warning', 'error')->andReturnNull();
    Log::shouldReceive('channel')->andReturn($channel);

    $response = $manager->chargeWithFallback(claimTestRequest('order_logger_down'));

    expect($response->reference)->toBe('order_logger_down')
        ->and($response->provider)->toBe('primary');
});

test('a raw ambiguous ChargeException is recognised even unwrapped', function () {
    // Reached by reflection on purpose. attemptChargeChain() always rewraps an
    // ambiguous ChargeException as a ProviderException before chargeWithFallback
    // sees it, so this branch is unreachable from the only current caller.
    //
    // It is not dead weight: the whole method answers "is retrying unsafe?",
    // and a future caller handed the unwrapped exception must get the same
    // answer. Saying "safe to retry" here would permit a double charge.
    $ambiguous = ChargeException::withContext('read timed out', [], new GuzzleHttp\Exception\RequestException(
        'read timed out',
        new GuzzleHttp\Psr7\Request('POST', 'https://api.primary.test/charge'),
    ));

    $manager = claimTestManager(['primary' => claimTestDriver('primary')]);

    $isAmbiguous = (new ReflectionClass($manager))->getMethod('isAmbiguousOutcome');

    expect($isAmbiguous->invoke($manager, $ambiguous))->toBeTrue()
        ->and($isAmbiguous->invoke($manager, new ChargeException('card declined')))->toBeFalse();
});
