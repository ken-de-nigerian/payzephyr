<?php

declare(strict_types=1);

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Cache;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Enums\RefundStatus;
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use KenDeNigerian\PayZephyr\Models\RefundTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Refund;
use KenDeNigerian\PayZephyr\Repositories\EloquentRefundRepository;

/**
 * A driver that supports NO refunds - implements DriverInterface only.
 * Used to prove Refund::refund()/fetch() reject it rather than fataling.
 */
function makeNonRefundableDriver(): DriverInterface
{
    return new class implements DriverInterface
    {
        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            return new ChargeResponseDTO('r', 'https://e.test', 'c', 'pending', [], 'nonrefundable');
        }

        public function verify(string $reference): VerificationResponseDTO
        {
            return new VerificationResponseDTO($reference, 'success', 1.0, 'NGN', provider: 'nonrefundable');
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
            return 'nonrefundable';
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

function managerWithDriver(string $name, DriverInterface $driver): PaymentManager
{
    $manager = new PaymentManager;
    $reflection = new ReflectionClass($manager);
    $property = $reflection->getProperty('drivers');
    $property->setAccessible(true);
    $property->setValue($manager, [$name => $driver]);

    return $manager;
}

beforeEach(function () {
    app()->forgetInstance('payments.config');
    Cache::flush();

    config([
        'payments.default' => 'primary',
        'payments.health_check.enabled' => false,
        'payments.refunds.validation.enabled' => false,
        'payments.refunds.prevent_duplicates' => true,
        'payments.providers' => [
            'primary' => ['driver' => 'primary', 'enabled' => true, 'secret_key' => 'test'],
            'nonrefundable' => ['driver' => 'nonrefundable', 'enabled' => true, 'secret_key' => 'test'],
        ],
    ]);
});

test('using() is an alias for with() and selects the same provider', function () {
    $driver = makeRefundCapableDriver('primary');
    $manager = managerWithDriver('primary', $driver);

    $response = (new Refund($manager))->transaction('txn_alias')->using('primary')->refund();

    expect($response->transactionReference)->toBe('txn_alias')
        ->and($driver->refundCalls)->toBe(1);
});

test('using() accepts an array of providers, like with()', function () {
    $driver = makeRefundCapableDriver('primary');
    $manager = managerWithDriver('primary', $driver);

    $response = (new Refund($manager))->transaction('txn_alias_array')->using(['primary'])->refund();

    expect($response->transactionReference)->toBe('txn_alias_array');
});

test('idempotency() generates a key when none is supplied', function () {
    $captured = null;
    $driver = makeCapturingRefundDriver($captured);
    $manager = managerWithDriver('primary', $driver);

    (new Refund($manager))->transaction('txn_auto_key')->with('primary')->idempotency()->refund();

    expect($captured)->not->toBeNull()
        ->and($captured->idempotencyKey)->not->toBeNull()
        ->and($captured->idempotencyKey)->not->toBe('');
});

test('idempotency() uses the caller-supplied key verbatim', function () {
    $captured = null;
    $driver = makeCapturingRefundDriver($captured);
    $manager = managerWithDriver('primary', $driver);

    (new Refund($manager))->transaction('txn_given_key')->with('primary')->idempotency('my-stable-key')->refund();

    expect($captured->idempotencyKey)->toBe('my-stable-key');
});

test('refund() rejects a provider that does not support refunds', function () {
    $manager = managerWithDriver('nonrefundable', makeNonRefundableDriver());

    expect(fn () => (new Refund($manager))->transaction('txn_x')->with('nonrefundable')->refund())
        ->toThrow(PaymentException::class, 'does not support refunds');
});

test('fetch() rejects a provider that does not support refunds', function () {
    $manager = managerWithDriver('nonrefundable', makeNonRefundableDriver());

    expect(fn () => (new Refund($manager))->with('nonrefundable')->fetch('rf_1'))
        ->toThrow(PaymentException::class, 'does not support refunds');
});

test('fetch() returns the refund for a supporting provider', function () {
    $manager = managerWithDriver('primary', makeRefundCapableDriver('primary'));

    expect((new Refund($manager))->with('primary')->fetch('rf_1')->refundReference)->toBe('rf_1');
});

test('an ambiguous refund outcome is reported as unconfirmed and the lock is deliberately NOT released', function () {
    // A refund whose response was lost may already have moved money. The lock
    // must stay held so a retry cannot immediately re-issue it, and the error
    // must tell the caller to reconcile rather than retry.
    $lost = new RequestException('Read timed out', new Request('POST', '/refunds'));
    $driver = makeThrowingRefundDriver(new RefundException('refund failed', 0, $lost));
    $manager = managerWithDriver('primary', $driver);

    expect(fn () => (new Refund($manager))->transaction('txn_ambiguous')->with('primary')->refund())
        ->toThrow(RefundException::class, 'timed out or lost its response');

    // Still locked: a second attempt is refused rather than reaching the provider.
    expect(Cache::get('payzephyr:refund:inflight:txn_ambiguous'))->not->toBeNull();
});

test('a definitive refund failure releases the lock so a genuine retry is allowed', function () {
    // No previous network exception - the provider gave a real answer, so the
    // refund definitively did not happen and retrying is safe.
    $driver = makeThrowingRefundDriver(new RefundException('card declined'));
    $manager = managerWithDriver('primary', $driver);

    expect(fn () => (new Refund($manager))->transaction('txn_definitive')->with('primary')->refund())
        ->toThrow(RefundException::class, 'card declined');

    expect(Cache::get('payzephyr:refund:inflight:txn_definitive'))->toBeNull();
});

test('RefundStatus::label returns a human-readable label for every case', function () {
    expect(RefundStatus::PENDING->label())->toBe('Pending')
        ->and(RefundStatus::PROCESSING->label())->toBe('Processing')
        ->and(RefundStatus::COMPLETED->label())->toBe('Completed')
        ->and(RefundStatus::FAILED->label())->toBe('Failed')
        ->and(RefundStatus::CANCELLED->label())->toBe('Cancelled');
});

test('updateOrCreateAtomic updates the existing row when the reference already exists', function () {
    $repo = new EloquentRefundRepository;

    $repo->updateOrCreateAtomic('rf_existing', [
        'transaction_reference' => 'txn_1',
        'provider' => 'primary',
        'status' => 'pending',
        'amount' => 10.00,
        'currency' => 'NGN',
    ]);

    $repo->updateOrCreateAtomic('rf_existing', ['status' => 'completed']);

    expect(RefundTransaction::where('refund_reference', 'rf_existing')->count())->toBe(1)
        ->and(RefundTransaction::where('refund_reference', 'rf_existing')->first()->status)->toBe('completed');
});

test('updateOrCreateAtomic creates a row when the reference is new', function () {
    $repo = new EloquentRefundRepository;

    $created = $repo->updateOrCreateAtomic('rf_brand_new', [
        'transaction_reference' => 'txn_2',
        'provider' => 'primary',
        'status' => 'pending',
        'amount' => 5.00,
        'currency' => 'NGN',
    ]);

    expect($created->refund_reference)->toBe('rf_brand_new')
        ->and(RefundTransaction::where('refund_reference', 'rf_brand_new')->count())->toBe(1);
});
