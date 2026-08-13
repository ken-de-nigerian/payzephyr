<?php

declare(strict_types=1);

/**
 * Shared fake payment drivers for tests.
 *
 * Lives here rather than inside a test file because Pest only makes
 * cross-file globals visible during a full-suite run - a single-file run
 * would fail to resolve them. tests/Pest.php requires this, so these are
 * always available however the suite is invoked.
 */

use KenDeNigerian\PayZephyr\Contracts\DriverInterface;
use KenDeNigerian\PayZephyr\Contracts\SupportsRefundsInterface;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\PaymentManager;

/**
 * Regression: RefundValidator::hasInFlightRefund() only sees a "pending"
 * refund_transactions row once the *previous* refund's provider call has
 * already returned (LogsRefundTransactions logs from the response). Two
 * Refund::refund() calls for the same transaction submitted close enough
 * together therefore both pass that check while neither has written
 * anything yet, and both proceed to call the provider - a genuine
 * double-refund.
 *
 * A real two-process race can't be reproduced in a single PHPUnit process,
 * but the exact vulnerable window can: this fake driver's refund() calls a
 * *second* Refund::refund() for the same transaction from inside the first
 * call, before the first has returned (and therefore before any DB row for
 * it exists) - reproducing "another request arrives while the first is
 * still mid-flight" deterministically.
 */
function makeRefundCapableDriver(string $name): DriverInterface&SupportsRefundsInterface
{
    return new class($name) implements DriverInterface, SupportsRefundsInterface
    {
        public int $refundCalls = 0;

        public function __construct(private readonly string $driverName) {}

        public function refund(RefundRequestDTO $request): RefundResponseDTO
        {
            $this->refundCalls++;

            return new RefundResponseDTO(
                refundReference: 'rf_'.$this->refundCalls,
                transactionReference: $request->transactionReference,
                status: 'pending',
                amount: $request->amount ?? 100.0,
                currency: $request->currency ?? 'NGN',
                provider: $this->driverName,
            );
        }

        public function fetchRefund(string $refundReference): RefundResponseDTO
        {
            return new RefundResponseDTO(
                refundReference: $refundReference,
                transactionReference: 'txn_ref',
                status: 'pending',
                amount: 100.0,
                currency: 'NGN',
                provider: $this->driverName,
            );
        }

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            return new ChargeResponseDTO(
                reference: 'ref_'.$this->driverName,
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
function injectFakeDriverForRefund(PaymentManager $manager, string $name, DriverInterface $driver): void
{
    $reflection = new ReflectionClass($manager);
    $property = $reflection->getProperty('drivers');
    $property->setAccessible(true);
    $property->setValue($manager, [$name => $driver]);
}
function makeCapturingRefundDriver(&$captured): DriverInterface&SupportsRefundsInterface
{
    return new class($captured) implements DriverInterface, SupportsRefundsInterface
    {
        public function __construct(private mixed &$captured) {}

        public function refund(RefundRequestDTO $request): RefundResponseDTO
        {
            $this->captured = $request;

            return new RefundResponseDTO('rf_cap', $request->transactionReference, 'pending', 1.0, 'NGN', provider: 'primary');
        }

        public function fetchRefund(string $refundReference): RefundResponseDTO
        {
            return new RefundResponseDTO($refundReference, 'txn', 'pending', 1.0, 'NGN', provider: 'primary');
        }

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            return new ChargeResponseDTO('r', 'https://e.test', 'c', 'pending', [], 'primary');
        }

        public function verify(string $reference): VerificationResponseDTO
        {
            return new VerificationResponseDTO($reference, 'success', 1.0, 'NGN', provider: 'primary');
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
            return 'primary';
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

/** A refund-capable driver whose refund() always throws the given exception. */
function makeThrowingRefundDriver(Throwable $toThrow): DriverInterface&SupportsRefundsInterface
{
    return new class($toThrow) implements DriverInterface, SupportsRefundsInterface
    {
        public function __construct(private readonly Throwable $toThrow) {}

        public function refund(RefundRequestDTO $request): RefundResponseDTO
        {
            throw $this->toThrow;
        }

        public function fetchRefund(string $refundReference): RefundResponseDTO
        {
            return new RefundResponseDTO($refundReference, 'txn', 'pending', 1.0, 'NGN', provider: 'primary');
        }

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            return new ChargeResponseDTO('r', 'https://e.test', 'c', 'pending', [], 'primary');
        }

        public function verify(string $reference): VerificationResponseDTO
        {
            return new VerificationResponseDTO($reference, 'success', 1.0, 'NGN', provider: 'primary');
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
            return 'primary';
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
 * A minimal fake driver whose charge()/verify() succeed and record how many
 * times each was called. Used to prove chargeWithFallback()/verify() never
 * invoke a second provider once the first one has already succeeded -
 * regardless of what happens in PayZephyr's own post-success bookkeeping
 * (cache writes, event dispatch).
 */
function makeCountingSuccessDriver(string $name): DriverInterface
{
    return new class($name) implements DriverInterface
    {
        public int $chargeCalls = 0;

        public int $verifyCalls = 0;

        public function __construct(private readonly string $driverName) {}

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            $this->chargeCalls++;

            return new ChargeResponseDTO(
                reference: 'ref_'.$this->driverName,
                authorizationUrl: 'https://example.test/pay',
                accessCode: 'code_'.$this->driverName,
                status: 'pending',
                provider: $this->driverName,
            );
        }

        public function verify(string $reference): VerificationResponseDTO
        {
            $this->verifyCalls++;

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

/**
 * @param  array<string, DriverInterface>  $drivers
 */
function injectFakeDrivers(PaymentManager $manager, array $drivers): void
{
    $reflection = new ReflectionClass($manager);
    $property = $reflection->getProperty('drivers');
    $property->setAccessible(true);
    $property->setValue($manager, $drivers);
}
