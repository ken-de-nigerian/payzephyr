<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use KenDeNigerian\PayZephyr\Constants\PaymentConstants;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;
use KenDeNigerian\PayZephyr\PaymentManager;

/**
 * Charge reference stability across the fallback chain.
 *
 * The invariant under test: one call to chargeWithFallback() is one payment,
 * and it has exactly one reference - whoever ends up fulfilling it, and
 * whether or not the caller supplied one.
 *
 * Before this, every driver resolved its own reference inside charge() as
 * `$request->reference ?? $this->generateReference(...)`. With no
 * caller-supplied reference each fallback attempt therefore invented its own,
 * so a payment that failed on one provider and succeeded on the next left two
 * attempts with no shared identifier - and the failed attempt's reference,
 * never stored and never returned, was unrecoverable.
 */

/**
 * A driver that records the reference it was handed on every charge() call.
 *
 * Its ChargeResponseDTO echoes back whatever reference it received. The
 * `??` branch mirrors what the real drivers do and exists purely so a
 * regression is loud: a response reference of GENERATED_BY_* means the driver
 * had to invent one, which is the exact behaviour these tests forbid.
 */
function makeReferenceRecordingDriver(string $name, ?Throwable $throws = null): DriverInterface
{
    return new class($name, $throws) implements DriverInterface
    {
        /** @var array<int, string|null> */
        public array $seenReferences = [];

        public int $chargeCalls = 0;

        public int $verifyCalls = 0;

        public function __construct(
            private readonly string $driverName,
            private readonly ?Throwable $throws
        ) {}

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            $this->chargeCalls++;
            $this->seenReferences[] = $request->reference;

            if ($this->throws !== null) {
                throw $this->throws;
            }

            return new ChargeResponseDTO(
                reference: $request->reference ?? 'GENERATED_BY_'.$this->driverName,
                authorizationUrl: 'https://example.test/pay',
                accessCode: 'code_'.$this->driverName,
                status: 'pending',
                provider: $this->driverName,
            );
        }

        public function verify(string $reference): VerificationResponseDTO
        {
            $this->verifyCalls++;

            if ($this->throws !== null) {
                throw $this->throws;
            }

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
function makeReferenceTestManager(array $drivers): PaymentManager
{
    $manager = new PaymentManager;
    $reflection = new ReflectionClass($manager);
    $property = $reflection->getProperty('drivers');
    $property->setAccessible(true);
    $property->setValue($manager, $drivers);

    return $manager;
}

function referencelessChargeRequest(): ChargeRequestDTO
{
    return ChargeRequestDTO::fromArray([
        'amount' => 100.00,
        'currency' => 'NGN',
        'email' => 'test@example.com',
    ]);
}

beforeEach(function () {
    app()->forgetInstance('payments.config');
    Cache::flush();

    config([
        'payments.default' => 'primary',
        'payments.fallback' => 'secondary',
        'payments.health_check.enabled' => false,
        'payments.logging.enabled' => false,
        'payments.providers' => [
            'primary' => ['driver' => 'primary', 'enabled' => true, 'secret_key' => 'test'],
            'secondary' => ['driver' => 'secondary', 'enabled' => true, 'secret_key' => 'test'],
        ],
    ]);
});

// ---------------------------------------------------------------------------
// One payment, one reference
// ---------------------------------------------------------------------------

test('a charge with no caller-supplied reference is given one before any driver sees it', function () {
    $primary = makeReferenceRecordingDriver('primary');
    $manager = makeReferenceTestManager(['primary' => $primary]);

    $response = $manager->chargeWithFallback(referencelessChargeRequest());

    expect($primary->seenReferences)->toHaveCount(1)
        ->and($primary->seenReferences[0])->not->toBeNull()
        ->and($response->reference)->toBe($primary->seenReferences[0])
        ->and($response->reference)->not->toStartWith('GENERATED_BY_');
});

test('every provider in the fallback chain is handed the same reference', function () {
    // The regression this whole change exists for: without a caller-supplied
    // reference, the failed attempt and the successful one used to be
    // completely unrelatable.
    $primary = makeReferenceRecordingDriver('primary', throws: new ChargeException('provider down'));
    $secondary = makeReferenceRecordingDriver('secondary');

    $manager = makeReferenceTestManager(['primary' => $primary, 'secondary' => $secondary]);

    $response = $manager->chargeWithFallback(referencelessChargeRequest());

    expect($primary->chargeCalls)->toBe(1)
        ->and($secondary->chargeCalls)->toBe(1)
        ->and($primary->seenReferences[0])->not->toBeNull()
        ->and($secondary->seenReferences[0])->toBe($primary->seenReferences[0])
        ->and($response->reference)->toBe($primary->seenReferences[0]);
});

test('a caller-supplied reference is passed through untouched', function () {
    $primary = makeReferenceRecordingDriver('primary');
    $manager = makeReferenceTestManager(['primary' => $primary]);

    $request = ChargeRequestDTO::fromArray([
        'amount' => 100.00,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => 'order_12345',
    ]);

    $response = $manager->chargeWithFallback($request);

    expect($primary->seenReferences[0])->toBe('order_12345')
        ->and($response->reference)->toBe('order_12345');
});

// ---------------------------------------------------------------------------
// Shape of a generated reference
// ---------------------------------------------------------------------------

test('a generated reference carries the provider-neutral prefix, not a provider name', function () {
    // A reference minted before the chain runs cannot know which provider will
    // fulfil it. Naming one would make ProviderDetector resolve it confidently
    // and wrongly the moment a fallback wins.
    $primary = makeReferenceRecordingDriver('primary');
    $manager = makeReferenceTestManager(['primary' => $primary]);

    $reference = $manager->chargeWithFallback(referencelessChargeRequest())->reference;

    expect($reference)->toStartWith(PaymentConstants::REFERENCE_PREFIX.'_')
        ->and($reference)->not->toContain('PRIMARY')
        ->and($reference)->not->toContain('SECONDARY');
});

test('a generated reference is valid input to ChargeRequestDTO', function () {
    // Whatever PayZephyr mints must survive being handed straight back to it,
    // which is exactly what a caller re-submitting or verifying will do.
    $primary = makeReferenceRecordingDriver('primary');
    $manager = makeReferenceTestManager(['primary' => $primary]);

    $reference = $manager->chargeWithFallback(referencelessChargeRequest())->reference;

    $roundTripped = ChargeRequestDTO::fromArray([
        'amount' => 100.00,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => $reference,
    ]);

    expect($roundTripped->reference)->toBe($reference)
        ->and(strlen((string) $reference))->toBeLessThanOrEqual(PaymentConstants::MAX_REFERENCE_LENGTH);
});

test('two referenceless charges are given different references', function () {
    $primary = makeReferenceRecordingDriver('primary');
    $manager = makeReferenceTestManager(['primary' => $primary]);

    $first = $manager->chargeWithFallback(referencelessChargeRequest())->reference;
    $second = $manager->chargeWithFallback(referencelessChargeRequest())->reference;

    expect($first)->not->toBe($second);
});

// ---------------------------------------------------------------------------
// The in-flight claim, now that there is something to claim
// ---------------------------------------------------------------------------

test('a referenceless charge now takes an in-flight claim it can be held to', function () {
    // claimChargeInFlight() returns early on a null reference, so before this
    // change an auto-referenced charge took no claim at all and re-submitting
    // the reference PayZephyr had just handed back charged the customer again.
    $primary = makeReferenceRecordingDriver('primary');
    $manager = makeReferenceTestManager(['primary' => $primary]);

    $reference = $manager->chargeWithFallback(referencelessChargeRequest())->reference;

    $resubmission = ChargeRequestDTO::fromArray([
        'amount' => 100.00,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => $reference,
    ]);

    expect(fn () => $manager->chargeWithFallback($resubmission))
        ->toThrow(ProviderException::class, 'already in progress');

    expect($primary->chargeCalls)->toBe(1);
});

test('a referenceless charge that definitively fails releases its claim', function () {
    // The claim is now taken for these charges, so it must also be given back -
    // otherwise a failed auto-referenced charge would poison its own reference.
    $failing = makeReferenceRecordingDriver('primary', throws: new ChargeException('card declined'));
    $manager = makeReferenceTestManager(['primary' => $failing]);

    expect(fn () => $manager->chargeWithFallback(referencelessChargeRequest()))
        ->toThrow(ProviderException::class);

    $reference = $failing->seenReferences[0];

    $retry = ChargeRequestDTO::fromArray([
        'amount' => 100.00,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'reference' => $reference,
    ]);

    $succeeding = makeReferenceRecordingDriver('primary');
    $response = makeReferenceTestManager(['primary' => $succeeding])->chargeWithFallback($retry);

    expect($response->reference)->toBe($reference);
});

// ---------------------------------------------------------------------------
// ChargeRequestDTO::withReference()
// ---------------------------------------------------------------------------

test('withReference preserves every other field, including the idempotency key', function () {
    $original = ChargeRequestDTO::fromArray([
        'amount' => 250.50,
        'currency' => 'NGN',
        'email' => 'test@example.com',
        'callback_url' => 'https://example.test/callback',
        'metadata' => ['order' => 'A1'],
        'description' => 'A description',
        'customer' => ['name' => 'Ada'],
        'custom_fields' => ['field' => 'value'],
        'split' => ['type' => 'flat'],
        'channels' => ['card'],
        'idempotency_key' => 'explicit_key_1',
    ]);

    $stamped = $original->withReference('PZ_1_abc');

    expect($stamped->reference)->toBe('PZ_1_abc')
        ->and($stamped->idempotencyKey)->toBe('explicit_key_1')
        ->and($stamped->amount)->toBe($original->amount)
        ->and($stamped->currency)->toBe($original->currency)
        ->and($stamped->email)->toBe($original->email)
        ->and($stamped->callbackUrl)->toBe($original->callbackUrl)
        ->and($stamped->metadata)->toBe($original->metadata)
        ->and($stamped->description)->toBe($original->description)
        ->and($stamped->customer)->toBe($original->customer)
        ->and($stamped->customFields)->toBe($original->customFields)
        ->and($stamped->split)->toBe($original->split)
        ->and($stamped->channels)->toBe($original->channels);
});

test('withReference leaves the original request untouched', function () {
    $original = referencelessChargeRequest();

    $original->withReference('PZ_1_abc');

    expect($original->reference)->toBeNull();
});

test('withReference rejects a reference the DTO would not have accepted', function () {
    expect(fn () => referencelessChargeRequest()->withReference('has spaces and !'))
        ->toThrow(InvalidArgumentException::class, 'Invalid reference format');
});

test('the unreferenced-request guard on the in-flight claim still refuses to claim', function () {
    // Reached by reflection on purpose. resolveChargeReference() makes this
    // branch unreachable from chargeWithFallback(), but it is not dead code:
    // it is the only thing standing between a future second call site and a
    // claim key built from an empty identifier, which would collide with every
    // other unreferenced request rather than protecting any of them.
    $manager = makeReferenceTestManager(['primary' => makeReferenceRecordingDriver('primary')]);

    $claim = (new ReflectionClass($manager))
        ->getMethod('claimChargeInFlight')
        ->invoke($manager, referencelessChargeRequest());

    expect($claim)->toBeNull();
});

// ---------------------------------------------------------------------------
// Documented consequence: a neutral prefix is not provider-detectable
// ---------------------------------------------------------------------------

test('verifying a generated reference with no stored context falls through the enabled providers', function () {
    // ProviderDetector resolves a provider from a reference *prefix*, so a
    // neutral prefix yields null - by design. With no cached session and no
    // transaction row, verify() then tries every enabled provider in turn.
    // Slower than a prefix hit, and still correct, which is the trade this
    // change accepts.
    $primary = makeReferenceRecordingDriver('primary', throws: new ChargeException('not mine'));
    $secondary = makeReferenceRecordingDriver('secondary');

    $manager = makeReferenceTestManager(['primary' => $primary, 'secondary' => $secondary]);

    $response = $manager->verify(PaymentConstants::REFERENCE_PREFIX.'_1755000000_abcdef0123456789');

    expect($primary->verifyCalls)->toBe(1)
        ->and($secondary->verifyCalls)->toBe(1)
        ->and($response->provider)->toBe('secondary');
});
