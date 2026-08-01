<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Tests\Integration;

use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\Facades\Payment;
use KenDeNigerian\PayZephyr\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unified Payment Abstraction Verification Test
 *
 * This test verifies that PayZephyr maintains its core promise:
 * "Write once, run anywhere" - the same code works across ALL providers.
 */
#[Group('integration')]
#[Group('abstraction')]
class UnifiedPaymentAbstractionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure health checks are disabled for all tests
        config(['payments.health_check.enabled' => false]);

        // Reset facade and manager instances to ensure clean state
        Payment::clearResolvedInstances();
        $this->app->forgetInstance(\KenDeNigerian\PayZephyr\PaymentManager::class);
        $this->app->forgetInstance('payments.config');
    }

    /**
     * All supported payment providers
     *
     * @return array<string>
     */
    public static function providers(): array
    {
        return [
            ['paystack'],
            ['stripe'],
            ['flutterwave'],
            ['monnify'],
            ['paypal'],
            ['square'],
            ['opay'],
            ['mollie'],
        ];
    }

    /**
     * Test that switching providers requires ONLY config change, not code change
     */
    public function test_switching_providers_requires_only_config_change(): void
    {
        // Same code, different providers via config
        $providers = ['paystack', 'stripe'];

        foreach ($providers as $provider) {
            // Ensure provider is enabled
            config(["payments.providers.{$provider}.enabled" => true]);
            $this->app->forgetInstance('payments.config');

            // Setup mocked provider with provider-specific response format
            // Stripe uses SDK, not HTTP responses
            $responses = match ($provider) {
                'stripe' => [], // Stripe uses SDK, handled in setupMockedProvider
                default => [$this->getProviderChargeResponse($provider)],
            };
            $this->setupMockedProvider($provider, $responses);

            // Use appropriate currency for provider
            $currency = $this->getTestCurrency($provider);

            // IDENTICAL code - only provider changes via ->with()
            $response = Payment::amount(100.00)
                ->currency($currency)
                ->email('test@example.com')
                ->callback('https://example.com/callback')
                ->with($provider)
                ->charge();

            $this->assertInstanceOf(ChargeResponseDTO::class, $response);
            $this->assertEquals($provider, $response->provider);
        }
    }

    /**
     * Check if provider is enabled in config
     */
    protected function isProviderEnabled(string $provider): bool
    {
        $config = config("payments.providers.{$provider}", []);

        return ($config['enabled'] ?? false) === true;
    }

    /**
     * Get appropriate test currency for provider
     */
    protected function getTestCurrency(string $provider): string
    {
        return match ($provider) {
            'stripe', 'paypal', 'square' => 'USD',
            'mollie' => 'EUR',
            default => 'NGN',
        };
    }

    /**
     * Get provider-specific charge response mock
     */
    protected function getProviderChargeResponse(string $provider): Response
    {
        $reference = "ref_{$provider}_123";
        $authUrl = "https://checkout.{$provider}.com/abc123";

        return match ($provider) {
            'paystack' => new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'reference' => $reference,
                    'authorization_url' => $authUrl,
                    'access_code' => 'access_123',
                ],
            ])),
            'flutterwave' => new Response(200, [], json_encode([
                'status' => 'success',
                'data' => [
                    'link' => $authUrl,
                    'tx_ref' => $reference,
                ],
            ])),
            'monnify' => new Response(200, [], json_encode([
                'requestSuccessful' => true,
                'responseBody' => [
                    'checkoutUrl' => $authUrl,
                    'transactionReference' => $reference,
                ],
            ])),
            'opay' => new Response(200, [], json_encode([
                'code' => '00000',
                'message' => 'Success',
                'data' => [
                    'cashierUrl' => $authUrl,
                    'orderNo' => $reference,
                ],
            ])),
            'square' => new Response(200, [], json_encode([
                'payment_link' => [
                    'id' => 'payment_link_123',
                    'url' => $authUrl,
                    'order_id' => 'order_456',
                ],
            ])),
            'mollie' => new Response(200, [], json_encode([
                'id' => $reference,
                'status' => 'open',
                '_links' => [
                    'checkout' => ['href' => $authUrl],
                ],
            ])),
            'paypal' => new Response(201, [], json_encode([
                'id' => 'ORDER_ID_123',
                'status' => 'CREATED',
                'links' => [
                    ['rel' => 'approve', 'href' => $authUrl],
                ],
            ])),
            default => new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'reference' => $reference,
                    'authorization_url' => $authUrl,
                    'access_code' => 'access_123',
                ],
            ])),
        };
    }

    /**
     * Get provider-specific webhook payload for testing
     *
     * @return array<string, mixed>
     */
    protected function getProviderWebhookPayload(string $provider): array
    {
        // Return mock webhook payloads for each provider
        // In real tests, these would be actual webhook formats
        return match ($provider) {
            'paystack' => [
                'event' => 'charge.success',
                'data' => [
                    'reference' => 'test_ref_123',
                    'status' => 'success',
                ],
            ],
            'stripe' => [
                'type' => 'payment_intent.succeeded',
                'data' => [
                    'object' => [
                        'id' => 'pi_test',
                        'metadata' => ['reference' => 'test_ref_123'],
                        'status' => 'succeeded',
                    ],
                ],
            ],
            default => [
                'reference' => 'test_ref_123',
                'status' => 'success',
            ],
        };
    }

    /**
     * Get provider-specific verification response mock
     */
    protected function getProviderVerifyResponse(string $provider): Response
    {
        $reference = 'test_ref_123';

        return match ($provider) {
            'paystack' => new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'reference' => $reference,
                    'status' => 'success',
                    'amount' => 10000,
                    'currency' => 'NGN',
                ],
            ])),
            'flutterwave' => new Response(200, [], json_encode([
                'status' => 'success',
                'data' => [
                    'tx_ref' => $reference,
                    'status' => 'successful',
                    'amount' => 100,
                    'currency' => 'NGN',
                ],
            ])),
            'monnify' => new Response(200, [], json_encode([
                'requestSuccessful' => true,
                'responseBody' => [
                    'transactionReference' => $reference,
                    'paymentStatus' => 'PAID',
                    'amountPaid' => 10000,
                    'currencyCode' => 'NGN',
                ],
            ])),
            'opay' => new Response(200, [], json_encode([
                'code' => '00000',
                'data' => [
                    'reference' => $reference,
                    'status' => 'SUCCESS',
                    'amount' => ['total' => 10000, 'currency' => 'NGN'],
                ],
            ])),
            'square' => new Response(200, [], json_encode([
                'payment' => [
                    'id' => 'payment_123',
                    'status' => 'COMPLETED',
                    'amount_money' => ['amount' => 10000, 'currency' => 'USD'],
                ],
            ])),
            'mollie' => new Response(200, [], json_encode([
                'id' => $reference,
                'status' => 'paid',
                'amount' => ['value' => '100.00', 'currency' => 'EUR'],
            ])),
            default => new Response(200, [], json_encode([
                'status' => true,
                'data' => [
                    'reference' => $reference,
                    'status' => 'success',
                    'amount' => 10000,
                    'currency' => 'NGN',
                ],
            ])),
        };
    }

    /**
     * Get provider-specific webhook headers for testing
     *
     * @return array<string, array<int, string>>
     */
    protected function getProviderWebhookHeaders(string $provider): array
    {
        return match ($provider) {
            'paystack' => [
                'x-paystack-signature' => ['test_signature'],
            ],
            'stripe' => [
                'stripe-signature' => ['test_signature'],
            ],
            default => [],
        };
    }
}
