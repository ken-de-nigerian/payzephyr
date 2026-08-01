<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Repositories;

use Illuminate\Support\Facades\DB;
use KenDeNigerian\PayZephyr\Contracts\TransactionRepositoryInterface;
use KenDeNigerian\PayZephyr\Models\PaymentTransaction;

final class EloquentTransactionRepository implements TransactionRepositoryInterface
{
    public function create(array $attributes): PaymentTransaction
    {
        return PaymentTransaction::create($attributes);
    }

    public function findByReference(string $reference): ?PaymentTransaction
    {
        return PaymentTransaction::where('reference', $reference)->first();
    }

    public function updateIfNotSuccessful(string $reference, array $attributes): bool
    {
        return DB::transaction(function () use ($reference, $attributes) {
            /** @var PaymentTransaction|null $transaction */
            $transaction = PaymentTransaction::where('reference', $reference)
                ->lockForUpdate()
                ->first();

            if (! $transaction || $transaction->isSuccessful()) {
                return false;
            }

            $transaction->update($attributes);

            return true;
        });
    }
}
