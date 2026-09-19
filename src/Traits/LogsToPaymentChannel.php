<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

trait LogsToPaymentChannel
{
    /**
     * Write to the payment log channel. Guaranteed not to throw.
     *
     * Logging here is diagnostic: it describes payments, it does not make them
     * happen. A charge must never fail because PayZephyr could not write a
     * line about it, and several call sites log from inside a catch block on
     * the payment path - PaymentManager::getCacheContext() runs before the
     * in-flight claim is even taken - where an exception escaping would
     * surface as a failed payment for a customer whose card was fine.
     *
     * The cost is that a genuinely broken log channel goes unreported by this
     * method. That is the right side of the trade: a lost log line is
     * recoverable, a payment reported as failed after the money moved is not.
     *
     * @param  array<string, mixed>  $context
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        try {
            $config = app('payments.config') ?? config('payments', []);
            $channelName = $config['logging']['channel'] ?? 'payments';

            try {
                Log::channel($channelName)->{$level}($message, $context);

                return;
            } catch (InvalidArgumentException) {
                // No such channel configured - fall back to the default logger.
            }

            Log::{$level}($message, $context);
        } catch (Throwable) {
            // Nothing left to try, and nothing worth failing a payment over.
        }
    }
}
