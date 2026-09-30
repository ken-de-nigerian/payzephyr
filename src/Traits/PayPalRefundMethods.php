<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use KenDeNigerian\PayZephyr\Support\Payload;
use Throwable;

/**
 * Refund support for PayPalDriver.
 *
 * $transactionReference is the PayPal capture id (the same id
 * PayPalDriver::verify()/captureOrder() resolve internally, exposed as
 * metadata['capture_id'] on VerificationResponseDTO) - PayPal refunds are
 * issued against a capture, not the order.
 */
trait PayPalRefundMethods
{
    use LogsRefundTransactions;

    public function refund(RefundRequestDTO $request): RefundResponseDTO
    {
        try {
            $refundCurrency = $request->currency ?? $this->settings()->string('currencies', 0) ?? 'USD';

            $payload = array_filter([
                'amount' => $request->amount !== null ? [
                    'value' => number_format($request->amount, $this->getCurrencyDecimals($refundCurrency), '.', ''),
                    'currency_code' => $refundCurrency,
                ] : null,
                'note_to_payer' => $request->reason,
            ], fn ($value): bool => $value !== null);

            $requestOptions = [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
                'json' => $payload,
            ];
            if ($request->idempotencyKey) {
                $requestOptions['headers']['PayPal-Request-Id'] = $request->idempotencyKey;
            }

            $response = $this->makeRequest('POST', '/v2/payments/captures/'.rawurlencode($request->transactionReference).'/refund', $requestOptions);
            $data = $this->parseResponse($response);
            $body = new Payload($data);
            $refundId = $body->string('id');

            if ($refundId === null) {
                throw new RefundException('Failed to create PayPal refund');
            }

            $this->log('info', 'Refund created', [
                'refund_reference' => $refundId,
                'transaction_reference' => $request->transactionReference,
            ]);

            $refundResponse = new RefundResponseDTO(
                refundReference: $refundId,
                transactionReference: $request->transactionReference,
                status: $body->string('status') ?? 'pending',
                amount: $this->requireAmountValue($body->get('amount', 'value') ?? $request->amount, 'amount.value', 'refund'),
                currency: $this->requireString($this->requireArray($data, 'amount', 'refund'), 'currency_code', 'refund'),
                reason: $body->string('note_to_payer') ?? $request->reason,
                metadata: $request->metadata,
                provider: $this->getName(),
            );

            $this->logRefund($request, $refundResponse);

            return $refundResponse;
        } catch (RefundException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to create refund', ['error' => $e->getMessage()]);
            throw new RefundException('Failed to create refund: '.$e->getMessage(), 0, $e);
        }
    }

    public function fetchRefund(string $refundReference): RefundResponseDTO
    {
        try {
            $response = $this->makeRequest('GET', '/v2/payments/refunds/'.rawurlencode($refundReference), [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
            ]);
            $data = $this->parseResponse($response);
            $body = new Payload($data);
            $refundId = $body->string('id');

            if ($refundId === null) {
                throw new RefundException("PayPal refund not found: $refundReference");
            }

            // The refunded capture is the refund's `up` link.
            $captureLink = $this->linkHref($data, 'up');
            $captureId = $captureLink === null ? '' : basename($captureLink);

            $refundResponse = new RefundResponseDTO(
                refundReference: $refundId,
                transactionReference: $captureId,
                status: $body->string('status') ?? 'unknown',
                amount: $this->requireAmount($this->requireArray($data, 'amount', 'fetch refund'), 'value', 'fetch refund'),
                currency: $this->requireString($this->requireArray($data, 'amount', 'fetch refund'), 'currency_code', 'fetch refund'),
                reason: $body->string('note_to_payer'),
                metadata: [],
                provider: $this->getName(),
            );

            $this->logRefundFromResponse($refundResponse);

            return $refundResponse;
        } catch (RefundException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to fetch refund', [
                'refund_reference' => $refundReference,
                'error' => $e->getMessage(),
            ]);
            throw new RefundException('Failed to fetch refund: '.$e->getMessage(), 0, $e);
        }
    }
}
