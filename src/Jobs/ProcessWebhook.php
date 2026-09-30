<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use KenDeNigerian\PayZephyr\Contracts\RefundRepositoryInterface;
use KenDeNigerian\PayZephyr\Contracts\RequiresAsyncWebhookVerification;
use KenDeNigerian\PayZephyr\Contracts\SendsStatelessWebhooks;
use KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface;
use KenDeNigerian\PayZephyr\Contracts\SubscriptionLifecycleHooks;
use KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface;
use KenDeNigerian\PayZephyr\Contracts\WebhookEventRepositoryInterface;
use KenDeNigerian\PayZephyr\Enums\PaymentStatus;
use KenDeNigerian\PayZephyr\Enums\RefundStatus;
use KenDeNigerian\PayZephyr\Enums\TraceDirection;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Events\RefundCompleted;
use KenDeNigerian\PayZephyr\Events\RefundCreated;
use KenDeNigerian\PayZephyr\Events\RefundFailed;
use KenDeNigerian\PayZephyr\Events\SubscriptionCancelled;
use KenDeNigerian\PayZephyr\Events\SubscriptionCreated;
use KenDeNigerian\PayZephyr\Events\SubscriptionPaymentFailed;
use KenDeNigerian\PayZephyr\Events\SubscriptionRenewed;
use KenDeNigerian\PayZephyr\Events\WebhookReceived;
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Support\PackageConfig;
use KenDeNigerian\PayZephyr\Support\Payload;
use KenDeNigerian\PayZephyr\Traits\LogsToPaymentChannel;
use KenDeNigerian\PayZephyr\Traits\RecordsTraceEvents;
use Throwable;

