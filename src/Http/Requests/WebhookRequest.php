<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use KenDeNigerian\PayZephyr\Contracts\RequiresAsyncWebhookVerification;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Traits\LogsToPaymentChannel;
use Throwable;

class WebhookRequest extends FormRequest
{
    use LogsToPaymentChannel;

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
            return true;
        }

        $provider = $this->route('provider');

        try {
            $manager = app(PaymentManager::class);
            $driver = $manager->driver($provider);

            if ($driver instanceof RequiresAsyncWebhookVerification && $driver->requiresAsyncVerification()) {
                // Deferred to ProcessWebhook - see ADR-0007/ADR-0008. The
                // payload-size cap above still applies; only the (I/O-bound)
                // signature check itself is skipped here.
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
