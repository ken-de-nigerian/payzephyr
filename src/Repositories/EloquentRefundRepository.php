<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Repositories;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use KenDeNigerian\PayZephyr\Contracts\RefundRepositoryInterface;
use KenDeNigerian\PayZephyr\Models\RefundTransaction;
use KenDeNigerian\PayZephyr\Traits\DetectsUniqueConstraintViolations;

final class EloquentRefundRepository implements RefundRepositoryInterface
{
    use DetectsUniqueConstraintViolations;

    public function updateOrCreateAtomic(string $refundReference, array $attributes): RefundTransaction
    {
        return DB::transaction(function () use ($refundReference, $attributes) {
            /** @var RefundTransaction|null $existing */
            $existing = RefundTransaction::where('refund_reference', $refundReference)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->update($attributes);

                return $existing;
            }

            try {
                return RefundTransaction::create(
                    array_merge(['refund_reference' => $refundReference], $attributes)
                );
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintViolation($e)) {
                    throw $e;
                }

                // Lost the create race to a concurrent request: the row now
                // exists. Lock and update it rather than dropping this
                // event's data - see ADR-0004.
                /** @var RefundTransaction $existing */
                $existing = RefundTransaction::where('refund_reference', $refundReference)
                    ->lockForUpdate()
                    ->firstOrFail();
                $existing->update($attributes);

                return $existing;
            }
        });
    }

    public function sumRefundedAmount(string $transactionReference): float
    {
        return (float) RefundTransaction::where('transaction_reference', $transactionReference)
            ->whereIn('status', ['pending', 'processing', 'completed'])
            ->sum('amount');
    }

    public function hasInFlightRefund(string $transactionReference): bool
    {
        return RefundTransaction::where('transaction_reference', $transactionReference)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();
    }
}
