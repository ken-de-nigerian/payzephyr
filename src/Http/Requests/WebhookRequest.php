<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Cache;
use KenDeNigerian\PayZephyr\Contracts\RequiresAsyncWebhookVerification;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Traits\LogsToPaymentChannel;
use Throwable;

class WebhookRequest extends FormRequest
{
    use LogsToPaymentChannel;

    private const UNVERIFIED_WARNING_CACHE_KEY = 'payzephyr:webhook:unverified_warning';

    /** Matches the health endpoint's warning interval. */
    private const UNVERIFIED_WARNING_INTERVAL_SECONDS = 3600;

    /**
     * Authorize webhook request.
     */
    public function authorize(): bool
    {
        $config = app('payments.config') ?? config('payments', []);
        $webhookConfig = $config['webhook'] ?? [];

        $maxPayloadSize = $webhookConfig['max_payload_size'] ?? 1048576;
        $contentLength = $this->header('Content-Length');
        $bodySize = strlen($this->getContent());

        if ($contentLength && (int) $contentLength > $maxPayloadSize) {
            $this->log('warning', 'Webhook payload size exceeds limit', [
                'size' => $contentLength,
                'max' => $maxPayloadSize,
                'ip' => $this->ip(),
            ]);

            return false;
        }

        if ($bodySize > $maxPayloadSize) {
            $this->log('warning', 'Webhook payload size exceeds limit', [
                'size' => $bodySize,
                'max' => $maxPayloadSize,
                'ip' => $this->ip(),
            ]);

            return false;
        }

        if (! ($webhookConfig['verify_signature'] ?? true)) {
            $this->warnOnceIfSignatureVerificationDisabled();

            return true;
        }

        $provider = $this->route('provider');

        try {
            $manager = app(PaymentManager::class);
            $driver = $manager->driver($provider);

            if ($driver instanceof RequiresAsyncWebhookVerification && $driver->requiresAsyncVerification()) {
                return true;
            }

            return $driver->validateWebhook(
                $this->headers->all(),
                $this->getContent()
            );
        } catch (Throwable $e) {
            $this->log('warning', "Webhook authorization failed for provider [$provider]", [
                'error' => $e->getMessage(),
                'ip' => $this->ip(),
            ]);

            return false;
        }
    }

    /**
     * Say so, loudly and repeatedly, when webhook signature verification is off
     * in production.
     *
     * This is the most dangerous switch the package has. With it off, anyone who
     * can reach the webhook URL can POST a charge.success for a reference they
     * have guessed or observed, and the transaction is marked paid without a
     * payment. The endpoint is public by necessity, so there is nothing else
     * standing in the way.
     *
     * It stays supported, because local development and replaying captured
     * payloads both need it, but it should never be silent. Rate-limited the
     * same way the health endpoint's warning is, so an active site does not
     * drown its own log.
     */
    private function warnOnceIfSignatureVerificationDisabled(): void
    {
        if (app()->environment(['local', 'testing'])) {
            return;
        }

        try {
            if (! Cache::add(self::UNVERIFIED_WARNING_CACHE_KEY, true, self::UNVERIFIED_WARNING_INTERVAL_SECONDS)) {
                return;
            }
        } catch (Throwable) {
            // A cache that cannot answer must not silence the warning.
        }

        $this->log('error', 'Webhook signature verification is DISABLED - this endpoint will accept forged payment notifications', [
            'hint' => 'Unset PAYMENTS_WEBHOOK_VERIFY_SIGNATURE (or set it to true) and configure a webhook secret for every enabled provider. See docs/security.md.',
            'ip' => $this->ip(),
        ]);
    }

    /**
     * Get validation rules.
     *
     * @return array<string, ValidationRule|array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            'event' => 'sometimes|string',
            'eventType' => 'sometimes|string',
            'event_type' => 'sometimes|string',
            'data' => 'sometimes|array',
            'reference' => 'sometimes|string',
            'status' => 'sometimes|string',
            'paymentStatus' => 'sometimes|string',
            'payment_status' => 'sometimes|string',
        ];
    }
}
