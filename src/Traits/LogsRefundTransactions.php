<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Services\MetadataSanitizer;
use Throwable;

/**
 * Persists refund transactions to the database. Shared by every
 * refund-capable driver, mirroring LogsSubscriptionTransactions.
 *
 * Requires the consuming class to also use LogsToPaymentChannel (for log())
 * and to expose getRefundRepository() (AbstractDriver already does).
 */
trait LogsRefundTransactions
{
    /**
     * Log refund transaction to database.
     *
     * Respects the config('payments.refunds.logging.enabled') setting and
     * handles errors gracefully to prevent breaking refund operations. Will
     * create a new record or update an existing one if the refund_reference
     * already exists.
     */
    protected function logRefund(
        RefundRequestDTO $request,
        RefundResponseDTO $response
    ): void {
        $this->logRefundFromResponse($response, $request->reason);
    }

    /**
     * Log refund transaction from response DTO.
     *
     * Used when we don't have the original request DTO, such as when
     * updating refund status from webhooks or after a fetch operation.
     */
    protected function logRefundFromResponse(
        RefundResponseDTO $response,
        ?string $reason = null
    ): void {
        $config = app('payments.config') ?? config('payments', []);
        $loggingEnabled = $config['refunds']['logging']['enabled'] ?? ($config['logging']['enabled'] ?? true);

        if (! $loggingEnabled) {
            return;
        }

        try {
            $reason = $reason ?? $response->reason;
            $sanitizer = app(MetadataSanitizer::class);
            $sanitizedMetadata = $sanitizer->sanitize($response->metadata);
            $sanitizedReason = $reason !== null ? $sanitizer->sanitize($reason) : null;

            // Concurrency-safe create-or-update (lock + create-race retry).
            $this->getRefundRepository()->updateOrCreateAtomic(
                $response->refundReference,
                [
                    'transaction_reference' => $response->transactionReference,
                    'provider' => $this->getName(),
                    'status' => $response->getStatus()->value,
                    'amount' => $response->amount,
                    'currency' => $response->currency,
                    'reason' => $sanitizedReason,
                    'metadata' => $sanitizedMetadata,
                ]
            );
        } catch (Throwable $e) {
            $this->log('error', 'Failed to log refund transaction', [
                'error' => $e->getMessage(),
                'refund_reference' => $response->refundReference,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
