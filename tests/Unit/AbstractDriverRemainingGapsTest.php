<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use KenDeNigerian\PayZephyr\Contracts\SubscriptionRepositoryInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Drivers\AbstractDriver;

/**
 * Targets the remaining uncovered lines in AbstractDriver that are not
 * reachable through any of the concrete provider drivers, because every
 * concrete driver overrides getIdempotencyHeader() and the extractWebhook*()
 * defaults. A minimal anonymous driver that deliberately does NOT override
 * those methods is used to exercise AbstractDriver's own default
 * implementations directly.
 */
function makeGapTestDriver(array $config = ['currencies' => ['NGN']]): AbstractDriver
{
    return new class($config) extends AbstractDriver
    {
        protected string $name = 'gaptest';

        protected function validateConfig(): void {}

        protected function getDefaultHeaders(): array
        {
            return [];
        }

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            return new ChargeResponseDTO('', '', '', 'pending');
        }

        public function verify(string $reference): VerificationResponseDTO
        {
            return new VerificationResponseDTO('', 'pending', 0, '');
        }

        public function validateWebhook(array $headers, string $body): bool
        {
            return false;
        }

        public function healthCheck(): bool
        {
            return false;
        }
    };
}

afterEach(function () {
    config(['payments.logging.enabled' => true, 'payments.logging.channel' => null]);
    app()->forgetInstance('payments.config');
});

// ==================== getIdempotencyHeader() default (line 176) ====================

test('abstract driver default getIdempotencyHeader returns Idempotency-Key header', function () {
    $driver = makeGapTestDriver();

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('getIdempotencyHeader');

    $result = $method->invoke($driver, 'my_idempotency_key');

    expect($result)->toBe(['Idempotency-Key' => 'my_idempotency_key']);
});

// ==================== log() disabled early return (line 278) ====================

test('abstract driver log does nothing when logging is disabled', function () {
    config(['payments.logging.enabled' => false]);
    app()->forgetInstance('payments.config');

    Log::shouldReceive('channel')->never();
    Log::shouldReceive('info')->never();

    $driver = makeGapTestDriver();

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('log');

    $method->invoke($driver, 'info', 'Should not be logged');

    expect(true)->toBeTrue();
});

// ==================== log() invalid channel fallback (lines 286-287) ====================

test('abstract driver log falls back to default logger when configured channel is invalid', function () {
    config([
        'payments.logging.enabled' => true,
        'payments.logging.channel' => 'nonexistent_channel_xyz',
    ]);
    app()->forgetInstance('payments.config');

    Log::shouldReceive('channel')
        ->once()
        ->with('nonexistent_channel_xyz')
        ->andThrow(new InvalidArgumentException('Log channel [nonexistent_channel_xyz] is not defined.'));

    Log::shouldReceive('warning')
        ->once()
        ->with('[gaptest] Something went wrong', []);

    $driver = makeGapTestDriver();

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('log');

    $method->invoke($driver, 'warning', 'Something went wrong');

    expect(true)->toBeTrue();
});

// ==================== setSubscriptionRepository() (lines 401-403) ====================

test('abstract driver setSubscriptionRepository stores a custom repository and returns self', function () {
    $driver = makeGapTestDriver();
    $repository = Mockery::mock(SubscriptionRepositoryInterface::class);

    $result = $driver->setSubscriptionRepository($repository);

    expect($result)->toBe($driver);

    $reflection = new ReflectionClass($driver);
    $method = $reflection->getMethod('getSubscriptionRepository');

    expect($method->invoke($driver))->toBe($repository);
});

// ==================== extractWebhookReference/Status/Channel defaults (lines 438-463) ====================

test('abstract driver default extractWebhookReference reads the reference key', function () {
    $driver = makeGapTestDriver();

    expect($driver->extractWebhookReference(['reference' => 'REF_123']))->toBe('REF_123')
        ->and($driver->extractWebhookReference(['transactionReference' => 'TXN_456']))->toBe('TXN_456')
        ->and($driver->extractWebhookReference([]))->toBeNull();
});

test('abstract driver default extractWebhookStatus reads the status key', function () {
    $driver = makeGapTestDriver();

    expect($driver->extractWebhookStatus(['status' => 'success']))->toBe('success')
        ->and($driver->extractWebhookStatus(['paymentStatus' => 'failed']))->toBe('failed')
        ->and($driver->extractWebhookStatus([]))->toBe('unknown');
});

test('abstract driver default extractWebhookChannel reads the channel key', function () {
    $driver = makeGapTestDriver();

    expect($driver->extractWebhookChannel(['channel' => 'card']))->toBe('card')
        ->and($driver->extractWebhookChannel(['paymentMethod' => 'bank_transfer']))->toBe('bank_transfer')
        ->and($driver->extractWebhookChannel([]))->toBeNull();
});
