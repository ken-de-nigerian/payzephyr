<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use KenDeNigerian\PayZephyr\Contracts\RequiresAsyncWebhookVerification;
use KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface;
use KenDeNigerian\PayZephyr\Contracts\SubscriptionLifecycleHooks;
use KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface;
use KenDeNigerian\PayZephyr\Contracts\WebhookEventRepositoryInterface;
use KenDeNigerian\PayZephyr\Enums\PaymentStatus;
use KenDeNigerian\PayZephyr\Events\SubscriptionCancelled;
use KenDeNigerian\PayZephyr\Events\SubscriptionCreated;
use KenDeNigerian\PayZephyr\Events\SubscriptionPaymentFailed;
use KenDeNigerian\PayZephyr\Events\SubscriptionRenewed;
use KenDeNigerian\PayZephyr\Events\WebhookReceived;
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Traits\LogsToPaymentChannel;
use Throwable;

final class ProcessWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use LogsToPaymentChannel;

    public int $tries;

    public int $backoff;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array<int, string>>  $headers  Only used by
     *                                                      drivers implementing RequiresAsyncWebhookVerification (see
     *                                                      ADR-0007); harmless to omit for every other provider.
     */
    public function __construct(
        public readonly string $provider,
        public readonly array $payload,
        public readonly array $headers = []
    ) {
        $config = app('payments.config') ?? config('payments', []);
        $webhookConfig = $config['webhook'] ?? [];

        $this->tries = (int) ($webhookConfig['max_retries'] ?? 3);
        $this->backoff = (int) ($webhookConfig['retry_backoff'] ?? 60);
    }

    public function handle(
        PaymentManager $manager,
        StatusNormalizerInterface $statusNormalizer,
        TransactionRepositoryInterface $transactionRepository,
        WebhookEventRepositoryInterface $webhookEventRepository
    ): void {
        try {
            if (! $this->verifyDeferredSignature($manager)) {
                $this->log('warning', 'Deferred webhook signature verification failed - discarding', [
                    'provider' => $this->provider,
                ]);

                return;
            }

            $eventKey = $this->resolveEventKey($manager);

            if (! $webhookEventRepository->recordIfNew($this->provider, $eventKey)) {
                $this->log('info', 'Duplicate webhook delivery skipped', [
                    'provider' => $this->provider,
                    'event_key' => $eventKey,
                ]);

                return;
            }

            $reference = $this->extractReference($manager);

            $config = app('payments.config') ?? config('payments', []);
            if ($reference && ($config['logging']['enabled'] ?? true)) {
                $this->updateTransactionFromWebhook($manager, $statusNormalizer, $transactionRepository, $reference);
            }

            if ($this->isSubscriptionWebhook($this->payload)) {
                $this->processSubscriptionWebhook($this->payload, $this->provider, $manager);
            }

            WebhookReceived::dispatch($this->provider, $this->payload, $reference);

            $this->log('info', "Webhook processed for $this->provider", [
                'reference' => $reference,
                'event' => $this->payload['event'] ?? $this->payload['eventType'] ?? $this->payload['event_type'] ?? 'unknown',
            ]);
        } catch (Throwable $e) {
            $this->log('error', 'Webhook processing failed', [
                'provider' => $this->provider,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Verify the webhook signature for drivers that defer verification to
     * this job instead of WebhookRequest::authorize() - see ADR-0007.
     *
     * A no-op (returns true) for every other driver, since those were
     * already verified synchronously before this job was ever queued.
     */
    protected function verifyDeferredSignature(PaymentManager $manager): bool
    {
        $config = app('payments.config') ?? config('payments', []);
        if (! ($config['webhook']['verify_signature'] ?? true)) {
            return true;
        }

        try {
            $driver = $manager->driver($this->provider);
        } catch (DriverNotFoundException) {
            return true;
        }

        if (! ($driver instanceof RequiresAsyncWebhookVerification && $driver->requiresAsyncVerification())) {
            return true;
        }

        return $driver->validateWebhook($this->headers, (string) json_encode($this->payload));
    }

    /**
     * Resolve an idempotency key for this delivery: a provider-native event
     * id where the driver supplies one, otherwise a content hash of the
     * payload. See ADR-0005.
     */
    protected function resolveEventKey(PaymentManager $manager): string
    {
        $eventId = null;

        try {
            $driver = $manager->driver($this->provider);
            if (method_exists($driver, 'extractWebhookEventId')) {
                $eventId = $driver->extractWebhookEventId($this->payload);
            }
        } catch (DriverNotFoundException) {
        }

        return $eventId ?? hash('sha256', $this->provider.'|'.json_encode($this->payload));
    }

    protected function extractReference(PaymentManager $manager): ?string
    {
        try {
            return $manager->driver($this->provider)->extractWebhookReference($this->payload);
        } catch (DriverNotFoundException) {
            return null;
        }
    }

    protected function updateTransactionFromWebhook(
        PaymentManager $manager,
        StatusNormalizerInterface $statusNormalizer,
        TransactionRepositoryInterface $transactionRepository,
        string $reference
    ): void {
        try {
            $status = $this->determineStatus($manager, $statusNormalizer);
            $updateData = ['status' => $status];

            $statusEnum = PaymentStatus::tryFromString($status);
            if ($statusEnum?->isSuccessful()) {
                $updateData['paid_at'] = now();
            }

            try {
                $channel = $manager->driver($this->provider)->extractWebhookChannel($this->payload);
                if ($channel) {
                    $updateData['channel'] = $channel;
                }
            } catch (DriverNotFoundException) {
            }

            $updated = $transactionRepository->updateIfNotSuccessful($reference, $updateData);

            if ($updated) {
                $this->log('info', 'Transaction updated from webhook', [
                    'reference' => $reference,
                    'status' => $status,
                    'provider' => $this->provider,
                ]);
            }
        } catch (Throwable $e) {
            $this->log('error', 'Failed to update transaction from webhook', [
                'error' => $e->getMessage(),
                'reference' => $reference,
                'provider' => $this->provider,
            ]);
        }
    }

    protected function determineStatus(PaymentManager $manager, StatusNormalizerInterface $statusNormalizer): string
    {
        try {
            $status = $manager->driver($this->provider)->extractWebhookStatus($this->payload);

            return $statusNormalizer->normalize($status, $this->provider);
        } catch (DriverNotFoundException) {
            $status = $this->payload['status']
                ?? $this->payload['paymentStatus']
                ?? $this->payload['payment_status']
                ?? 'unknown';

            return $statusNormalizer->normalize($status, $this->provider);
        }
    }

    /**
     * Check if the webhook payload is subscription-related.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function isSubscriptionWebhook(array $payload): bool
    {
        $eventType = strtolower($payload['event'] ?? $payload['eventType'] ?? $payload['event_type'] ?? '');

        $subscriptionKeywords = [
            'subscription',
            'invoice.payment_failed',
            'invoice.payment_succeeded',
        ];

        foreach ($subscriptionKeywords as $keyword) {
            if (str_contains($eventType, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Process subscription-related webhook events.
     *
     * Maps provider-specific webhook event types to appropriate event classes.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function processSubscriptionWebhook(array $payload, string $provider, PaymentManager $manager): void
    {
        $eventType = strtolower($payload['event'] ?? $payload['eventType'] ?? $payload['event_type'] ?? '');
        $data = $payload['data'] ?? $payload;

        $subscriptionCode = $data['subscription_code'] ?? $data['subscriptionCode'] ?? $data['subscription'] ?? null;

        if (! $subscriptionCode) {
            $this->log('warning', 'Subscription webhook missing subscription_code', [
                'provider' => $provider,
                'event' => $eventType,
            ]);

            return;
        }

        if (
            str_contains($eventType, 'subscription.create') ||
            str_contains($eventType, 'subscription.created') ||
            str_contains($eventType, 'customer.subscription.created')
        ) {
            SubscriptionCreated::dispatch(
                (string) $subscriptionCode,
                $provider,
                $data
            );
        } elseif (
            str_contains($eventType, 'subscription.success') ||
            str_contains($eventType, 'subscription.renewed') ||
            str_contains($eventType, 'invoice.payment_succeeded') ||
            str_contains($eventType, 'invoice.paid')
        ) {
            $invoiceReference = $data['reference'] ?? $data['invoice_reference'] ?? $data['invoiceReference'] ?? '';

            try {
                $driver = $manager->driver($provider);
                if ($driver instanceof SubscriptionLifecycleHooks) {
                    $driver->beforeSubscriptionRenewal((string) $subscriptionCode);
                    $driver->afterSubscriptionRenewal((string) $subscriptionCode, $invoiceReference);
                }
            } catch (DriverNotFoundException) {
            }

            SubscriptionRenewed::dispatch(
                (string) $subscriptionCode,
                $provider,
                $invoiceReference,
                $data
            );
        } elseif (
            str_contains($eventType, 'subscription.disable') ||
            str_contains($eventType, 'subscription.cancel') ||
            str_contains($eventType, 'subscription.cancelled') ||
            str_contains($eventType, 'customer.subscription.deleted')
        ) {
            SubscriptionCancelled::dispatch(
                (string) $subscriptionCode,
                $provider,
                $data
            );
        } elseif (
            str_contains($eventType, 'invoice.payment_failed') ||
            str_contains($eventType, 'payment.failed') ||
            str_contains($eventType, 'subscription.payment_failed')
        ) {
            $reason = $data['reason'] ?? $data['message'] ?? 'Payment failed';

            try {
                $driver = $manager->driver($provider);
                if ($driver instanceof SubscriptionLifecycleHooks) {
                    $driver->onSubscriptionRenewalFailed((string) $subscriptionCode, $reason);
                }
            } catch (DriverNotFoundException) {
            }

            SubscriptionPaymentFailed::dispatch(
                (string) $subscriptionCode,
                $provider,
                $reason,
                $data
            );
        }

        $this->log('info', 'Subscription webhook event processed', [
            'provider' => $provider,
            'event' => $eventType,
            'subscription_code' => $subscriptionCode,
        ]);
    }
}
