<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use KenDeNigerian\PayZephyr\Http\Requests\WebhookRequest;
use KenDeNigerian\PayZephyr\Jobs\ProcessWebhook;
use Throwable;

/**
 * Webhook controller.
 */
final class WebhookController extends Controller
{
    /**
     * Handle webhook request.
     */
    public function handle(WebhookRequest $request, string $provider): JsonResponse
    {
        try {
            ProcessWebhook::dispatch($provider, $request->all());

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

            return response()->json(['message' => 'Webhook received but queuing failed internally'], 500);
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
