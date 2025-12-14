<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface;
use KenDeNigerian\PayZephyr\Enums\PaymentStatus;
use KenDeNigerian\PayZephyr\Events\WebhookReceived;
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;
use KenDeNigerian\PayZephyr\PaymentManager;
use Throwable;

/**
 * Process webhook job.
 */
class ProcessWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $provider,
        public readonly array $payload
    ) {}

    /**
     * Execute job.
     */
    public function handle(PaymentManager $manager, StatusNormalizerInterface $statusNormalizer): void
    {
        try {
            $reference = $this->extractReference($manager);

            $config = app('payments.config') ?? config('payments', []);
            if ($reference && ($config['logging']['enabled'] ?? true)) {
                $this->updateTransactionFromWebhook($manager, $statusNormalizer, $reference);
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
     * Extract reference from payload.
     */
    protected function extractReference(PaymentManager $manager): ?string
    {
        try {
            return $manager->driver($this->provider)->extractWebhookReference($this->payload);
        } catch (DriverNotFoundException) {
            return null;
        }
    }

    /**
     * Update transaction from webhook.
     */
    protected function updateTransactionFromWebhook(
        PaymentManager $manager,
        StatusNormalizerInterface $statusNormalizer,
        string $reference
    ): void {
        try {
            DB::transaction(function () use ($manager, $statusNormalizer, $reference) {
                $transaction = PaymentTransaction::where('reference', $reference)
                    ->lockForUpdate() // Prevent race conditions
                    ->first();

                if (! $transaction) {
                    return;
                }

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

                $transaction->update($updateData);

                $this->log('info', 'Transaction updated from webhook', [
                    'reference' => $reference,
                    'status' => $status,
                    'provider' => $this->provider,
                ]);
            });
        } catch (Throwable $e) {
            $this->log('error', 'Failed to update transaction from webhook', [
                'error' => $e->getMessage(),
                'reference' => $reference,
                'provider' => $this->provider,
            ]);
        }
    }

    /**
     * Determine status from webhook.
     */
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
     * Log message, using payments channel if available, otherwise default channel.
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        try {
            Log::channel('payments')->{$level}($message, $context);
        } catch (InvalidArgumentException) {
            Log::{$level}($message, $context);
        }
    }
}
