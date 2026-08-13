<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Models;

use ArrayObject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use KenDeNigerian\PayZephyr\Traits\HasConfigurableTableName;
use KenDeNigerian\PayZephyr\Traits\LogsToPaymentChannel;

/**
 * @method static Builder<RefundTransaction> where(string $column, mixed $operator = null, mixed $value = null)
 * @method static RefundTransaction create(array<string, mixed> $attributes = [])
 * @method static RefundTransaction|null first(array<int, string>|string $columns = ['*'])
 * @method static RefundTransaction firstOrFail(array<int, string>|string $columns = ['*'])
 * @method static Builder<RefundTransaction> lockForUpdate()
 * @method static Builder<RefundTransaction> update(array<string, mixed> $attributes = [])
 * @method static Builder<RefundTransaction> delete()
 * @method static RefundTransaction updateOrCreate(array<string, mixed> $attributes, array<string, mixed> $values = [])
 *
 * @property int $id
 * @property string $refund_reference
 * @property string $transaction_reference
 * @property string $provider
 * @property string $status
 * @property float $amount
 * @property string $currency
 * @property string|null $reason
 * @property array<string, mixed>|ArrayObject<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class RefundTransaction extends Model
{
    use HasConfigurableTableName;
    use LogsToPaymentChannel;

    /** @var array<int, string> */
    protected $fillable = [
        'refund_reference',
        'transaction_reference',
        'provider',
        'status',
        'amount',
        'currency',
        'reason',
        'metadata',
    ];

    protected $table = 'refund_transactions';

    protected function configuredTableNameKey(): string
    {
        return 'refunds.logging.table';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => AsArrayObject::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? (app()->environment('testing') ? 'testing' : null);
    }

    /**
     * Scope to filter pending/processing refunds.
     *
     * @param  Builder<RefundTransaction>  $query
     * @return Builder<RefundTransaction>
     */
    public function scopePending(Builder $query): Builder
    {
        /** @var Builder<self> */
        return $query->whereIn('status', ['pending', 'processing']);
    }

    /**
     * Scope to filter completed refunds.
     *
     * @param  Builder<RefundTransaction>  $query
     * @return Builder<RefundTransaction>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        /** @var Builder<self> */
        return $query->where('status', 'completed');
    }

    /**
     * Scope to filter refunds for a given original transaction.
     *
     * @param  Builder<RefundTransaction>  $query
     * @return Builder<RefundTransaction>
     */
    public function scopeForTransaction(Builder $query, string $transactionReference): Builder
    {
        /** @var Builder<self> */
        return $query->where('transaction_reference', $transactionReference);
    }
}
