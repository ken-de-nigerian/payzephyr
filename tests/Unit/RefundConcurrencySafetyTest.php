<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\Contracts\SupportsRefundsInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Refund;

beforeEach(function () {
    app()->forgetInstance('payments.config');

    config([
        'payments.default' => 'primary',
        'payments.health_check.enabled' => false,
        'payments.refunds.validation.enabled' => false,
        'payments.refunds.prevent_duplicates' => true,
        'payments.providers' => [
            'primary' => ['driver' => 'primary', 'enabled' => true, 'secret_key' => 'test'],
        ],
    ]);
});

test('a second refund for the same transaction submitted while the first is still in flight is rejected', function () {
    $driver = makeRefundCapableDriver('primary');

    $manager = new PaymentManager;
    injectFakeDriverForRefund($manager, 'primary', $driver);

    $racingCaught = null;

    // Simulate "another request arrives while the first is still mid-flight"
    // by triggering the second refund() call from *inside* the first
    // driver call, before the first has returned (and therefore before any
    // refund_transactions row for it exists).
    $racingDriver = new class($driver, $manager, $racingCaught) implements DriverInterface, SupportsRefundsInterface
    {
        public function __construct(
            private readonly SupportsRefundsInterface&DriverInterface $inner,
            private readonly PaymentManager $manager,
            private mixed &$racingCaught
        ) {}

        public function refund(RefundRequestDTO $request): RefundResponseDTO
        {
            try {
                (new Refund($this->manager))
                    ->transaction($request->transactionReference)
                    ->with('primary')
                    ->refund();
            } catch (RefundException $e) {
                $this->racingCaught = $e;
            }

            return $this->inner->refund($request);
        }

        public function fetchRefund(string $refundReference): RefundResponseDTO
        {
            return $this->inner->fetchRefund($refundReference);
        }

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            return $this->inner->charge($request);
        }

        public function verify(string $reference): VerificationResponseDTO
        {
            return $this->inner->verify($reference);
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
            return $this->inner->getName();
        }

        public function getSupportedCurrencies(): array
        {
            return $this->inner->getSupportedCurrencies();
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

    injectFakeDriverForRefund($manager, 'primary', $racingDriver);

    $response = (new Refund($manager))
        ->transaction('txn_race')
        ->amount(50.0)
        ->currency('NGN')
        ->with('primary')
        ->refund();

    expect($racingCaught)->not->toBeNull()
        ->and($racingCaught)->toBeInstanceOf(RefundException::class)
        ->and($racingCaught->getMessage())->toContain('already in progress')
        ->and($driver->refundCalls)->toBe(1)
        ->and($response->transactionReference)->toBe('txn_race');
});

test('the in-flight lock is released after the refund completes, so a later refund on the same transaction is not blocked', function () {
    $driver = makeRefundCapableDriver('primary');

    $manager = new PaymentManager;
    injectFakeDriverForRefund($manager, 'primary', $driver);

    $first = (new Refund($manager))->transaction('txn_sequential')->with('primary')->refund();
    $second = (new Refund($manager))->transaction('txn_sequential')->with('primary')->refund();

    expect($driver->refundCalls)->toBe(2)
        ->and($first->refundReference)->not->toBe($second->refundReference);
});

test('the in-flight lock is scoped per transaction reference, not global', function () {
    $driver = makeRefundCapableDriver('primary');

    $manager = new PaymentManager;
    injectFakeDriverForRefund($manager, 'primary', $driver);

    Cache::add('payzephyr:refund:inflight:txn_a', true, 60);

    // A different transaction's refund must not be blocked by an unrelated
    // transaction's in-flight lock.
    $response = (new Refund($manager))->transaction('txn_b')->with('primary')->refund();

    expect($response->transactionReference)->toBe('txn_b')
        ->and($driver->refundCalls)->toBe(1);

    Cache::forget('payzephyr:refund:inflight:txn_a');
});

test('prevent_duplicates=false disables the in-flight lock entirely', function () {
    config(['payments.refunds.prevent_duplicates' => false]);

    $driver = makeRefundCapableDriver('primary');
    $manager = new PaymentManager;
    injectFakeDriverForRefund($manager, 'primary', $driver);

    Cache::add('payzephyr:refund:inflight:txn_disabled', true, 60);

    $response = (new Refund($manager))->transaction('txn_disabled')->with('primary')->refund();

    expect($response->transactionReference)->toBe('txn_disabled')
        ->and($driver->refundCalls)->toBe(1);

    Cache::forget('payzephyr:refund:inflight:txn_disabled');
});
