<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Models;

use ArrayObject;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use KenDeNigerian\PayZephyr\Traits\HasConfigurableTableName;
use KenDeNigerian\PayZephyr\Traits\LogsToPaymentChannel;

/**
 * @method static Builder<SubscriptionTransaction> where(string $column, mixed $operator = null, mixed $value = null)
 * @method static SubscriptionTransaction create(array<string, mixed> $attributes = [])
 * @method static SubscriptionTransaction|null first(array<int, string>|string $columns = ['*'])
 * @method static SubscriptionTransaction firstOrFail(array<int, string>|string $columns = ['*'])
 * @method static Builder<SubscriptionTransaction> lockForUpdate()
 * @method static Builder<SubscriptionTransaction> update(array<string, mixed> $attributes = [])
 * @method static Builder<SubscriptionTransaction> delete()
 * @method static SubscriptionTransaction updateOrCreate(array<string, mixed> $attributes, array<string, mixed> $values = [])
 *
 * @property int $id
 * @property string $subscription_code
 * @property string $provider
 * @property string $status
 * @property string $plan_code
 * @property string $customer_email
 * @property float $amount
 * @property string $currency
 * @property Carbon|null $next_payment_date
 * @property array<string, mixed>|ArrayObject<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property CarbonImmutable|null $state_as_of
 */
final class SubscriptionTransaction extends Model
{
    use HasConfigurableTableName;
    use LogsToPaymentChannel;

    /** @var array<int, string> */
    protected $fillable = [
        'subscription_code',
        'provider',
        'status',
        'plan_code',
        'customer_email',
        'amount',
        'currency',
        'next_payment_date',
        'metadata',
        'state_as_of',
    ];

    protected $table = 'subscription_transactions';

    protected function configuredTableNameKey(): string
    {
        return 'subscriptions.logging.table';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => AsArrayObject::class,
            'next_payment_date' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * When the request that produced this row's state was sent, to the
     * microsecond: two requests from one process routinely land in the same
     * second, and the ordering is between them. Stored explicitly rather than
     * through the model's date format, which drops the fraction.
     */
    protected function stateAsOf(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : CarbonImmutable::parse($value),
            set: fn (CarbonInterface|string|null $value) => $value === null
                ? null
                : CarbonImmutable::parse($value)->format('Y-m-d H:i:s.u'),
        );
    }

    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? (app()->environment('testing') ? 'testing' : null);
    }

    /**
     * Scope to filter active subscriptions.
     *
     * @param  Builder<SubscriptionTransaction>  $query
     * @return Builder<SubscriptionTransaction>
     */
    public function scopeActive(Builder $query): Builder
    {
        /** @var Builder<self> */
        return $query->whereIn('status', ['active', 'non-renewing']);
    }

    /**
     * Scope to filter canceled subscriptions.
     *
     * @param  Builder<SubscriptionTransaction>  $query
     * @return Builder<SubscriptionTransaction>
     */
    public function scopeCancelled(Builder $query): Builder
    {
        /** @var Builder<self> */
        return $query->where('status', 'cancelled');
    }

    /**
     * Scope to filter subscriptions by customer email.
     *
     * @param  Builder<SubscriptionTransaction>  $query
     * @return Builder<SubscriptionTransaction>
     */
    public function scopeForCustomer(Builder $query, string $email): Builder
    {
        /** @var Builder<self> */
        return $query->where('customer_email', $email);
    }

    /**
     * Scope to filter subscriptions by plan code.
     *
     * @param  Builder<SubscriptionTransaction>  $query
     * @return Builder<SubscriptionTransaction>
     */
    public function scopeForPlan(Builder $query, string $planCode): Builder
    {
        /** @var Builder<self> */
        return $query->where('plan_code', $planCode);
    }
}
