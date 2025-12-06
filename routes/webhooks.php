<?php

use Illuminate\Support\Facades\Route;
use KenDeNigerian\PayZephyr\Http\Controllers\WebhookController;

/**
 * Webhook Routes - Payment Provider Notifications
 *
 * These routes receive webhooks (POST requests) from payment providers
 * when a payment status changes (success, failure, etc.).
 *
 * The {provider} parameter tells us which provider sent the webhook
 * (e.g., 'paystack', 'stripe', 'paypal').
 *
 * Routes are automatically registered when the package is loaded.
 * You can customize the path and middleware in config/payments.php.
 */
Route::post(
    config('payments.webhook.path', '/payments/webhook').'/{provider}',
    [WebhookController::class, 'handle']
)->middleware([
    'api',
    'throttle:60,1', // 60 requests per minute per IP
])->name('payments.webhook');
