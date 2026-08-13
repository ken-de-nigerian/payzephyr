<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
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
            $refundCurrency = $request->currency ?? $this->config['currencies'][0] ?? 'USD';

            $payload = array_filter([
                'amount' => $request->amount !== null ? [
                    'value' => number_format($request->amount, $this->getCurrencyDecimals($refundCurrency), '.', ''),
                    'currency_code' => $refundCurrency,
                ] : null,
                'note_to_payer' => $request->reason,
            ], fn ($value) => $value !== null);

            $requestOptions = [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
                'json' => $payload,
            ];
            if ($request->idempotencyKey) {
                $requestOptions['headers']['PayPal-Request-Id'] = $request->idempotencyKey;
            }

            $response = $this->makeRequest('POST', "/v2/payments/captures/$request->transactionReference/refund", $requestOptions);
            $data = $this->parseResponse($response);

            if (! isset($data['id'])) {
                throw new RefundException('Failed to create PayPal refund');
            }

            $this->log('info', 'Refund created', [
                'refund_reference' => $data['id'],
                'transaction_reference' => $request->transactionReference,
            ]);

            $refundResponse = new RefundResponseDTO(
                refundReference: $data['id'],
                transactionReference: $request->transactionReference,
                status: $data['status'] ?? 'pending',
                amount: isset($data['amount']['value']) ? (float) $data['amount']['value'] : ($request->amount ?? 0.0),
                currency: $data['amount']['currency_code'] ?? 'USD',
                reason: $data['note_to_payer'] ?? $request->reason,
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
            $response = $this->makeRequest('GET', "/v2/payments/refunds/$refundReference", [
                'headers' => ['Authorization' => 'Bearer '.$this->getAccessToken()],
            ]);
            $data = $this->parseResponse($response);

            if (! isset($data['id'])) {
                throw new RefundException("PayPal refund not found: $refundReference");
            }

            /** @var array<int, array<string, mixed>> $links */
            $links = $data['links'] ?? [];
            $captureLink = collect($links)->firstWhere('rel', 'up');
            $captureId = $captureLink ? basename((string) $captureLink['href']) : '';

            $refundResponse = new RefundResponseDTO(
                refundReference: $data['id'],
                transactionReference: $captureId,
                status: $data['status'] ?? 'unknown',
                amount: isset($data['amount']['value']) ? (float) $data['amount']['value'] : 0.0,
                currency: $data['amount']['currency_code'] ?? 'USD',
                reason: $data['note_to_payer'] ?? null,
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
