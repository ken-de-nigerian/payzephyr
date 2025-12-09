<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Constants;

use ValueError;

/**
 * Payment Status Enum
 *
 * Standard payment status values are used throughout the package.
 * This is a string-backed enum for database and API compatibility.
 *
 * @method static self SUCCESS()
 * @method static self FAILED()
 * @method static self PENDING()
 * @method static self CANCELLED()
 */
enum PaymentStatus: string
{
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case PENDING = 'pending';
    case CANCELLED = 'cancelled';

    /**
     * Check if this status represents a successful payment.
     */
    public function isSuccessful(): bool
    {
        return $this === self::SUCCESS;
    }

    /**
     * Check if this status represents a failed payment.
     */
    public function isFailed(): bool
    {
        return $this === self::FAILED || $this === self::CANCELLED;
    }

    /**
     * Check if this status represents a pending payment.
     */
    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Get all valid status values as strings.
     *
     * @return array<string> Array of valid status values
     */
    public static function all(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Try to create an enum from a string value.
     * Returns null if the value is not a valid status.
     *
     * @param  string  $value  The status string value
     * @return self|null The enum case or null if invalid
     */
    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }

    /**
     * Create an enum from a string value.
     * Throws ValueError if the value is not valid.
     *
     * @param  string  $value  The status string value
     * @return self The enum case
     *
     * @throws ValueError If value is not a valid status
     */
    public static function fromString(string $value): self
    {
        return self::from(strtolower(trim($value)));
    }

    /**
     * Check if a string value is a valid payment status.
     *
     * @param  string  $value  The status string to check
     * @return bool True if valid
     */
    public static function isValid(string $value): bool
    {
        return self::tryFromString($value) !== null;
    }

    /**
     * Check if a status string represents a successful payment.
     * Backward compatibility method for string-based checks.
     *
     * @param  string  $status  The status to check
     * @return bool True if the status indicates success
     */
    public static function isSuccessfulString(string $status): bool
    {
        $enum = self::tryFromString($status);

        return $enum?->isSuccessful() ?? false;
    }

    /**
     * Check if a status string represents a failed payment.
     * Backward compatibility method for string-based checks.
     *
     * @param  string  $status  The status to check
     * @return bool True if the status indicates failure
     */
    public static function isFailedString(string $status): bool
    {
        $enum = self::tryFromString($status);

        return $enum?->isFailed() ?? false;
    }

    /**
     * Check if a status string represents a pending payment.
     * Backward compatibility method for string-based checks.
     *
     * @param  string  $status  The status to check
     * @return bool True if the status indicates pending
     */
    public static function isPendingString(string $status): bool
    {
        $enum = self::tryFromString($status);

        return $enum?->isPending() ?? false;
    }
}
