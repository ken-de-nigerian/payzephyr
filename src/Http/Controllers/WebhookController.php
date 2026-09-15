<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use KenDeNigerian\PayZephyr\Constants\HttpStatusCodes;
use KenDeNigerian\PayZephyr\Enums\TraceDirection;
use KenDeNigerian\PayZephyr\Enums\TraceEvent;
use KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\Traits\LogsToPaymentChannel;
use KenDeNigerian\PayZephyr\Traits\RecordsTraceEvents;
use Throwable;

final class WebhookController extends Controller
{
    use LogsToPaymentChannel;
    use RecordsTraceEvents;

    public function handle(WebhookRequest $request, string $provider): JsonResponse
    {
        try {
            $payload = $request->all();

            ProcessWebhook::dispatch($provider, $payload, $request->headers->all());

            $this->log('info', 'Webhook queued for processing', [
                'provider' => $provider,
                'ip' => $request->ip(),
            ]);

            return response()->json(['status' => 'queued'], 202);
        } catch (Throwable $e) {
            $this->log('error', 'Webhook queuing failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->trace($this->referenceFor($provider, $request->all()), TraceEvent::WEBHOOK_QUEUE_FAILED, TraceDirection::INBOUND,
                payload: ['error' => $e->getMessage(), 'error_class' => $e::class],
                provider: $provider,
                metadata: ['ip' => $request->ip()],
            );

            return response()->json(['message' => 'Webhook received but queuing failed internally'], HttpStatusCodes::INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Resolve the reference a failed-to-queue webhook was about.
     *
     * Only ever called from the catch block. On the happy path the controller
     * deliberately stays ignorant of the payload's contents - ProcessWebhook
     * extracts the reference once, where it is needed - so this costs a driver
     * resolution only when something has already gone wrong.
     *
     * A webhook that was accepted and never queued is the one failure the job
     * itself can never record, because the job never runs.
     *
     * @param  array<string, mixed>  $payload
     */
    private function referenceFor(string $provider, array $payload): ?string
    {
        try {
            return app(PaymentManager::class)->driver($provider)->extractWebhookReference($payload);
        } catch (Throwable) {
            return null;
        }
    }
}
