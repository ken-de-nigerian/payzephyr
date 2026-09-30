<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Repositories;

use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KenDeNigerian\PayZephyr\Contracts\SubscriptionRepositoryInterface;
use KenDeNigerian\PayZephyr\Models\SubscriptionTransaction;
use KenDeNigerian\PayZephyr\Traits\DetectsUniqueConstraintViolations;

final class EloquentSubscriptionRepository implements SubscriptionRepositoryInterface
{
    use DetectsUniqueConstraintViolations;

    /**
     * Whether each subscription table has the state_as_of column, keyed by
     * table name - an install that has not run the migration adding it must
     * keep logging subscriptions, just without ordering.
     *
     * @var array<string, bool>
     */
    private array $orderingSupported = [];

    /**
     * {@inheritDoc}
     *
     * A write carrying a `state_as_of` older than the stored one is skipped:
     * it is a response to a request sent before the one that produced the
     * stored state, and finished after it - a fetch answered after a cancel -
     * and applying it would put stale state back (ADR-0004's follow-up).
     * Writes without a `state_as_of`, or onto a row without one, are applied
     * as before.
     */
    public function updateOrCreateAtomic(string $subscriptionCode, array $attributes): SubscriptionTransaction
    {
        $attributes = $this->withSupportedColumns($attributes);

        return DB::connection()->transaction(function () use ($subscriptionCode, $attributes): SubscriptionTransaction {
            /** @var SubscriptionTransaction|null $existing */
            $existing = SubscriptionTransaction::where('subscription_code', $subscriptionCode)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $this->applyUnlessStale($existing, $attributes);
            }

            try {
                return SubscriptionTransaction::create(
                    array_merge(['subscription_code' => $subscriptionCode], $attributes)
                );
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintViolation($e)) {
                    throw $e;
                }

                /** @var SubscriptionTransaction $existing */
                $existing = SubscriptionTransaction::where('subscription_code', $subscriptionCode)
                    ->lockForUpdate()
                    ->firstOrFail();

                return $this->applyUnlessStale($existing, $attributes);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyUnlessStale(SubscriptionTransaction $existing, array $attributes): SubscriptionTransaction
    {
        $incoming = $attributes['state_as_of'] ?? null;
        $stored = $existing->state_as_of;

        if ($incoming instanceof CarbonInterface && $stored instanceof CarbonInterface && $incoming->lt($stored)) {
            return $existing;
        }

        $existing->update($attributes);

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withSupportedColumns(array $attributes): array
    {
        if (! array_key_exists('state_as_of', $attributes)) {
            return $attributes;
        }

        $model = new SubscriptionTransaction;
        $table = $model->getTable();

        $this->orderingSupported[$table] ??= Schema::connection($model->getConnectionName())->hasColumn($table, 'state_as_of');

        if (! $this->orderingSupported[$table]) {
            unset($attributes['state_as_of']);
        }

        return $attributes;
    }
}
