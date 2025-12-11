<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface;
use KenDeNigerian\PayZephyr\Enums\PaymentStatus;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;
use Throwable;

/**
 * Payment transaction model.
 *
 * @method static where(string $string, string $reference)
 * @method static create(array $array)
 */
final class PaymentTransaction extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
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

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'payment_transactions';

    /**
     * Get the table associated with the model.
     *
     * @return string
     */
    public function getTable()
    {
        $config = app('payments.config') ?? config('payments', []);
        $tableName = $config['logging']['table'] ?? $this->table;

        return $tableName;
    }

    /**
     * Get attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // Use 'array' cast for Laravel 10 and below compatibility
        // AsArrayObject may not properly encode during mass assignment in Laravel 10
        // Laravel 11+ handles AsArrayObject correctly during mass assignment
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

    /**
     * Set a given attribute on the model.
     * Override to handle array encoding for Laravel 10 compatibility.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    public function setAttribute($key, $value)
    {
        $laravelVersion = (float) app()->version();
        
        // For Laravel 10, ensure arrays are JSON-encoded for metadata and customer
        // Laravel 11+ uses AsArrayObject which handles this automatically
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
        
        // For Laravel 11+ or other attributes, use parent implementation
        return parent::setAttribute($key, $value);
    }

    /**
     * Set table name.
     */
    public static function setTableName(string $table): void
    {
        $instance = new self;
        $instance->table = $table;
    }

    /**
     * Get database connection name.
     */
    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? (app()->environment('testing') ? 'testing' : null);
    }

    /**
     * Scope: successful payments.
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

        return $query->whereIn('status', $successStatuses);
    }

    /**
     * Scope: failed payments.
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

        return $query->whereIn('status', $failedStatuses);
    }

    /**
     * Scope: pending payments.
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
