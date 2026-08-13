<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Enums;

use InvalidArgumentException;

/**
 * Subscription status enum with state machine logic.
 *
 * Represents the various states a subscription can be in and provides
 * methods to validate state transitions and check status capabilities.
 */
enum SubscriptionStatus: string
{
    case ACTIVE = 'active';
    case NON_RENEWING = 'non-renewing';
    case CANCELLED = 'cancelled';
    case COMPLETED = 'completed';
    case ATTENTION = 'attention';
    case EXPIRED = 'expired';

    /**
     * Get human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::NON_RENEWING => 'Non-Renewing',
            self::CANCELLED => 'Cancelled',
            self::COMPLETED => 'Completed',
            self::ATTENTION => 'Attention Required',
            self::EXPIRED => 'Expired',
        };
    }

    /**
     * Check if subscription can be canceled from this status.
     */
    public function canBeCancelled(): bool
    {
        return in_array($this, [self::ACTIVE, self::NON_RENEWING, self::ATTENTION], true);
    }

    /**
     * Check if subscription can be resumed from this status.
     */
    public function canBeResumed(): bool
    {
        return $this === self::CANCELLED || $this === self::NON_RENEWING;
    }

    /**
     * Check if subscription is actively billing.
     */
    public function isBilling(): bool
    {
        return in_array($this, [self::ACTIVE, self::NON_RENEWING], true);
    }

    /**
     * Get array of valid next states from current status.
     *
     * @return array<SubscriptionStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::ACTIVE => [self::NON_RENEWING, self::CANCELLED, self::ATTENTION],
            self::NON_RENEWING => [self::ACTIVE, self::CANCELLED, self::COMPLETED],
            self::ATTENTION => [self::ACTIVE, self::CANCELLED, self::EXPIRED],
            self::CANCELLED => [self::ACTIVE],
            self::COMPLETED => [],
            self::EXPIRED => [],
        };
    }

    /**
     * Check if subscription can transition to the given status.
     */
    public function canTransitionTo(SubscriptionStatus $newStatus): bool
    {
        return in_array($newStatus, $this->allowedTransitions(), true);
    }

    /**
     * Create SubscriptionStatus from provider status string.
     *
     * Normalizes various provider-specific status strings to our enum values.
     */
    public static function fromString(string $status): self
    {
        $normalized = strtolower(trim($status));

        return match (true) {
            in_array($normalized, ['active', 'subscribed', 'enabled'], true) => self::ACTIVE,
            in_array($normalized, ['non-renewing', 'non_renewing', 'nonrenewing', 'will_not_renew'], true) => self::NON_RENEWING,
            in_array($normalized, ['cancelled', 'canceled', 'disabled', 'deleted'], true) => self::CANCELLED,
            in_array($normalized, ['completed', 'finished', 'ended'], true) => self::COMPLETED,
            in_array($normalized, ['attention', 'requires_attention', 'payment_required'], true) => self::ATTENTION,
            in_array($normalized, ['expired', 'past_due', 'unpaid'], true) => self::EXPIRED,
            default => throw new InvalidArgumentException("Unknown subscription status: $status"),
        };
    }

    /**
     * Try to create SubscriptionStatus from provider status string.
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
