<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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
 * @method static create(array $array)
 * @method static where(string $string, string $reference)
 */
class PaymentTransaction extends Model
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
    public function scopeSuccessful($query)
    {
        return $query->whereIn('status', ['success', 'succeeded', 'completed', 'successful']);
    }

    /**
     * Get only payments that failed.
     * Usage: PaymentTransaction::failed()->get()
     */
    public function scopeFailed($query)
    {
        return $query->whereIn('status', ['failed', 'cancelled', 'declined']);
    }

    /**
     * Get only payments that are still pending (waiting for customer).
     * Usage: PaymentTransaction::pending()->get()
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Check if payment is successful.
     */
    public function isSuccessful(): bool
    {
        return in_array(strtolower($this->status), ['success', 'succeeded', 'completed', 'successful']);
    }

    /**
     * Check if payment has failed.
     */
    public function isFailed(): bool
    {
        return in_array(strtolower($this->status), ['failed', 'cancelled', 'declined']);
    }

    /**
     * Check if payment is pending.
     */
    public function isPending(): bool
    {
        return strtolower($this->status) === 'pending';
    }
}
