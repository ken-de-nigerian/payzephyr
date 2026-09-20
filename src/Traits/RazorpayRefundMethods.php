<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use Throwable;

/**
 * Refund support for RazorpayDriver.
 *
 * Razorpay refunds a payment (pay_...), not a Payment Link, so
 * $transactionReference - normally the package reference, the same value
 * Payment::verify() takes - is resolved to the link's single captured payment
 * first. A plink_ or pay_ id is accepted as well. The refund's currency comes
 * from Razorpay, never from config, because it decides the minor-unit amount.
 *
 * Refunds start "pending" and settle via the refund.processed / refund.failed
 * webhooks. The package reference is written to the refund's notes so
 * fetchRefund() and those webhooks can report it back instead of the pay_ id:
 * refund_transactions rows are matched on it by the over-refund guard.
 */
trait RazorpayRefundMethods
{
    use LogsRefundTransactions;

    public function refund(RefundRequestDTO $request): RefundResponseDTO
    {
        try {
            $payment = $this->resolveRefundablePayment($request->transactionReference);
            $paymentId = $payment['id'];
            $currency = $payment['currency'];

            $payload = [
                'amount' => $request->amount !== null
                    ? $this->toMinorUnits($request->amount, $currency)
                    : $payment['refundable'],
                'speed' => $this->config['refund_speed'] ?? 'normal',
                'notes' => $this->buildNotes(
                    array_merge(array_filter(['reason' => $request->reason]), $request->metadata),
                    $request->transactionReference
                ),
            ];

            $requestOptions = ['json' => $payload];
            if ($request->idempotencyKey !== null) {
                if (preg_match('/^[A-Za-z0-9_-]{10,}$/', $request->idempotencyKey) !== 1) {
                    throw new RefundException(
                        'Razorpay will not accept this idempotency key, and PayZephyr will not send a refund '.
                        'without the protection you asked for. Keys must be at least 10 characters of letters, '.
                        'digits, hyphens or underscores.'
                    );
                }

                $requestOptions['headers'] = ['X-Refund-Idempotency' => $request->idempotencyKey];
            }

            $response = $this->makeRequest('POST', '/v1/payments/'.rawurlencode($paymentId).'/refund', $requestOptions);
            $data = $this->parseResponse($response);

            if (empty($data['id'])) {
                throw new RefundException('Razorpay did not return a refund id');
            }

            $responseCurrency = strtoupper((string) ($data['currency'] ?? $currency));
            $refundedMinorUnits = $data['amount'] ?? $payload['amount'];

            $this->log('info', 'Refund created', [
                'refund_reference' => (string) $data['id'],
                'transaction_reference' => $request->transactionReference,
                'razorpay_payment_id' => $paymentId,
            ]);

            $refundResponse = new RefundResponseDTO(
                refundReference: (string) $data['id'],
                transactionReference: $request->transactionReference,
                status: (string) ($data['status'] ?? 'pending'),
                amount: $this->fromMinorUnits($refundedMinorUnits, $responseCurrency),
                currency: $responseCurrency,
                reason: $request->reason,
                metadata: $request->metadata,
                provider: $this->getName(),
            );

            $this->logRefund($request, $refundResponse);

            return $refundResponse;
        } catch (RefundException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to create refund', ['error' => $e->getMessage()]);
            throw new RefundException('Failed to create refund: '.$this->describeFailure($e), 0, $e);
        }
    }

    public function fetchRefund(string $refundReference): RefundResponseDTO
    {
        try {
            $response = $this->makeRequest('GET', '/v1/refunds/'.rawurlencode($refundReference));
            $data = $this->parseResponse($response);

            if (empty($data['id'])) {
                throw new RefundException("Razorpay refund [$refundReference] not found");
            }

            $currency = strtoupper($this->requireString($data, 'currency', 'refund'));
            $amount = $this->requireAmount($data, 'amount', 'refund');
            $notes = is_array($data['notes'] ?? null) ? $data['notes'] : [];

            $refundResponse = new RefundResponseDTO(
                refundReference: (string) $data['id'],
                transactionReference: (string) ($notes['payzephyr_reference'] ?? $data['payment_id'] ?? ''),
                status: (string) ($data['status'] ?? 'pending'),
                amount: $this->fromMinorUnits($amount, $currency),
                currency: $currency,
                reason: isset($notes['reason']) ? (string) $notes['reason'] : null,
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
            throw new RefundException('Failed to fetch refund: '.$this->describeFailure($e), 0, $e);
        }
    }

    /**
     * Resolve the payment to refund. Its status, currency and the amount still
     * refundable come from the payment itself, which Razorpay keeps current
     * across partial refunds.
     *
     * @return array{id: string, currency: string, refundable: int} refundable is in minor units
     *
     * @throws RefundException
     */
    private function resolveRefundablePayment(string $transactionReference): array
    {
        $paymentId = $transactionReference;

        if (! str_starts_with($transactionReference, 'pay_')) {
            $link = $this->lookUpBeforeRefunding(
                $transactionReference,
                fn () => $this->fetchPaymentLink($transactionReference)
            );

            $settled = array_values(array_filter(
                is_array($link['payments'] ?? null) ? $link['payments'] : [],
                fn ($payment) => is_array($payment) && in_array($payment['status'] ?? null, ['captured', 'refunded'], true)
            ));

            if (count($settled) !== 1 || empty($settled[0]['payment_id'])) {
                throw new RefundException(
                    "Cannot refund [$transactionReference]: expected exactly one captured Razorpay payment, found ".count($settled).'.'
                );
            }

            $paymentId = (string) $settled[0]['payment_id'];
        }

        $payment = $this->lookUpBeforeRefunding(
            $transactionReference,
            fn () => $this->parseResponse($this->makeRequest('GET', '/v1/payments/'.rawurlencode($paymentId)))
        );

        $amount = (int) ($payment['amount'] ?? 0);
        $refundable = $amount - (int) ($payment['amount_refunded'] ?? 0);

        if (($payment['status'] ?? null) !== 'captured' || $refundable <= 0) {
            throw new RefundException(
                "Cannot refund Razorpay payment [$paymentId]: it is ".($payment['status'] ?? 'unknown')
                ." with $refundable of $amount minor units left to refund."
            );
        }

        return [
            'id' => $paymentId,
            'currency' => strtoupper($this->requireString($payment, 'currency', 'refund')),
            'refundable' => $refundable,
        ];
    }

    /**
     * Run a lookup that has to succeed before any refund is sent.
     *
     * @template T
     *
     * @param  callable(): T  $lookup
     * @return T
     *
     * @throws RefundException
     */
    private function lookUpBeforeRefunding(string $transactionReference, callable $lookup): mixed
    {
        try {
            return $lookup();
        } catch (Throwable $e) {
            throw new RefundException(
                "Could not look up the Razorpay payment for [$transactionReference] before refunding: ".$this->describeFailure($e)
            );
        }
    }
}