final class ProcessWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use LogsToPaymentChannel;
    use RecordsTraceEvents;

    public int $tries;

    public int $backoff;

    /**
     * When the delivery reached the application: set at dispatch, inside the
     * webhook request, and serialized with the job. A driver that verifies in
     * the job measures its replay window from here, not from whenever a
     * worker happens to pick the job up. Null only for a job queued by a
     * version that did not record it.
     */
    public ?int $receivedAt = null;

    /**
     * Refund outcome keys this attempt has claimed (see claimRefundOutcome()),
     * released alongside the delivery's own key if the attempt fails.
     *
     * @var array<int, string>
     */
    private array $outcomeKeys = [];

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array<int, string>>  $headers  Only used by
     *                                                      drivers implementing RequiresAsyncWebhookVerification;
     *                                                      harmless to omit for every other provider.
     */
    public function __construct(
        public readonly string $provider,
        public readonly array $payload,
        public readonly array $headers = []
    ) {
        $webhookConfig = PackageConfig::read()->at('webhook');

        $this->receivedAt = time();
        $this->tries = $webhookConfig->int('max_retries') ?? 3;
        $this->backoff = $webhookConfig->int('retry_backoff') ?? 60;
    }

    public function handle(
        PaymentManager $manager,
        StatusNormalizerInterface $statusNormalizer,
        TransactionRepositoryInterface $transactionRepository,
        WebhookEventRepositoryInterface $webhookEventRepository,
        RefundRepositoryInterface $refundRepository
    ): void {
        $eventKey = null;
        $reference = null;
        $this->outcomeKeys = [];

        try {
            $reference = $this->extractReference($manager);

            if (! $this->verifyDeferredSignature($manager)) {
                $this->log('warning', 'Deferred webhook signature verification failed - discarding', [
                    'provider' => $this->provider,
                ]);
                $this->trace($reference, TraceEvent::WEBHOOK_VALIDATION_FAILED, TraceDirection::INBOUND,
                    payload: $this->tracePayload(),
                    provider: $this->provider,
                );

                return;
            }

            // A stateless delivery is never claimed: its body cannot tell one
            // event from the next, so a claim would drop every event after the
            // first. $eventKey stays null, which also leaves nothing to release
            // if processing fails.
            if (! $this->isStatelessDelivery($manager)) {
                $eventKey = $this->resolveEventKey($manager);

                $claimed = $webhookEventRepository->recordIfNew($this->provider, $eventKey);

                if (! $claimed && ! $this->isRetryOfThisDelivery()) {
                    $this->log('info', 'Duplicate webhook delivery skipped', [
                        'provider' => $this->provider,
                        'event_key' => $eventKey,
                    ]);

                    $this->trace($reference, TraceEvent::WEBHOOK_DUPLICATE, TraceDirection::INBOUND,
                        payload: $this->tracePayload(),
                        provider: $this->provider,
                        metadata: ['event_key' => $eventKey],
                    );

                    return;
                }

                if (! $claimed) {
                    // The marker is this job's own, left behind by an attempt that
                    // died before its catch block could release it. Reclaim it
                    // rather than mistaking our own footprint for a duplicate.
                    $this->log('warning', 'Reclaiming an idempotency marker left by a previous attempt', [
                        'provider' => $this->provider,
                        'event_key' => $eventKey,
                        'attempt' => $this->attempts(),
                    ]);
                }
            }

            $this->trace($reference, TraceEvent::WEBHOOK_RECEIVED, TraceDirection::INBOUND,
                payload: $this->tracePayload(),
                provider: $this->provider,
                metadata: ['event_key' => $eventKey],
            );

            if ($reference && PackageConfig::read()->flag(true, 'logging', 'enabled')) {
                $this->updateTransactionFromWebhook($manager, $statusNormalizer, $transactionRepository, $reference);
            }

            if ($this->isSubscriptionWebhook($this->payload)) {
                $this->processSubscriptionWebhook($this->payload, $this->provider, $manager);
            }

            if ($this->isRefundWebhook($this->payload)) {
                $this->processRefundWebhook($this->payload, $this->provider, $refundRepository, $webhookEventRepository);
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

            $this->trace($reference, TraceEvent::WEBHOOK_PROCESSING_FAILED, TraceDirection::INBOUND,
                payload: [
                    'error' => $e->getMessage(),
                    'error_class' => $e::class,
                    'attempt' => $this->attempts(),
                    'max_attempts' => $this->tries,
                ],
                provider: $this->provider,
            );

            if ($this->job !== null && $this->attempts() < $this->tries) {
                $this->trace($reference, TraceEvent::RETRY_SCHEDULED,
                    payload: ['in_seconds' => $this->backoff, 'attempt' => $this->attempts() + 1],
                    provider: $this->provider,
                );
            }

            foreach (array_filter([$eventKey, ...$this->outcomeKeys]) as $key) {
                try {
                    $webhookEventRepository->forget($this->provider, $key);
                } catch (Throwable $forgetError) {
                    $this->log('error', 'Failed to clear the webhook idempotency marker after a failed delivery', [
                        'provider' => $this->provider,
                        'event_key' => $key,
                        'error' => $forgetError->getMessage(),
                    ]);
                }
            }

            throw $e;
        }
    }

    /**
     * Whether this execution is a retry of a delivery this job already claimed.
     *
     * The idempotency marker is written before the work is done and released in
     * a catch block if the work throws. A worker that dies without unwinding -
     * SIGKILL on job timeout, an OOM kill, a PHP fatal - never reaches that
     * catch, so the marker survives with nothing having been processed. The
     * retry then read its own leftover marker as somebody else's duplicate and
     * returned, and the webhook was dropped for good: a charge.success that
     * left the transaction pending forever while the provider recorded a
     * successful delivery.
     *
     * The first attempt of a genuine duplicate delivery is a different job, so
     * its attempt count is 1 and it still skips, which is the behavior the
     * marker exists for. Only attempt two onwards may reclaim.
     *
     * The trade-off is deliberate: reprocessing may re-dispatch events a first
     * attempt already fired. Queues are at-least-once, so listeners have to
     * tolerate that in any case, and a duplicated event is recoverable in a way
     * that a silently discarded payment confirmation is not.
     */
    private function isRetryOfThisDelivery(): bool
    {
        return $this->job !== null && $this->attempts() > 1;
    }

    /**
     * Verify the webhook signature for drivers that defer verification to
     * this job instead of WebhookRequest::authorize().
     *
     * A no-op (returns true) for every other driver, since those were
     * already verified synchronously before this job was ever queued.
     */
    protected function verifyDeferredSignature(PaymentManager $manager): bool
    {
        if (! PackageConfig::read()->flag(true, 'webhook', 'verify_signature')) {
            return true;
        }

        try {
            $driver = $manager->driver($this->provider);
        } catch (DriverNotFoundException) {
            // Deliberately open. A provider that cannot be resolved here was
            // still resolvable in WebhookRequest::authorize(), which refuses an
            // unknown provider outright - so an unverified delivery cannot reach
            // this point over HTTP. For the synchronous drivers, which is all of
            // them but PayPal and Mollie, authorize() has already verified the
            // signature, and answering "unverified" here would discard a
            // delivery that was in fact verified. Covered by
            // ProcessWebhookAdditionalCoverageTest.
            return true;
        }

        if (! ($driver instanceof RequiresAsyncWebhookVerification && $driver->requiresAsyncVerification())) {
            return true;
        }

        $measuresFromReceipt = method_exists($driver, 'setWebhookReceivedAt');

        if ($measuresFromReceipt) {
            $driver->setWebhookReceivedAt($this->receivedAt);
        }

        try {
            return $driver->validateWebhook($this->headers, (string) json_encode($this->payload));
        } finally {
            // Drivers live for the whole worker process; the next job must not
            // inherit this delivery's receipt time.
            if ($measuresFromReceipt) {
                $driver->setWebhookReceivedAt(null);
            }
        }
    }

    /**
     * Whether the driver reports this delivery as one whose body carries no
     * event identity (see SendsStatelessWebhooks).
     */
    protected function isStatelessDelivery(PaymentManager $manager): bool
    {
        try {
            $driver = $manager->driver($this->provider);
        } catch (DriverNotFoundException) {
            return false;
        }

        return $driver instanceof SendsStatelessWebhooks && $driver->isStatelessWebhook($this->payload);
    }

    /**
     * Resolve an idempotency key for this delivery: a provider-native event
     * id where the driver supplies one, otherwise a content hash of the
     * payload.
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

    /**
     * The webhook body, if trace is configured to keep provider bodies.
     *
     * webhook_events stores only a dedup key, so this is the only place the
     * contents of a delivery survive. Gated by the same switch that governs
     * provider request and response bodies, since it is the same category of
     * data and the same reason to be able to turn it off.
     *
     * @return array<string, mixed>
     */
    private function tracePayload(): array
    {
        return $this->traceRecordsHttpBodies() ? $this->payload : [];
    }

    /**
     * Record that PayZephyr has stopped retrying this delivery.
     *
     * Laravel calls this once the final attempt has failed, which is the only
     * point at which "abandoned" is true rather than guessed. The catch block
     * in handle() cannot know it is the last attempt, so it records the
     * failure and, separately, a retry only when one is really coming.
     */
    public function failed(Throwable $exception): void
    {
        $reference = null;

        try {
            $reference = $this->extractReference(app(PaymentManager::class));
        } catch (Throwable) {
            // Without a reference there is nothing to hang the event off, and
            // a job that has already failed is not worth a second exception.
        }

        $this->trace($reference, TraceEvent::RETRY_ABANDONED,
            payload: [
                'error' => $exception->getMessage(),
                'error_class' => $exception::class,
                'attempts' => $this->tries,
            ],
            provider: $this->provider,
        );
    }

    protected function updateTransactionFromWebhook(
        PaymentManager $manager,
        StatusNormalizerInterface $statusNormalizer,
        TransactionRepositoryInterface $transactionRepository,
        string $reference
    ): void {
        try {
            $status = $this->determineStatus($manager, $statusNormalizer);

            if ($status === 'unknown') {
                $this->log('info', 'Webhook carried no recognisable payment status - transaction left unchanged', [
                    'provider' => $this->provider,
                    'reference' => $reference,
                ]);

                return;
            }

            $updateData = ['status' => $status];

            $statusEnum = PaymentStatus::tryFromString($status);
            if ($statusEnum?->isSuccessful()) {
                $updateData['paid_at'] = now();
            }

            // No DriverNotFoundException guard: $reference came from this same
            // driver (extractReference() returns null when it cannot be
            // resolved), and the manager caches it, so resolving it again
            // here cannot fail.
            $channel = $manager->driver($this->provider)->extractWebhookChannel($this->payload);
            if ($channel) {
                $updateData['channel'] = $channel;
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
            $body = new Payload($this->payload);
            $status = $body->string('status')
                ?? $body->string('paymentStatus')
                ?? $body->string('payment_status')
                ?? 'unknown';

            return $statusNormalizer->normalize($status, $this->provider);
        }
    }

    /**
     * The event name, wherever this provider puts it, lower-cased.
     *
     * @param  array<string, mixed>  $payload
     */
    private function eventType(array $payload): string
    {
        $body = new Payload($payload);

        return strtolower($body->string('event') ?? $body->string('eventType') ?? $body->string('event_type') ?? '');
    }

    /**
     * Check if the webhook payload is subscription-related.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function isSubscriptionWebhook(array $payload): bool
    {
        $eventType = $this->eventType($payload);

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
        $eventType = $this->eventType($payload);
        $data = Payload::of($payload)->arrayOrNull('data') ?? $payload;
        $details = new Payload($data);

        $subscriptionCode = $details->string('subscription_code') ?? $details->string('subscriptionCode') ?? $details->string('subscription');

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
            $this->trace($subscriptionCode, TraceEvent::SUBSCRIPTION_CREATED, TraceDirection::INBOUND,
                payload: ['event' => $eventType],
                provider: $provider,
            );

            SubscriptionCreated::dispatch(
                $subscriptionCode,
                $provider,
                $data
            );
        } elseif (
            str_contains($eventType, 'subscription.success') ||
            str_contains($eventType, 'subscription.renewed') ||
            str_contains($eventType, 'invoice.payment_succeeded') ||
            str_contains($eventType, 'invoice.paid')
        ) {
            $invoiceReference = $details->string('reference') ?? $details->string('invoice_reference') ?? $details->string('invoiceReference') ?? '';

            try {
                $driver = $manager->driver($provider);
                if ($driver instanceof SubscriptionLifecycleHooks) {
                    $driver->beforeSubscriptionRenewal($subscriptionCode);
                    $driver->afterSubscriptionRenewal($subscriptionCode, $invoiceReference);
                }
            } catch (DriverNotFoundException) {
            }

            $this->trace($subscriptionCode, TraceEvent::SUBSCRIPTION_RENEWED, TraceDirection::INBOUND,
                payload: ['event' => $eventType, 'invoice_reference' => $invoiceReference],
                provider: $provider,
            );

            SubscriptionRenewed::dispatch(
                $subscriptionCode,
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
            $this->trace($subscriptionCode, TraceEvent::SUBSCRIPTION_CANCELLED, TraceDirection::INBOUND,
                payload: ['event' => $eventType],
                provider: $provider,
            );

            SubscriptionCancelled::dispatch(
                $subscriptionCode,
                $provider,
                $data
            );
        } elseif (
            str_contains($eventType, 'invoice.payment_failed') ||
            str_contains($eventType, 'payment.failed') ||
            str_contains($eventType, 'subscription.payment_failed')
        ) {
            $reason = $details->string('reason') ?? $details->string('message') ?? 'Payment failed';

            $this->trace($subscriptionCode, TraceEvent::SUBSCRIPTION_PAYMENT_FAILED, TraceDirection::INBOUND,
                payload: ['event' => $eventType, 'reason' => $reason],
                provider: $provider,
            );

            try {
                $driver = $manager->driver($provider);
                if ($driver instanceof SubscriptionLifecycleHooks) {
                    $driver->onSubscriptionRenewalFailed($subscriptionCode, $reason);
                }
            } catch (DriverNotFoundException) {
            }

            SubscriptionPaymentFailed::dispatch(
                $subscriptionCode,
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

    /**
     * Check if the webhook payload is refund-related.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function isRefundWebhook(array $payload): bool
    {
        $eventType = $this->eventType($payload);

        return str_contains($eventType, 'refund') || str_contains($eventType, 'charge.refunded');
    }

    /**
     * Process refund-related webhook events.
     *
     * Several providers (Paystack, PayPal, Square) confirm refunds
     * asynchronously - the initial refund() response is only "pending", and
     * this is where the terminal RefundCompleted/RefundFailed event actually
     * fires. Reference field names vary widely across providers' refund
     * webhook shapes, so this resolves the refund/transaction reference from
     * every known field rather than a single fixed path.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function processRefundWebhook(
        array $payload,
        string $provider,
        RefundRepositoryInterface $refundRepository,
        WebhookEventRepositoryInterface $webhookEventRepository
    ): void {
        $eventType = $this->eventType($payload);
        // Where a provider puts the refund: `data` (Paystack, Stripe, Paddle),
        // `resource` (PayPal), `eventData` (Monnify), or the top level.
        $body = new Payload($payload);
        $data = $body->arrayOrNull('data') ?? $body->arrayOrNull('resource') ?? $body->arrayOrNull('eventData') ?? $payload;
        $details = new Payload($data);
        $object = new Payload($details->arrayOrNull('object') ?? $body->arrayOrNull('payload', 'refund', 'entity') ?? $data);

        $refundReference = $object->string('id')
            ?? $object->string('refund_reference')
            ?? $details->string('refundReference');

        $transactionReference = $object->string('transaction_reference')
            ?? $object->string('payment_intent')
            ?? $details->string('transactionReference')
            ?? $object->string('transaction', 'reference')
            ?? $object->string('notes', 'payzephyr_reference')
            ?? ($provider === 'razorpay' ? $object->string('payment_id') : null);

        if (! $refundReference) {
            $this->log('warning', 'Refund webhook missing refund reference', [
                'provider' => $provider,
                'event' => $eventType,
            ]);

            return;
        }

        $status = strtolower($object->string('status') ?? $details->string('status') ?? $details->string('refundStatus') ?? '');

        if (
            str_contains($eventType, 'failed') ||
            in_array($status, ['failed', 'declined', 'error'], true)
        ) {
            $reason = $object->string('reason') ?? $object->string('message') ?? $details->string('reason') ?? 'Refund failed';

            $this->persistRefundStatus($refundRepository, $refundReference, RefundStatus::FAILED);

            if ($this->claimRefundOutcome($webhookEventRepository, $refundReference, RefundStatus::FAILED)) {
                $this->trace($transactionReference, TraceEvent::REFUND_FAILED, TraceDirection::INBOUND,
                    payload: ['stage' => 'settlement', 'refund_reference' => $refundReference, 'reason' => $reason],
                    provider: $provider,
                );

                RefundFailed::dispatch(
                    $refundReference,
                    $transactionReference ?? '',
                    $provider,
                    $reason,
                    $data
                );
            }
        } elseif (
            str_contains($eventType, 'processed') ||
            str_contains($eventType, 'refunded') ||
            str_contains($eventType, 'completed') ||
            in_array($status, ['completed', 'succeeded', 'success', 'processed', 'refunded'], true)
        ) {
            $this->persistRefundStatus($refundRepository, $refundReference, RefundStatus::COMPLETED);

            if ($this->claimRefundOutcome($webhookEventRepository, $refundReference, RefundStatus::COMPLETED)) {
                $this->trace($transactionReference, TraceEvent::PAYMENT_REFUNDED, TraceDirection::INBOUND,
                    payload: ['refund_reference' => $refundReference],
                    provider: $provider,
                );

                RefundCompleted::dispatch(
                    $refundReference,
                    $transactionReference ?? '',
                    $provider,
                    $data
                );
            }
        } else {
            RefundCreated::dispatch(
                $refundReference,
                $transactionReference ?? '',
                $provider,
                $data
            );
        }

        $this->log('info', 'Refund webhook event processed', [
            'provider' => $provider,
            'event' => $eventType,
            'refund_reference' => $refundReference,
        ]);
    }

    /**
     * Persist a webhook-confirmed terminal refund status to
     * refund_transactions, so the local row - and the duplicate/over-refund
     * guards in RefundValidator that depend on it - actually reflects
     * reality for providers that confirm refunds asynchronously (Paystack,
     * Stripe, Square, ...). Without this, RefundCompleted/RefundFailed only
     * ever fired as an in-memory event and the local row stayed "pending"
     * forever unless the application separately called Refund::fetch().
     *
     * Best-effort and additive only: skips silently (via
     * updateStatusIfExists()) when the row doesn't exist locally, when
     * refund logging is disabled, or when the repository call itself
     * fails - a webhook must never fail webhook processing over a
     * bookkeeping write.
     */
    /**
     * Claim the right to announce a refund's outcome, so RefundCompleted or
     * RefundFailed fires once per refund however many webhooks report it.
     *
     * Providers routinely report one outcome more than once: Razorpay sends an
     * instant refund as refund.created already processed, then again as
     * refund.processed. Those are different deliveries - both are processed -
     * but the refund completed once. The claim reuses the webhook_events table
     * with a key of its own, and is released with the delivery's if this
     * attempt fails, so a retry can still announce it. A retry of this same
     * delivery reclaims its own leftover claim, as it does the delivery key.
     */
    protected function claimRefundOutcome(
        WebhookEventRepositoryInterface $webhookEventRepository,
        string $refundReference,
        RefundStatus $outcome
    ): bool {
        $key = 'refund.'.$outcome->value.':'.$refundReference;

        if ($webhookEventRepository->recordIfNew($this->provider, $key) || $this->isRetryOfThisDelivery()) {
            $this->outcomeKeys[] = $key;

            return true;
        }

        $this->log('info', 'Refund outcome already reported - not dispatching it again', [
            'provider' => $this->provider,
            'refund_reference' => $refundReference,
            'outcome' => $outcome->value,
        ]);

        return false;
    }

    protected function persistRefundStatus(RefundRepositoryInterface $refundRepository, string $refundReference, RefundStatus $status): void
    {
        $config = PackageConfig::read();
        $loggingEnabled = $config->flag($config->flag(true, 'logging', 'enabled'), 'refunds', 'logging', 'enabled');

        if (! $loggingEnabled) {
            return;
        }

        try {
            $refundRepository->updateStatusIfExists($refundReference, $status->value);
        } catch (Throwable $e) {
            $this->log('error', 'Failed to update refund status from webhook', [
                'error' => $e->getMessage(),
                'refund_reference' => $refundReference,
                'status' => $status->value,
            ]);
        }
    }
}
