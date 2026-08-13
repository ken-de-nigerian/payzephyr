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
 * @method static PaymentTransaction create(array<string, mixed> $attributes = [])
 * @method static PaymentTransaction|null first(array<int, string>|string $columns = ['*'])
 * @method static Builder<PaymentTransaction> lockForUpdate()
 * @method static Builder<PaymentTransaction> update(array<string, mixed> $attributes = [])
 * @method static Builder<PaymentTransaction> delete()
 *
 * @property string $reference
 * @property string $provider
 * @property string $status
 * @property int $amount
 * @property string $currency
 * @property string $email
 * @property string|null $channel
 * @property array<string, mixed>|ArrayObject<string, mixed>|null $metadata
 * @property array<string, mixed>|null $customer
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
        return [
            'amount' => 'decimal:2',
            'metadata' => AsArrayObject::class,
            'customer' => AsArrayObject::class,
            'paid_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
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
