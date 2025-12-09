<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use KenDeNigerian\PayZephyr\Constants\PaymentStatus;
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;
use Throwable;

/**
 * WebhookController - Handles Payment Notifications from Providers
 *
 * When a payment is completed (or fails), the payment provider sends a webhook
 * (a POST request) to this controller. This controller:
 * 1. Verifies the webhook is really from the provider (security check)
 * 2. Updates the payment record in database
 * 3. Fires Laravel events so your app can react (e.g., send email, update order status)
 */
final class WebhookController extends Controller
{
    protected StatusNormalizer $statusNormalizer;

    public function __construct(
        protected PaymentManager $manager,
        ?StatusNormalizer $statusNormalizer = null
    ) {
        $this->statusNormalizer = $statusNormalizer ?? app(StatusNormalizer::class);
    }

    /**
     * Process an incoming webhook from a payment provider.
     *
     * This is called automatically when a provider sends a webhook.
     * It verifies the webhook is legitimate, updates your database,
     * and fires events so your app can handle the payment status change.
     *
     * @param  Request  $request  The webhook HTTP request
     * @param  string  $provider  Which provider sent it (e.g., 'paystack', 'stripe')?
     */
    public function handle(Request $request, string $provider): JsonResponse
    {
        try {
            $driver = $this->manager->driver($provider);
            // Get the raw request body - this is critical for signature validation
            // getContent() returns the raw body even if Laravel has parsed it
            // Note: If using a proxy/tunnel (like ngrok, Expose, etc.), ensure it doesn't modify the body
            $rawBody = $request->getContent();

            if (config('payments.webhook.verify_signature', true)) {
                $isValid = $driver->validateWebhook(
                    $request->headers->all(),
                    $rawBody
                );

                if (! $isValid) {
                    logger()->warning("Invalid webhook signature for $provider", [
                        'ip' => $request->ip(),
                    ]);

                    return response()->json(['error' => 'Invalid signature'], 403);
                }
            }

            $payload = $request->all();
            $reference = $this->extractReference($provider, $payload);

            // Update transaction if reference exists and logging is enabled
            if ($reference && config('payments.logging.enabled', true)) {
                $this->updateTransactionFromWebhook($provider, $reference, $payload);
            }

            // Fire events even if no reference (webhook still processed)
            event("payments.webhook.$provider", [$payload]);
            event('payments.webhook', [$provider, $payload]);

            logger()->info("Webhook processed for $provider", [
                'reference' => $reference,
                'event' => $payload['event'] ?? $payload['eventType'] ?? $payload['event_type'] ?? 'unknown',
            ]);

            return response()->json(['status' => 'success']);
        } catch (Throwable $e) {
            logger()->error('Webhook processing failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => 'Webhook received but processing failed internally'], 500);
        }
    }

    /**
     * Find the transaction reference in the webhook data.
     *
     * Delegates to the driver to extract the reference according to its webhook format.
     */
    protected function extractReference(string $provider, array $payload): ?string
    {
        try {
            return $this->manager->driver($provider)->extractWebhookReference($payload);
        } catch (DriverNotFoundException) {
            // Unknown provider - return null
            return null;
        }
    }

    /**
     * Figure out the payment status from the webhook data.
     *
     * Delegates to the driver to extract the status, then normalizes it to standard format.
     */
    protected function determineStatus(string $provider, array $payload): string
    {
        try {
            $status = $this->manager->driver($provider)->extractWebhookStatus($payload);

            return $this->statusNormalizer->normalize($status, $provider);
        } catch (DriverNotFoundException) {
            // Unknown provider - use default extraction
            $status = $payload['status'] ?? $payload['paymentStatus'] ?? 'unknown';

            return $this->statusNormalizer->normalize($status, $provider);
        }
    }

    /**
     * Update the payment record in the database when we receive a webhook.
     *
     * Updates the payment status, which payment method was used, and when it was paid.
     */
    protected function updateTransactionFromWebhook(string $provider, string $reference, array $payload): void
    {
        try {
            $status = $this->determineStatus($provider, $payload);

            $updateData = [
                'status' => $status,
            ];

            $statusEnum = PaymentStatus::tryFromString($status);
            if ($statusEnum?->isSuccessful()) {
                $updateData['paid_at'] = now();
            }

            try {
                $channel = $this->manager->driver($provider)->extractWebhookChannel($payload);
                if ($channel) {
                    $updateData['channel'] = $channel;
                }
            } catch (DriverNotFoundException) {
                // Unknown provider - skip channel extraction
            }

            PaymentTransaction::where('reference', $reference)->update($updateData);

            logger()->info('Transaction updated from webhook', [
                'reference' => $reference,
                'status' => $status,
                'provider' => $provider,
            ]);
        } catch (Throwable $e) {
            logger()->error('Failed to update transaction from webhook', [
                'error' => $e->getMessage(),
                'reference' => $reference,
                'provider' => $provider,
            ]);
        }
    }
}
