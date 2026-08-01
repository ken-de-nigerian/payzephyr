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
 * @method static Builder<SubscriptionTransaction> where(string $column, mixed $operator = null, mixed $value = null)
 * @method static SubscriptionTransaction create(array $attributes = [])
 * @method static SubscriptionTransaction|null first(array|string $columns = ['*'])
 * @method static SubscriptionTransaction firstOrFail(array|string $columns = ['*'])
 * @method static Builder<SubscriptionTransaction> lockForUpdate()
 * @method static Builder<SubscriptionTransaction> update(array $attributes = [])
 * @method static Builder<SubscriptionTransaction> delete()
 * @method static SubscriptionTransaction updateOrCreate(array $attributes, array $values = [])
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
 * @property array|ArrayObject|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
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
    ];

    protected $table = 'subscription_transactions';

    protected function configuredTableNameKey(): string
    {
        return 'subscriptions.logging.table';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        $laravelVersion = (float) app()->version();
        $arrayCast = $laravelVersion >= 11.0 ? AsArrayObject::class : 'array';

        return [
            'amount' => 'decimal:2',
            'metadata' => $arrayCast,
            'next_payment_date' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function setAttribute($key, $value): self
    {
        $laravelVersion = (float) app()->version();

        if ($laravelVersion < 11.0 && $key === 'metadata') {
            if ($value === null) {
                $this->attributes[$key] = null;
            } elseif (is_array($value)) {
                $this->attributes[$key] = json_encode($value);
            } else {
                $this->attributes[$key] = $value;
            }

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    public function getAttribute($key): mixed
    {
        $value = parent::getAttribute($key);

        $laravelVersion = (float) app()->version();
        if ($laravelVersion < 11.0 && $key === 'metadata') {
            if (is_string($value) && ! empty($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return $value;
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
