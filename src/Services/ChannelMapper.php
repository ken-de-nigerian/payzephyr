<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Services;

/**
 * Channel Mapper Service
 *
 * This service maps unified channel names to provider-specific channel names.
 * It ensures consistency across all providers while respecting their API requirements.
 *
 * Single Responsibility: Only handles channel name mapping.
 */
final class ChannelMapper
{
    /**
     * Unified channel names used in the package.
     */
    public const CHANNEL_CARD = 'card';

    public const CHANNEL_BANK_TRANSFER = 'bank_transfer';

    public const CHANNEL_USSD = 'ussd';

    public const CHANNEL_MOBILE_MONEY = 'mobile_money';

    public const CHANNEL_QR_CODE = 'qr_code';

    /**
     * Map unified channels to provider-specific channel names.
     *
     * @param  array<string>|null  $channels  Unified channel names (e.g., ['card', 'bank_transfer'])
     * @param  string  $provider  Provider name (e.g., 'paystack', 'monnify')
     * @return array<string>|null Provider-specific channel names or null if not supported
     */
    public function mapChannels(?array $channels, string $provider): ?array
    {
        if (empty($channels)) {
            return null;
        }

        return match ($provider) {
            'paystack' => $this->mapToPaystack($channels),
            'monnify' => $this->mapToMonnify($channels),
            'flutterwave' => $this->mapToFlutterwave($channels),
            'stripe' => $this->mapToStripe($channels),
            'paypal' => $this->mapToPayPal(),
            'square' => $this->mapToSquare($channels),
            default => $channels, // Return as-is for unknown providers
        };
    }

    /**
     * Map channels to Paystack format.
     *
     * Paystack accepts: 'card', 'bank', 'ussd', 'qr', 'mobile_money', 'bank_transfer'
     */
    protected function mapToPaystack(array $channels): array
    {
        $mapping = [
            self::CHANNEL_CARD => 'card',
            self::CHANNEL_BANK_TRANSFER => 'bank_transfer',
            self::CHANNEL_USSD => 'ussd',
            self::CHANNEL_MOBILE_MONEY => 'mobile_money',
            self::CHANNEL_QR_CODE => 'qr',
        ];

        return array_filter(
            array_map(fn ($channel) => $mapping[strtolower($channel)] ?? $channel, $channels)
        );
    }

    /**
     * Map channels to Monnify format.
     *
     * Monnify accepts: 'CARD', 'ACCOUNT_TRANSFER', 'USSD', 'PHONE_NUMBER'
     */
    protected function mapToMonnify(array $channels): array
    {
        $mapping = [
            self::CHANNEL_CARD => 'CARD',
            self::CHANNEL_BANK_TRANSFER => 'ACCOUNT_TRANSFER',
            self::CHANNEL_USSD => 'USSD',
            self::CHANNEL_MOBILE_MONEY => 'PHONE_NUMBER',
        ];

        $mapped = array_map(
            fn ($channel) => $mapping[strtolower($channel)] ?? strtoupper($channel),
            $channels
        );

        return array_filter($mapped, fn ($channel) => in_array($channel, ['CARD', 'ACCOUNT_TRANSFER', 'USSD', 'PHONE_NUMBER']));
    }

    /**
     * Map channels to Flutterwave format.
     *
     * Flutterwave accepts: 'card', 'account', 'banktransfer', 'ussd', 'mpesa',
     * 'mobilemoneyghana', 'mobilemoneyfranco', 'mobilemoneyuganda', 'nqr', etc.
     * Note: Flutterwave uses a comma-separated string, not an array
     */
    protected function mapToFlutterwave(array $channels): array
    {
        $mapping = [
            self::CHANNEL_CARD => 'card',
            self::CHANNEL_BANK_TRANSFER => 'banktransfer',
            self::CHANNEL_USSD => 'ussd',
            self::CHANNEL_MOBILE_MONEY => 'mobilemoneyghana', // Default to Ghana, can be overridden
            self::CHANNEL_QR_CODE => 'nqr',
        ];

        $mapped = array_map(
            fn ($channel) => $mapping[strtolower($channel)] ?? strtolower($channel),
            $channels
        );

        // Flutterwave accepts valid payment options (common ones)
        $validOptions = [
            'card', 'account', 'banktransfer', 'ussd', 'mpesa',
            'mobilemoneyghana', 'mobilemoneyfranco', 'mobilemoneyuganda',
            'mobilemoneyrwanda', 'mobilemoneyzambia', 'mobilemoneytanzania',
            'nqr', 'barter', 'credit', 'opay',
        ];

        return array_filter($mapped, fn ($option) => in_array($option, $validOptions));
    }

