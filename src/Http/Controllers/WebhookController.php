<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;
use Throwable;

class WebhookController extends Controller
{
    public function __construct(
        protected PaymentManager $manager
    ) {}

    public function handle(Request $request, string $provider)
    {
        try {
            $driver = $this->manager->driver($provider);

            if (config('payments.webhook.verify_signature', true)) {
                $isValid = $driver->validateWebhook(
                    $request->headers->all(),
                    $request->getContent()
                );

                if (! $isValid) {
                    logger()->warning("Invalid webhook signature for $provider");

                    return response()->json(['error' => 'Invalid signature'], 403);
                }
            }

            $payload = $request->all();

            // Update Database Transaction
            if (config('payments.logging.enabled', true)) {
                // You'll need to extract the reference based on the provider
                // This is a simple example; usually, you'd use the driver to parse the webhook
                $reference = $payload['data']['reference'] ?? $payload['data']['tx_ref'] ?? null;

                if ($reference) {
                    PaymentTransaction::where('reference', $reference)->update([
                        'status' => 'success', // Logic to determine status based on payload is needed here
                        'paid_at' => now(),
                    ]);
                }
            }

            event("payments.webhook.$provider", [$payload]);
            event('payments.webhook', [$provider, $payload]);

            logger()->info("Webhook processed for $provider");

            return response()->json(['status' => 'success']);
        } catch (Throwable $e) {
            logger()->error('Webhook processing failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Processing failed'], 500);
        }
    }
}
