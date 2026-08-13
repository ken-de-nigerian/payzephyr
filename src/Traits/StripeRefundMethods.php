<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use Stripe\Exception\ApiErrorException;

/**
 * Refund support for StripeDriver.
 *
 * Stripe's refunds resource is first-class (unlike subscriptions, which had
 * to be modeled on top of Prices/Products). $transactionReference is treated
 * as a PaymentIntent ID, matching how StripeDriver::verify() already
 * resolves references for 'pi_'-prefixed values.
 */
trait StripeRefundMethods
{
    use LogsRefundTransactions;

    public function refund(RefundRequestDTO $request): RefundResponseDTO
    {
        try {
            $params = array_filter([
                'payment_intent' => $request->transactionReference,
                'amount' => $request->getAmountInMinorUnits(),
                'metadata' => $request->metadata ?: null,
            ], fn ($value) => $value !== null);

            $options = $request->idempotencyKey ? ['idempotency_key' => $request->idempotencyKey] : [];

            $refund = $this->stripe->refunds->create($params, $options);

            $this->log('info', 'Refund created', [
                'refund_reference' => $refund->id,
                'transaction_reference' => $request->transactionReference,
            ]);

            $response = $this->mapStripeRefundToResponse($refund, $request->reason);
            $this->logRefund($request, $response);

            return $response;
        } catch (ApiErrorException $e) {
            $this->log('error', 'Failed to create refund', ['error' => $e->getMessage()]);
            throw new RefundException('Failed to create refund: '.$e->getMessage(), 0, $e);
        }
    }

    public function fetchRefund(string $refundReference): RefundResponseDTO
    {
        try {
            $refund = $this->stripe->refunds->retrieve($refundReference);

            $response = $this->mapStripeRefundToResponse($refund);
            $this->logRefundFromResponse($response);

            return $response;
        } catch (ApiErrorException $e) {
            $this->log('error', 'Failed to fetch refund', [
                'refund_reference' => $refundReference,
                'error' => $e->getMessage(),
            ]);
            throw new RefundException('Failed to fetch refund: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @param  object{id: string, payment_intent?: string|null, status: string, amount?: int|null, currency: string, metadata?: array<string, mixed>|object}  $refund
     */
    private function mapStripeRefundToResponse(object $refund, ?string $reason = null): RefundResponseDTO
    {
        return new RefundResponseDTO(
            refundReference: $refund->id,
            transactionReference: (string) ($refund->payment_intent ?? ''),
            status: $refund->status,
            amount: ($refund->amount ?? 0) / 100,
            currency: strtoupper($refund->currency),
            reason: $reason,
            metadata: (array) ($refund->metadata ?? []),
            provider: $this->getName(),
        );
    }
}
