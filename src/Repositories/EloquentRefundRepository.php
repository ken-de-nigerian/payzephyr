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
        return DB::connection()->transaction(function () use ($refundReference, $attributes): RefundTransaction {
            /** @var RefundTransaction|null $existing */
            $existing = RefundTransaction::where('refund_reference', $refundReference)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->update($this->withoutStaleStatus($existing, $attributes));

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
                $existing->update($this->withoutStaleStatus($existing, $attributes));

                return $existing;
            }
        });
    }

    /**
     * Drop an incoming status that would move a refund out of a terminal one.
     *
     * A refund's outcome does not change once it is known, but the response
     * carrying a status can arrive after a newer one has been written: a
     * fetchRefund() that was sent while the refund was still pending, and
     * answered after its refund.processed webhook had already been applied,
     * would put it back to pending. updateStatusIfExists() has always refused
     * to change a terminal refund; this makes the other write path agree. The
     * remaining attributes are still applied.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withoutStaleStatus(RefundTransaction $existing, array $attributes): array
    {
        $current = RefundStatus::tryFromString((string) $existing->status);

        if ($current?->isTerminal() && isset($attributes['status']) && $attributes['status'] !== $current->value) {
            unset($attributes['status']);
        }

        return $attributes;
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
        $sum = RefundTransaction::where('transaction_reference', $transactionReference)
            ->whereIn('status', $this->countedStatuses())
            ->sum('amount');

        // A decimal column sums to a numeric string on most drivers.
        return is_numeric($sum) ? (float) $sum : 0.0;
    }

    public function hasInFlightRefund(string $transactionReference): bool
    {
        return RefundTransaction::where('transaction_reference', $transactionReference)
            ->whereIn('status', [RefundStatus::PENDING->value, RefundStatus::PROCESSING->value])
            ->exists();
    }

    public function updateStatusIfExists(string $refundReference, string $status): bool
    {
        return DB::connection()->transaction(function () use ($refundReference, $status): bool {
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
