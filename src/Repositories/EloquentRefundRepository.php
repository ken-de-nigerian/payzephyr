<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Repositories;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use KenDeNigerian\PayZephyr\Contracts\RefundRepositoryInterface;
use KenDeNigerian\PayZephyr\Enums\RefundStatus;
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

                /** @var RefundTransaction $existing */
                $existing = RefundTransaction::where('refund_reference', $refundReference)
                    ->lockForUpdate()
                    ->firstOrFail();
                $existing->update($attributes);

                return $existing;
            }
        });
    }

    /**
     * Statuses representing money that has moved or is still expected to.
     * Derived from the enum rather than hardcoded, so a new status added to
     * RefundStatus cannot silently drop out of the over-refund guard.
     *
     * @return array<int, string>
     */
    private function countedStatuses(): array
    {
        return array_values(array_map(
            fn (RefundStatus $status) => $status->value,
            array_filter(
                RefundStatus::cases(),
                fn (RefundStatus $status) => $status->countsTowardRefundedAmount()
            )
        ));
    }

    public function sumRefundedAmount(string $transactionReference): float
    {
        return (float) RefundTransaction::where('transaction_reference', $transactionReference)
            ->whereIn('status', $this->countedStatuses())
            ->sum('amount');
    }

    public function hasInFlightRefund(string $transactionReference): bool
    {
        return RefundTransaction::where('transaction_reference', $transactionReference)
            ->whereIn('status', [RefundStatus::PENDING->value, RefundStatus::PROCESSING->value])
            ->exists();
    }

    public function updateStatusIfExists(string $refundReference, string $status): bool
    {
        return DB::transaction(function () use ($refundReference, $status) {
            /** @var RefundTransaction|null $refund */
            $refund = RefundTransaction::where('refund_reference', $refundReference)
                ->lockForUpdate()
                ->first();

            if (! $refund || (RefundStatus::tryFromString($refund->status)?->isTerminal() ?? false)) {
                return false;
            }

            $refund->update(['status' => $status]);

            return true;
        });
    }
}