    /**
     * Map channels to Stripe format.
     *
     * Stripe accepts: 'card', 'us_bank_account', 'link', 'affirm', 'klarna', etc.
     */
    protected function mapToStripe(array $channels): array
    {
        $mapping = [
            self::CHANNEL_CARD => 'card',
            self::CHANNEL_BANK_TRANSFER => 'us_bank_account', // Stripe's bank transfer
        ];

        $mapped = array_map(
            fn ($channel) => $mapping[strtolower($channel)] ?? strtolower($channel),
            $channels
        );

        // Filter to only valid Stripe payment method types
        $validTypes = ['card', 'us_bank_account', 'link', 'affirm', 'klarna', 'cashapp', 'paypal'];

        return array_filter($mapped, fn ($type) => in_array($type, $validTypes));
    }

    /**
     * Map channels to PayPal format.
     *
     * PayPal doesn't use channels in the same way, but we can set payment method preference.
     * Returns null as PayPal handles this differently.
     */
    protected function mapToPayPal(): ?array
    {
        // PayPal doesn't support channel filtering in the same way
        // It uses payment_method_preference in experience_context
        // Return null to use default behavior
        return null;
    }

    /**
     * Map channels to Square format.
     *
     * Square accepts: 'CARD', 'CASH', 'OTHER', 'SQUARE_GIFT_CARD', 'NO_SALE'
     * Square Online Checkout primarily supports card payments.
     */
    protected function mapToSquare(array $channels): array
    {
        $mapping = [
            self::CHANNEL_CARD => 'CARD',
            self::CHANNEL_BANK_TRANSFER => 'OTHER', // Square doesn't have direct bank transfer
        ];

        $mapped = array_map(
            fn ($channel) => $mapping[strtolower($channel)] ?? strtoupper($channel),
            $channels
        );

        // Square payment methods
        $validMethods = ['CARD', 'CASH', 'OTHER', 'SQUARE_GIFT_CARD'];

        return array_filter($mapped, fn ($method) => in_array($method, $validMethods));
    }

    /**
     * Get default channels for a provider if none are specified.
     *
     * @param  string  $provider  Provider name
     * @return array<string> Default channels for the provider (already in provider format)
     */
    public function getDefaultChannels(string $provider): array
    {
        return match ($provider) {
            'paystack' => ['card', 'bank_transfer'],
            'monnify' => ['CARD', 'ACCOUNT_TRANSFER'],
            'flutterwave' => ['card'],
            'stripe' => ['card'],
            'paypal' => [], // PayPal doesn't use channels
            'square' => ['CARD'], // Square Online Checkout primarily supports cards
            default => ['card'], // Default to card for unknown providers
        };
    }

    /**
     * Check if channels should be included (some providers don't support it).
     *
     * @param  string  $provider  Provider name
     * @return bool True if provider supports channel filtering
     */
    public function shouldIncludeChannels(string $provider, ?array $channels): bool
    {
        if (empty($channels)) {
            return false;
        }

        return $this->supportsChannels($provider);
    }

    /**
     * Get unified channel names (for documentation/API consistency).
     *
     * @return array<string> All supported unified channel names
     */
    public static function getUnifiedChannels(): array
    {
        return [
            self::CHANNEL_CARD,
            self::CHANNEL_BANK_TRANSFER,
            self::CHANNEL_USSD,
            self::CHANNEL_MOBILE_MONEY,
            self::CHANNEL_QR_CODE,
        ];
    }

    /**
     * Check if a provider supports channel filtering.
     *
     * @param  string  $provider  Provider name
     * @return bool True if provider supports channel filtering
     */
    public function supportsChannels(string $provider): bool
    {
        return match ($provider) {
            'paystack', 'monnify', 'flutterwave', 'stripe', 'square' => true,
            'paypal' => false, // PayPal doesn't support channel filtering
            default => false,
        };
    }
}
