<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Services;

use KenDeNigerian\PayZephyr\Contracts\ChannelMapperInterface;
use KenDeNigerian\PayZephyr\Enums\PaymentChannel;

/**
 * Channel mapper service.
 */
final class ChannelMapper implements ChannelMapperInterface
{
    /**
     * Map channels to provider format.
     *
     * This method is now dynamic, automatically calling `mapTo<Provider>`
     * if the method exists, or returning raw channels for custom drivers.
     *
     * @param  array<string>|null  $channels
     */
    public function mapChannels(?array $channels, string $provider): ?array
    {
        if (empty($channels)) {
            return null;
        }

        $method = 'mapTo'.ucfirst($provider);

        if (method_exists($this, $method)) {
            return $this->{$method}($channels);
        }

        return $channels;
    }

    /**
     * Map channels to Paystack format.
     *
     * Paystack accepts: 'card', 'bank', 'ussd', 'qr', 'mobile_money', 'bank_transfer'
     */
    protected function mapToPaystack(array $channels): array
    {
        $mapping = [
            PaymentChannel::CARD->value => 'card',
            PaymentChannel::BANK_TRANSFER->value => 'bank_transfer',
            PaymentChannel::USSD->value => 'ussd',
            PaymentChannel::MOBILE_MONEY->value => 'mobile_money',
            PaymentChannel::QR_CODE->value => 'qr',
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
            PaymentChannel::CARD->value => 'CARD',
            PaymentChannel::BANK_TRANSFER->value => 'ACCOUNT_TRANSFER',
            PaymentChannel::USSD->value => 'USSD',
            PaymentChannel::MOBILE_MONEY->value => 'PHONE_NUMBER',
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
            PaymentChannel::CARD->value => 'card',
            PaymentChannel::BANK_TRANSFER->value => 'banktransfer',
            PaymentChannel::USSD->value => 'ussd',
            PaymentChannel::MOBILE_MONEY->value => 'mobilemoneyghana',
            PaymentChannel::QR_CODE->value => 'nqr',
        ];

        $mapped = array_map(
            fn ($channel) => $mapping[strtolower($channel)] ?? strtolower($channel),
            $channels
        );

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
            PaymentChannel::CARD->value => 'card',
            PaymentChannel::BANK_TRANSFER->value => 'us_bank_account',
        ];

        $mapped = array_map(
            fn ($channel) => $mapping[strtolower($channel)] ?? strtolower($channel),
            $channels
        );

        $validTypes = ['card', 'us_bank_account', 'link', 'affirm', 'klarna', 'cashapp', 'paypal'];

        return array_filter($mapped, fn ($type) => in_array($type, $validTypes));
    }

    /**
     * Map channels to PayPal format.
     *
     * PayPal doesn't use channels in the same way, but we can set payment method preference.
     * Returns null as PayPal handles this differently.
     *
     * @param  array<string>  $channels
     */
    protected function mapToPayPal(array $channels): ?array
    {
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
            PaymentChannel::CARD->value => 'CARD',
            PaymentChannel::BANK_TRANSFER->value => 'OTHER',
        ];

        $mapped = array_map(
            fn ($channel) => $mapping[strtolower($channel)] ?? strtoupper($channel),
            $channels
        );

        $validMethods = ['CARD', 'CASH', 'OTHER', 'SQUARE_GIFT_CARD'];

        return array_filter($mapped, fn ($method) => in_array($method, $validMethods));
    }

    /**
     * Map channels to OPay format.
     *
     * OPay accepts: 'CARD', 'BANK_ACCOUNT', 'OPAY_ACCOUNT', 'OPAY_QRCODE'
     */
    protected function mapToOpay(array $channels): array
    {
        $mapping = [
            PaymentChannel::CARD->value => 'CARD',
            PaymentChannel::BANK_TRANSFER->value => 'BANK_ACCOUNT',
            PaymentChannel::USSD->value => 'OPAY_ACCOUNT',
            PaymentChannel::MOBILE_MONEY->value => 'OPAY_ACCOUNT',
            PaymentChannel::QR_CODE->value => 'OPAY_QRCODE',
        ];

        $validOptions = ['CARD', 'BANK_ACCOUNT', 'OPAY_ACCOUNT', 'OPAY_QRCODE', 'BALANCE', 'OTHERS'];

        $mapped = array_map(
            fn ($channel) => $mapping[strtolower($channel)] ?? (in_array(strtoupper($channel), $validOptions) ? strtoupper($channel) : null),
            $channels
        );

        return array_filter($mapped, fn ($option) => in_array($option, $validOptions));
    }

    /**
     * Map channels to Mollie format.
     *
     * Mollie accepts: creditcard, ideal, bancontact, sofort, giropay,
     * eps, klarnapaylater, klarnasliceit, paypal, applepay, etc.
     */
    protected function mapToMollie(array $channels): array
    {
        $mapping = [
            PaymentChannel::CARD->value => 'creditcard',
            PaymentChannel::BANK_TRANSFER->value => 'banktransfer',
            PaymentChannel::MOBILE_MONEY->value => 'paypal',
        ];

        $mapped = array_map(
            fn ($channel) => $mapping[strtolower($channel)] ?? strtolower($channel),
            $channels
        );

        $validMethods = [
            'creditcard', 'ideal', 'bancontact', 'sofort', 'giropay',
            'eps', 'klarnapaylater', 'klarnasliceit', 'paypal',
            'applepay', 'banktransfer', 'giftcard', 'przelewy24',
            'kbc', 'belfius', 'mybank', 'in3',
        ];

        return array_filter($mapped, fn ($method) => in_array($method, $validMethods));
    }

    /**
     * Get default channels for provider.
     *
     * @return array<string>
     */
    public function getDefaultChannels(string $provider): array
    {
        return match ($provider) {
            'paystack' => ['card', 'bank_transfer'],
            'monnify' => ['CARD', 'ACCOUNT_TRANSFER'],
            'flutterwave' => ['card'],
            'stripe' => ['card'],
            'paypal' => [], // PayPal doesn't use channels
            'square' => ['CARD'],
            'opay' => [PaymentChannel::CARD->value, PaymentChannel::BANK_TRANSFER->value],
            default => ['card'],
        };
    }

    /**
     * Check if channels should be included.
     *
     * @param  array<string>|null  $channels
     */
    public function shouldIncludeChannels(string $provider, ?array $channels): bool
    {
        if (empty($channels)) {
            return false;
        }

        return $this->supportsChannels($provider);
    }

    /**
     * Get unified channels.
     *
     * @return array<string>
     */
    public static function getUnifiedChannels(): array
    {
        return PaymentChannel::values();
    }

    /**
     * Check if provider supports channels.
     */
    public function supportsChannels(string $provider): bool
    {
        if ($provider === 'paypal') {
            return false;
        }

        if (method_exists($this, 'mapTo'.ucfirst($provider))) {
            return true;
        }

        return false;
    }
}
