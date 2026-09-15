<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Tests\Unit;

use KenDeNigerian\PayZephyr\Enums\PaymentStatus;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;
use KenDeNigerian\PayZephyr\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Status Normalization Consistency Test
 *
 * Verifies that payment statuses are normalized consistently across providers.
 */
#[Group('unit')]
#[Group('abstraction')]
class StatusNormalizationConsistencyTest extends TestCase
{
    /**
     * Provider status variations that should normalize to 'success'
     *
     * @return array<string, array<string>>
     */
    public static function successStatusVariations(): array
    {
        return [
            ['paystack', ['success', 'successful', 'completed']],
            ['stripe', ['succeeded', 'paid', 'complete']],
            ['flutterwave', ['successful', 'success']],
            ['paypal', ['COMPLETED']],
            ['square', ['COMPLETED', 'APPROVED']],
            ['mollie', ['paid', 'paidout']],
            ['razorpay', ['paid', 'captured']],
        ];
    }

    /**
     * Provider status variations that should normalize to 'pending'
     *
     * @return array<string, array<string>>
     */
    public static function pendingStatusVariations(): array
    {
        return [
            ['paystack', ['pending', 'processing']],
            ['stripe', ['processing', 'requires_payment_method', 'requires_confirmation']],
            ['flutterwave', ['pending', 'processing']],
            ['paypal', ['PENDING', 'CREATED', 'APPROVED']],
            ['square', ['PENDING']],
            ['mollie', ['open', 'pending']],
            ['razorpay', ['created', 'partially_paid', 'authorized']],
        ];
    }

    /**
     * Provider status variations that should normalize to 'failed'
     *
     * @return array<string, array<string>>
     */
    public static function failedStatusVariations(): array
    {
        return [
            ['paystack', ['failed', 'declined']],
            ['stripe', ['payment_failed', 'canceled', 'requires_action']],
            ['flutterwave', ['failed', 'declined']],
            ['paypal', ['FAILED', 'DENIED']],
            ['square', ['FAILED', 'CANCELED']],
            ['mollie', ['failed', 'expired', 'canceled']],
            ['razorpay', ['failed', 'expired', 'cancelled']],
        ];
    }

    /**
     * Test that success status variations normalize correctly
     */
    #[DataProvider('successStatusVariations')]
    public function test_success_status_variations_normalize_correctly(string $provider, array $statuses): void
    {
        $normalizer = app(StatusNormalizer::class);

        foreach ($statuses as $status) {
            $normalized = $normalizer->normalize($status, $provider);

            // Should normalize to 'success'
            $this->assertEquals('success', $normalized);

            // Verify enum conversion
            $statusEnum = PaymentStatus::tryFromString($normalized);
            $this->assertNotNull($statusEnum);
            $this->assertTrue($statusEnum->isSuccessful());
        }
    }

    /**
     * Test that pending status variations normalize correctly
     */
    #[DataProvider('pendingStatusVariations')]
    public function test_pending_status_variations_normalize_correctly(string $provider, array $statuses): void
    {
        $normalizer = app(StatusNormalizer::class);

        foreach ($statuses as $status) {
            $normalized = $normalizer->normalize($status, $provider);

            // Should normalize to 'pending'
            $this->assertEquals('pending', $normalized);

            // Verify enum conversion
            $statusEnum = PaymentStatus::tryFromString($normalized);
            $this->assertNotNull($statusEnum);
            $this->assertTrue($statusEnum->isPending());
        }
    }

    /**
     * Test that failed status variations normalize correctly
     */
    #[DataProvider('failedStatusVariations')]
    public function test_failed_status_variations_normalize_correctly(string $provider, array $statuses): void
    {
        $normalizer = app(StatusNormalizer::class);

        foreach ($statuses as $status) {
            $normalized = $normalizer->normalize($status, $provider);

            // Should normalize to 'failed'
            $this->assertEquals('failed', $normalized);

            // Verify enum conversion
            $statusEnum = PaymentStatus::tryFromString($normalized);
            $this->assertNotNull($statusEnum);
            $this->assertTrue($statusEnum->isFailed());
        }
    }

    /**
     * Test that VerificationResponseDTO->isSuccessful() works consistently
     */
    #[DataProvider('successStatusVariations')]
    public function test_verification_response_is_successful_works_consistently(string $provider, array $statuses): void
    {
        if (! $this->isProviderEnabled($provider)) {
            $this->markTestSkipped("Provider {$provider} is not enabled");
        }

        foreach ($statuses as $status) {
            $dto = new \KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO(
                reference: 'test_ref',
                status: $status,
                amount: 100.00,
                currency: 'NGN',
                provider: $provider
            );

            // Should work consistently regardless of provider's status format
            $this->assertTrue($dto->isSuccessful());
        }
    }

    /**
     * Test that PaymentTransaction->isSuccessful() works consistently
     */
    public function test_payment_transaction_is_successful_works_consistently(): void
    {
        $normalizer = app(StatusNormalizer::class);

        // Test with different provider statuses
        $testCases = [
            ['paystack', 'success'],
            ['stripe', 'succeeded'],
            ['paypal', 'COMPLETED'],
        ];

        foreach ($testCases as [$provider, $status]) {
            if (! $this->isProviderEnabled($provider)) {
                continue;
            }

            $normalized = $normalizer->normalize($status, $provider);

            $transaction = \KenDeNigerian\PayZephyr\Models\PaymentTransaction::create([
                'reference' => 'test_ref_'.$provider,
                'provider' => $provider,
                'status' => $normalized,
                'amount' => 100.00,
                'currency' => 'NGN',
                'email' => 'test@example.com',
            ]);

            // Should work consistently
            $this->assertTrue($transaction->isSuccessful());
        }
    }

    /**
     * Test that webhook status extraction normalizes correctly
     */
    public function test_webhook_status_extraction_normalizes_correctly(): void
    {
        $providers = ['paystack', 'stripe', 'paypal'];

        foreach ($providers as $provider) {
            if (! $this->isProviderEnabled($provider)) {
                continue;
            }

            $driver = app(\KenDeNigerian\PayZephyr\PaymentManager::class)->driver($provider);
            $webhookPayload = $this->getProviderWebhookPayload($provider);

            $status = $driver->extractWebhookStatus($webhookPayload);
            $normalizer = app(StatusNormalizer::class);
            $normalized = $normalizer->normalize($status, $provider);

            // Should normalize to one of our standard statuses
            $this->assertContains($normalized, ['success', 'pending', 'failed', 'cancelled']);
        }
    }

    /**
     * Get provider webhook payload for testing
     *
     * @return array<string, mixed>
     */
    protected function getProviderWebhookPayload(string $provider): array
    {
        return match ($provider) {
            'paystack' => [
                'event' => 'charge.success',
                'data' => [
                    'status' => 'success',
                    'reference' => 'test_ref',
                ],
            ],
            'stripe' => [
                'type' => 'payment_intent.succeeded',
                'data' => [
                    'object' => [
                        'status' => 'succeeded',
                    ],
                ],
            ],
            'paypal' => [
                'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
                'resource' => [
                    'status' => 'COMPLETED',
                ],
            ],
            default => [
                'status' => 'success',
            ],
        };
    }

    /**
     * Check if provider is enabled
     */
    protected function isProviderEnabled(string $provider): bool
    {
        $config = config("payments.providers.{$provider}", []);

        return ($config['enabled'] ?? false) === true;
    }
}
