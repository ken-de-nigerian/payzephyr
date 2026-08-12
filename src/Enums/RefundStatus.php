<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Enums;

use InvalidArgumentException;

/**
 * Refund status enum.
 *
 * Represents the various states a refund can be in and normalizes the
 * different status vocabularies used across providers.
 */
enum RefundStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    /**
     * Get human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::PROCESSING => 'Processing',
            self::COMPLETED => 'Completed',
            self::FAILED => 'Failed',
            self::CANCELLED => 'Cancelled',
        };
    }

    /**
     * Check if the refund reached a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::CANCELLED], true);
    }

    /**
     * Whether this status counts toward a transaction's refunded amount
     * (i.e. money that has moved or is still expected to move).
     */
    public function countsTowardRefundedAmount(): bool
    {
        return in_array($this, [self::PENDING, self::PROCESSING, self::COMPLETED], true);
    }

    /**
     * Create RefundStatus from provider status string.
     *
     * Normalizes various provider-specific status strings to our enum values.
     */
    public static function fromString(string $status): self
    {
        $normalized = strtolower(trim($status));

        return match (true) {
            in_array($normalized, ['pending', 'requested', 'created'], true) => self::PENDING,
            in_array($normalized, ['processing', 'in_progress', 'submitted'], true) => self::PROCESSING,
            in_array($normalized, ['completed', 'succeeded', 'success', 'processed', 'refunded'], true) => self::COMPLETED,
            in_array($normalized, ['failed', 'declined', 'error'], true) => self::FAILED,
            in_array($normalized, ['cancelled', 'canceled', 'reversed', 'voided'], true) => self::CANCELLED,
            default => throw new InvalidArgumentException("Unknown refund status: $status"),
        };
    }

    /**
     * Try to create RefundStatus from provider status string.
     *
     * Returns null if the status cannot be mapped.
     */
    public static function tryFromString(string $status): ?self
    {
        try {
            return self::fromString($status);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
