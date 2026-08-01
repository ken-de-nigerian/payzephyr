<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Repositories;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use KenDeNigerian\PayZephyr\Contracts\SubscriptionRepositoryInterface;
use KenDeNigerian\PayZephyr\Models\SubscriptionTransaction;
use KenDeNigerian\PayZephyr\Traits\DetectsUniqueConstraintViolations;

final class EloquentSubscriptionRepository implements SubscriptionRepositoryInterface
{
    use DetectsUniqueConstraintViolations;

    public function updateOrCreateAtomic(string $subscriptionCode, array $attributes): SubscriptionTransaction
    {
        return DB::transaction(function () use ($subscriptionCode, $attributes) {
            /** @var SubscriptionTransaction|null $existing */
            $existing = SubscriptionTransaction::where('subscription_code', $subscriptionCode)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->update($attributes);

                return $existing;
            }

            try {
                return SubscriptionTransaction::create(
                    array_merge(['subscription_code' => $subscriptionCode], $attributes)
                );
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintViolation($e)) {
                    throw $e;
                }

                // Lost the create race to a concurrent request: the row now
                // exists. Lock and update it rather than dropping this
                // event's data - see ADR-0004.
                /** @var SubscriptionTransaction $existing */
                $existing = SubscriptionTransaction::where('subscription_code', $subscriptionCode)
                    ->lockForUpdate()
                    ->firstOrFail();
                $existing->update($attributes);

                return $existing;
            }
        });
    }
}
