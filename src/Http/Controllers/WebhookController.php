<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use KenDeNigerian\PayZephyr\Constants\HttpStatusCodes;
use KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use KenDeNigerian\PayZephyr\Traits\LogsToPaymentChannel;
use Throwable;

final class WebhookController extends Controller
{
    use LogsToPaymentChannel;

    public function handle(WebhookRequest $request, string $provider): JsonResponse
    {
        try {
            $payload = $request->all();

            // Headers are only actually needed by drivers implementing
            // RequiresAsyncWebhookVerification (see ADR-0007); passed
            // through for every provider for simplicity rather than
            // conditionally checking driver type in the controller.
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

            return response()->json(['message' => 'Webhook received but queuing failed internally'], HttpStatusCodes::INTERNAL_SERVER_ERROR);
        }
    }
}
