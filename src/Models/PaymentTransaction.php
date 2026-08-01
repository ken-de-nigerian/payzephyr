<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Models;

use ArrayObject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface;
use KenDeNigerian\PayZephyr\Enums\PaymentStatus;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;
use KenDeNigerian\PayZephyr\Traits\HasConfigurableTableName;
use KenDeNigerian\PayZephyr\Traits\LogsToPaymentChannel;
use Throwable;

/**
 * @method static Builder<PaymentTransaction> where(string $column, mixed $operator = null, mixed $value = null)
 * @method static PaymentTransaction create(array $attributes = [])
 * @method static PaymentTransaction|null first(array|string $columns = ['*'])
 * @method static Builder<PaymentTransaction> lockForUpdate()
 * @method static Builder<PaymentTransaction> update(array $attributes = [])
 * @method static Builder<PaymentTransaction> delete()
 *
 * @property string $reference
 * @property string $provider
 * @property string $status
 * @property int $amount
 * @property string $currency
 * @property string $email
 * @property string|null $channel
 * @property array|ArrayObject|null $metadata
 * @property array|null $customer
 * @property Carbon|null $paid_at
 */
final class PaymentTransaction extends Model
{
    use HasConfigurableTableName;
    use LogsToPaymentChannel;

    /** @var array<int, string> */
    protected $fillable = [
        'reference',
        'provider',
        'status',
        'amount',
        'currency',
        'email',
        'channel',
        'metadata',
        'customer',
        'paid_at',
    ];

    protected $table = 'payment_transactions';

    protected function configuredTableNameKey(): string
    {
        return 'logging.table';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        $laravelVersion = (float) app()->version();
        $arrayCast = $laravelVersion >= 11.0 ? AsArrayObject::class : 'array';

        return [
            'amount' => 'decimal:2',
            'metadata' => $arrayCast,
            'customer' => $arrayCast,
            'paid_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function setAttribute($key, $value): self
    {
        $laravelVersion = (float) app()->version();

        if ($laravelVersion < 11.0 && in_array($key, ['metadata', 'customer'], true)) {
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
        if ($laravelVersion < 11.0 && in_array($key, ['metadata', 'customer'], true)) {
            if (is_string($value) && ! empty($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return $value;
    }

    public static function setTableName(string $table): void
    {
        $instance = new self;
        $instance->table = $table;
    }

    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? (app()->environment('testing') ? 'testing' : null);
    }

    /**
     * @param  Builder<PaymentTransaction>  $query
     * @return Builder<PaymentTransaction>
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        $successStatuses = [
            PaymentStatus::SUCCESS->value,
            'succeeded',
            'completed',
            'successful',
            'paid',
        ];

        /** @var Builder<self> */
        return $query->whereIn('status', $successStatuses);
    }

    /**
     * @param  Builder<PaymentTransaction>  $query
     * @return Builder<PaymentTransaction>
     */
    public function scopeFailed(Builder $query): Builder
    {
        $failedStatuses = [
            PaymentStatus::FAILED->value,
            PaymentStatus::CANCELLED->value,
            'declined',
            'rejected',
            'denied',
            'voided',
            'expired',
        ];

        /** @var Builder<self> */
        return $query->whereIn('status', $failedStatuses);
    }

    /**
     * @param  Builder<PaymentTransaction>  $query
     * @return Builder<PaymentTransaction>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::PENDING->value);
    }

    public function isSuccessful(): bool
    {
        try {
            if (function_exists('app')) {
                $normalizer = app(StatusNormalizerInterface::class);
                $normalizedStatus = $normalizer->normalize($this->status);
            } else {
                $normalizedStatus = StatusNormalizer::normalizeStatic($this->status);
            }
        } catch (Throwable) {
            $normalizedStatus = StatusNormalizer::normalizeStatic($this->status);
        }

        $status = PaymentStatus::tryFromString($normalizedStatus);

        return $status?->isSuccessful() ?? false;
    }

    public function isFailed(): bool
    {
        try {
            if (function_exists('app')) {
                $normalizer = app(StatusNormalizerInterface::class);
                $normalizedStatus = $normalizer->normalize($this->status);
            } else {
                $normalizedStatus = StatusNormalizer::normalizeStatic($this->status);
            }
        } catch (Throwable) {
            $normalizedStatus = StatusNormalizer::normalizeStatic($this->status);
        }

        $status = PaymentStatus::tryFromString($normalizedStatus);

        return $status?->isFailed() ?? false;
    }

    public function isPending(): bool
    {
        try {
            if (function_exists('app')) {
                $normalizer = app(StatusNormalizerInterface::class);
                $normalizedStatus = $normalizer->normalize($this->status);
            } else {
                $normalizedStatus = StatusNormalizer::normalizeStatic($this->status);
            }
        } catch (Throwable) {
            $normalizedStatus = StatusNormalizer::normalizeStatic($this->status);
        }

        $status = PaymentStatus::tryFromString($normalizedStatus);

        return $status?->isPending() ?? false;
    }
}
