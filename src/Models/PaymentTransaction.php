<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use KenDeNigerian\PayZephyr\Constants\PaymentStatus;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;
use Throwable;

/**
 * PaymentTransaction - Database Model for Payment Records
 *
 * This model represents a payment transaction in your database.
 * Every payment attempt is automatically logged here (if logging is enabled).
 *
 * Properties:
 * - reference: Unique transaction ID
 * - provider: Which payment provider was used (paystack, stripe, etc.)
 * - status: Payment status (success, failed, pending)
 * - amount: Payment amount
 * - currency: Currency code (NGN, USD, etc.)
 * - email: Customer email
 * - channel: Payment method used (card, bank_transfer, etc.)
 * - metadata: Extra data you attached to the payment
 * - customer: Customer information
 * - paid_at: When the payment was completed
 *
 * @method static create(array $array)
 * @method static where(string $string, string $reference)
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
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'customer' => 'array',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('payments.logging.table') ?? 'payment_transactions';
    }

    /**
     * Get the database connection for the model.
     */
    public function getConnectionName(): ?string
    {
        // Use the default connection if set, otherwise use testing in test environment
        return parent::getConnectionName() ?? (app()->environment('testing') ? 'testing' : null);
    }

    /**
     * Get only payments that were successful.
     * Usage: PaymentTransaction::successful()->get()
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        // Include both normalized and common variations
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
     * Get only payments that failed.
     * Usage: PaymentTransaction::failed()->get()
     */
    public function scopeFailed(Builder $query): Builder
    {
        // Include both normalized and common variations
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
     * Get only payments that are still pending (waiting for customer).
     * Usage: PaymentTransaction::pending()->get()
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::PENDING->value);
    }

    /**
     * Check if payment is successful.
     */
    public function isSuccessful(): bool
    {
        // Try to use container if available, otherwise use static method
        try {
            if (function_exists('app')) {
                $normalizer = app(StatusNormalizer::class);
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

    /**
     * Check if payment has failed.
     */
    public function isFailed(): bool
    {
        // Try to use container if available, otherwise use static method
        try {
            if (function_exists('app')) {
                $normalizer = app(StatusNormalizer::class);
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

    /**
     * Check if payment is pending.
     */
    public function isPending(): bool
    {
        // Try to use container if available, otherwise use static method
        try {
            if (function_exists('app')) {
                $normalizer = app(StatusNormalizer::class);
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
