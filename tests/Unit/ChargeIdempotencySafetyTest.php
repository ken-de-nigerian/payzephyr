<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;
use KenDeNigerian\PayZephyr\PaymentManager;

/**
 * Charge-side double-submission and idempotency protection.
 *
 * The invariant under test: when PayZephyr has enough information to know two
 * submissions are the same logical payment (the caller supplied a stable
 * reference), at most one of them may reach a provider.
 */

/**
 * A driver whose charge() outcome is scriptable, and which counts how many
 * times it was actually called.
 */
function makeScriptableDriver(string $name, ?Throwable $throws = null, ?Closure $onCharge = null): DriverInterface
{
    return new class($name, $throws, $onCharge) implements DriverInterface
    {
        public int $chargeCalls = 0;

        public function __construct(
            private readonly string $driverName,
            private readonly ?Throwable $throws,
            private readonly ?Closure $onCharge
        ) {}

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            $this->chargeCalls++;

            if ($this->onCharge !== null) {
                ($this->onCharge)($request);
            }

            if ($this->throws !== null) {
                throw $this->throws;
            }

            return new ChargeResponseDTO(
                reference: $request->reference ?? 'ref_'.$this->driverName,
                authorizationUrl: 'https://example.test/pay',
                accessCode: 'code_'.$this->driverName,
                status: 'pending',
                provider: $this->driverName,
            );
        }

        public function verify(string $reference): VerificationResponseDTO
        {
            return new VerificationResponseDTO(
                reference: $reference,
                status: 'success',
                amount: 100.0,
                currency: 'NGN',
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

        public function getCachedHealthCheck(): bool
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

        public function isCurrencySupported(string $currency): bool
        {
            return true;
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

function makeManagerWithDrivers(array $drivers): PaymentManager
{
    $manager = new PaymentManager;
    $reflection = new ReflectionClass($manager);
    $property = $reflection->getProperty('drivers');
    $property->setAccessible(true);
    $property->setValue($manager, $drivers);

    return $manager;
}

function chargeRequestWithReference(?string $reference): ChargeRequestDTO
{
    return ChargeRequestDTO::fromArray(array_filter([
        'amount' => 100.00,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => $reference,
    ], fn ($v) => $v !== null));
}

beforeEach(function () {
    app()->forgetInstance('payments.config');
    Cache::flush();

    config([
        'payments.default' => 'primary',
        'payments.fallback' => 'secondary',
        'payments.health_check.enabled' => false,
        'payments.providers' => [
            'primary' => ['driver' => 'primary', 'enabled' => true, 'secret_key' => 'test'],
            'secondary' => ['driver' => 'secondary', 'enabled' => true, 'secret_key' => 'test'],
        ],
    ]);
});

// ---------------------------------------------------------------------------
// Idempotency key identity
// ---------------------------------------------------------------------------

test('a caller-supplied reference produces a stable idempotency key across submissions', function () {
    // Regression: fromArray() used to mint a fresh random UUID on every call
    // even when the caller supplied the same reference, so a retry of the same
    // logical payment reached the provider under a *different* idempotency
    // key - defeating provider-side deduplication in exactly the case it
    // exists to protect.
    $first = chargeRequestWithReference('order_12345');
    $second = chargeRequestWithReference('order_12345');

    expect($first->idempotencyKey)->toBe($second->idempotencyKey)
        ->and($first->idempotencyKey)->toBe('order_12345');
});

test('two different references produce different idempotency keys', function () {
    expect(chargeRequestWithReference('order_a')->idempotencyKey)
        ->not->toBe(chargeRequestWithReference('order_b')->idempotencyKey);
});

test('an explicit idempotency key always overrides the reference-derived one', function () {
    $request = ChargeRequestDTO::fromArray([
        'amount' => 100.00,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => 'order_12345',
        'idempotency_key' => 'explicit_key_1',
    ]);

    expect($request->idempotencyKey)->toBe('explicit_key_1');
});

test('with no reference and no explicit key, each submission gets a distinct key', function () {
    // This is the honest limitation, asserted rather than glossed over:
    // nothing identifies these two submissions as the same logical payment,
    // so PayZephyr cannot and does not deduplicate them.
    expect(chargeRequestWithReference(null)->idempotencyKey)
        ->not->toBe(chargeRequestWithReference(null)->idempotencyKey);
});

// ---------------------------------------------------------------------------
// Concurrent / repeated submission
// ---------------------------------------------------------------------------

test('a second submission arriving while the first is still in flight never reaches a provider', function () {
    // Exercises the real race window: the second submission is issued from
    // *inside* the first submission's provider call, before the first has
    // returned and before any transaction row exists for it. This is the
    // precise interleaving a genuine two-process race produces.
    $manager = null;
    $racingOutcome = null;

    $primary = makeScriptableDriver('primary', onCharge: function () use (&$manager, &$racingOutcome) {
        try {
            $manager->chargeWithFallback(chargeRequestWithReference('order_race'));
            $racingOutcome = 'CHARGED AGAIN';
        } catch (Throwable $e) {
            $racingOutcome = $e;
        }
    });
    $secondary = makeScriptableDriver('secondary');

    $manager = makeManagerWithDrivers(['primary' => $primary, 'secondary' => $secondary]);

    $response = $manager->chargeWithFallback(chargeRequestWithReference('order_race'));

    expect($racingOutcome)->toBeInstanceOf(ProviderException::class)
        ->and($racingOutcome->getMessage())->toContain('already in progress')
        ->and($racingOutcome->getContext()['duplicate_submission'])->toBeTrue()
        ->and($primary->chargeCalls)->toBe(1)
        ->and($secondary->chargeCalls)->toBe(0)
        ->and($response->reference)->toBe('order_race');
});

test('a repeat submission after a successful charge does not charge again', function () {
    $primary = makeScriptableDriver('primary');
    $secondary = makeScriptableDriver('secondary');
    $manager = makeManagerWithDrivers(['primary' => $primary, 'secondary' => $secondary]);

    $manager->chargeWithFallback(chargeRequestWithReference('order_repeat'));

    expect(fn () => $manager->chargeWithFallback(chargeRequestWithReference('order_repeat')))
        ->toThrow(ProviderException::class);

    expect($primary->chargeCalls)->toBe(1)
        ->and($secondary->chargeCalls)->toBe(0);
});

test('a definitive failure releases the claim so a legitimate retry can proceed', function () {
    // A charge that definitively did not happen must not leave the payment
    // permanently unchargeable.
    $failing = makeScriptableDriver('primary', throws: new ChargeException('card declined'));
    $manager = makeManagerWithDrivers(['primary' => $failing]);

    expect(fn () => $manager->chargeWithFallback(chargeRequestWithReference('order_retry')))
        ->toThrow(ProviderException::class);

    $succeeding = makeScriptableDriver('primary');
    $manager2 = makeManagerWithDrivers(['primary' => $succeeding]);

    $response = $manager2->chargeWithFallback(chargeRequestWithReference('order_retry'));

    expect($response->reference)->toBe('order_retry')
        ->and($succeeding->chargeCalls)->toBe(1);
});

test('an ambiguous outcome keeps the claim so a retry cannot double-charge', function () {
    // The provider may already have processed this charge. Unlike a definitive
    // failure, the claim is deliberately NOT released - a retry would risk
    // charging the customer twice.
    $ambiguous = new ChargeException(
        'connection timed out',
        0,
        new GuzzleHttp\Exception\RequestException(
            'timeout',
            new GuzzleHttp\Psr7\Request('POST', '/charge')
        )
    );

    $primary = makeScriptableDriver('primary', throws: $ambiguous);
    $secondary = makeScriptableDriver('secondary');
    $manager = makeManagerWithDrivers(['primary' => $primary, 'secondary' => $secondary]);

    expect(fn () => $manager->chargeWithFallback(chargeRequestWithReference('order_ambiguous')))
        ->toThrow(ProviderException::class);

    // The fallback provider must not have been tried for an ambiguous outcome.
    expect($secondary->chargeCalls)->toBe(0);

    // And a retry of the same logical payment must still be refused.
    $retryPrimary = makeScriptableDriver('primary');
    $manager2 = makeManagerWithDrivers(['primary' => $retryPrimary]);

    expect(fn () => $manager2->chargeWithFallback(chargeRequestWithReference('order_ambiguous')))
        ->toThrow(ProviderException::class);

    expect($retryPrimary->chargeCalls)->toBe(0);
});

test('charges without a reference are not blocked by each other', function () {
    // No stable identity means no protection is possible - but it also must
    // not produce false positives that block genuinely different payments.
    $primary = makeScriptableDriver('primary');
    $manager = makeManagerWithDrivers(['primary' => $primary]);

    $manager->chargeWithFallback(chargeRequestWithReference(null));
    $manager->chargeWithFallback(chargeRequestWithReference(null));

    expect($primary->chargeCalls)->toBe(2);
});

test('different references do not block each other', function () {
    $primary = makeScriptableDriver('primary');
    $manager = makeManagerWithDrivers(['primary' => $primary]);

    $manager->chargeWithFallback(chargeRequestWithReference('order_one'));
    $manager->chargeWithFallback(chargeRequestWithReference('order_two'));

    expect($primary->chargeCalls)->toBe(2);
});
