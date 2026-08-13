<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Services;

use KenDeNigerian\PayZephyr\Contracts\ChannelMapperInterface;
use KenDeNigerian\PayZephyr\Enums\PaymentChannel;

final class ChannelMapper implements ChannelMapperInterface
{
    /**
     * @param  array<int, string>|null  $channels
     * @return array<int, string>|null
     */
    public function mapChannels(?array $channels, string $provider): ?array
    {
        if (empty($channels)) {
            return null;
        }

        return match (strtolower($provider)) {
            'paystack' => $this->mapToPaystack($channels),
            'monnify' => $this->mapToMonnify($channels),
            'flutterwave' => $this->mapToFlutterwave($channels),
            'stripe' => $this->mapToStripe($channels),
            // PayPal's Orders v2 API has no funding-source restriction
            // parameter on order creation - the payer picks their instrument
            // on PayPal's own hosted checkout - so there is nothing to map.
            'paypal' => null,
            'square' => $this->mapToSquare($channels),
            'opay' => $this->mapToOpay($channels),
            'mollie' => $this->mapToMollie($channels),
            default => $channels,
        };
    }

    /**
     * @param  array<int, string>  $channels
     * @return array<int, string>
     */
    protected function mapToPaystack(array $channels): array
    {
        $mapping = [
            PaymentChannel::CARD->value => 'card',
            PaymentChannel::BANK_TRANSFER->value => 'bank_transfer',
            PaymentChannel::BANK_ACCOUNT->value => 'bank_transfer',
            PaymentChannel::USSD->value => 'ussd',
            PaymentChannel::MOBILE_MONEY->value => 'mobile_money',
            PaymentChannel::QR_CODE->value => 'qr',
            PaymentChannel::DIGITAL_WALLET->value => 'card',
        ];

        return array_filter(
            array_map(fn ($channel) => $mapping[strtolower($channel)] ?? $channel, $channels)
        );
    }

    /**
     * @param  array<int, string>  $channels
     * @return array<int, string>
     */
    protected function mapToMonnify(array $channels): array
    {
        $mapping = [
            PaymentChannel::CARD->value => 'CARD',
            PaymentChannel::BANK_TRANSFER->value => 'ACCOUNT_TRANSFER',
            PaymentChannel::BANK_ACCOUNT->value => 'ACCOUNT_TRANSFER',
            PaymentChannel::USSD->value => 'USSD',
            PaymentChannel::MOBILE_MONEY->value => 'PHONE_NUMBER',
            PaymentChannel::DIGITAL_WALLET->value => 'CARD',
        ];

        $mapped = array_map(
            fn ($channel) => $mapping[strtolower($channel)] ?? strtoupper($channel),
            $channels
        );

        return array_filter($mapped, fn ($channel) => in_array($channel, ['CARD', 'ACCOUNT_TRANSFER', 'USSD', 'PHONE_NUMBER']));
    }

    /**
     * @param  array<int, string>  $channels
     * @return array<int, string>
     */
    protected function mapToFlutterwave(array $channels): array
    {
        $mapping = [
            PaymentChannel::CARD->value => 'card',
            PaymentChannel::BANK_TRANSFER->value => 'banktransfer',
            PaymentChannel::BANK_ACCOUNT->value => 'banktransfer',
            PaymentChannel::USSD->value => 'ussd',
            PaymentChannel::MOBILE_MONEY->value => 'mobilemoneyghana',
            PaymentChannel::QR_CODE->value => 'nqr',
            PaymentChannel::DIGITAL_WALLET->value => 'card',
            PaymentChannel::PAYPAL->value => 'paypal',
        ];

        $mapped = array_map(
            fn ($channel) => $mapping[strtolower($channel)] ?? strtolower($channel),
            $channels
        );

        $validOptions = [
            'card', 'account', 'banktransfer', 'ussd', 'mpesa',
            'mobilemoneyghana', 'mobilemoneyfranco', 'mobilemoneyuganda',
            'mobilemoneyrwanda', 'mobilemoneyzambia', 'mobilemoneytanzania',
            'nqr', 'barter', 'credit', 'opay', 'mobilemoneycameroon',
            'mobilemoneysenegal', 'mobilemoneycotedivoire', 'mobilemoneykenya',
            'paypal',
        ];

        return array_filter($mapped, fn ($option) => in_array($option, $validOptions));
    }

    /**
     * @param  array<int, string>  $channels
     * @return array<int, string>
     */
    protected function mapToStripe(array $channels): array
    {
        $mapping = [
            PaymentChannel::CARD->value => 'card',
            PaymentChannel::BANK_TRANSFER->value => 'us_bank_account',
            PaymentChannel::BANK_ACCOUNT->value => 'us_bank_account',
            PaymentChannel::MOBILE_MONEY->value => 'link',
            PaymentChannel::DIGITAL_WALLET->value => 'link',
            PaymentChannel::PAYPAL->value => 'paypal',
        ];

        $mapped = array_map(
            fn ($channel) => $mapping[strtolower($channel)] ?? strtolower($channel),
            $channels
        );

        $validTypes = [
            'card', 'us_bank_account', 'link', 'affirm', 'klarna',
            'cashapp', 'paypal', 'apple_pay', 'google_pay', 'alipay',
            'wechat_pay', 'bancontact', 'ideal', 'sofort', 'giropay',
            'eps', 'p24', 'blik', 'boleto', 'oxxo',
        ];

        return array_filter($mapped, fn ($type) => in_array($type, $validTypes));
    }

    /**
     * @param  array<int, string>  $channels
     * @return array<int, string>
     */
    protected function mapToSquare(array $channels): array
    {
        $mapping = [
            PaymentChannel::CARD->value => 'CARD',
            PaymentChannel::BANK_TRANSFER->value => 'OTHER',
            PaymentChannel::BANK_ACCOUNT->value => 'OTHER',
            PaymentChannel::MOBILE_MONEY->value => 'CARD',
            PaymentChannel::DIGITAL_WALLET->value => 'CARD',
            PaymentChannel::QR_CODE->value => 'CARD',
        ];

        $mapped = array_map(
            fn ($channel) => $mapping[strtolower($channel)] ?? strtoupper($channel),
            $channels
        );

        $validMethods = ['CARD', 'CASH', 'OTHER', 'SQUARE_GIFT_CARD', 'EXTERNAL'];

        return array_filter($mapped, fn ($method) => in_array($method, $validMethods));
    }

    /**
     * @param  array<int, string>  $channels
     * @return array<int, string>
     */
    protected function mapToOpay(array $channels): array
    {
        $mapping = [
            PaymentChannel::CARD->value => 'CARD',
            PaymentChannel::BANK_TRANSFER->value => 'BANK_ACCOUNT',
            PaymentChannel::BANK_ACCOUNT->value => 'BANK_ACCOUNT',
            PaymentChannel::USSD->value => 'OPAY_ACCOUNT',
            PaymentChannel::MOBILE_MONEY->value => 'OPAY_ACCOUNT',
            PaymentChannel::DIGITAL_WALLET->value => 'CARD',
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
     * @param  array<int, string>  $channels
     * @return array<int, string>
     */
    protected function mapToMollie(array $channels): array
    {
        $mapping = [
            PaymentChannel::CARD->value => 'creditcard',
            PaymentChannel::BANK_TRANSFER->value => 'banktransfer',
            PaymentChannel::BANK_ACCOUNT->value => 'banktransfer',
            PaymentChannel::MOBILE_MONEY->value => 'paypal',
            PaymentChannel::PAYPAL->value => 'paypal',
            PaymentChannel::DIGITAL_WALLET->value => 'applepay',
            PaymentChannel::QR_CODE->value => 'ideal',
        ];

        $mapped = array_map(
            fn ($channel) => $mapping[strtolower($channel)] ?? strtolower($channel),
            $channels
        );

        $validMethods = [
            'creditcard', 'ideal', 'bancontact', 'sofort', 'giropay',
            'eps', 'klarnapaylater', 'klarnasliceit', 'paypal',
            'applepay', 'banktransfer', 'giftcard', 'przelewy24',
            'kbc', 'belfius', 'mybank', 'in3', 'tikkie', 'bizum',
            'blik', 'paylater', 'sliceit', 'voucher',
        ];

        return array_filter($mapped, fn ($method) => in_array($method, $validMethods));
    }

    /**
     * @param  array<int, string>|null  $channels
     */
    public function shouldIncludeChannels(string $provider, ?array $channels): bool
    {
        if (empty($channels)) {
            return false;
        }

        return $this->supportsChannels($provider);
    }

    /**
     * @return array<int, string>
     */
    public static function getUnifiedChannels(): array
    {
        return PaymentChannel::values();
    }

    public function supportsChannels(string $provider): bool
    {
        if (strtolower($provider) === 'paypal') {
            return false;
        }

        return match (strtolower($provider)) {
            'paystack', 'monnify', 'flutterwave', 'stripe', 'square', 'opay', 'mollie' => true,
            default => false,
        };
    }

    public function mapFromProvider(string $providerMethod, string $provider): ?string
    {
        if (empty($providerMethod)) {
            return null;
        }

        $result = match (strtolower($provider)) {
            'paystack' => $this->mapFromPaystack($providerMethod),
            'stripe' => $this->mapFromStripe($providerMethod),
            'monnify' => $this->mapFromMonnify($providerMethod),
            'mollie' => $this->mapFromMollie($providerMethod),
            'flutterwave' => $this->mapFromFlutterwave($providerMethod),
            'square' => $this->mapFromSquare($providerMethod),
            'opay' => $this->mapFromOpay($providerMethod),
            default => null,
        };

        if ($result !== null) {
            return $result;
        }

        $providerMethodLower = strtolower($providerMethod);
        foreach (PaymentChannel::cases() as $channel) {
            if (strtolower($channel->value) === $providerMethodLower) {
                return $channel->value;
            }
        }

        return null;
    }

    protected function mapFromPaystack(string $providerMethod): ?string
    {
        $mapping = [
            'card' => PaymentChannel::CARD->value,
            'bank' => PaymentChannel::BANK_TRANSFER->value,
            'bank_transfer' => PaymentChannel::BANK_TRANSFER->value,
            'ussd' => PaymentChannel::USSD->value,
            'qr' => PaymentChannel::QR_CODE->value,
            'mobile_money' => PaymentChannel::MOBILE_MONEY->value,
            'mobilemoney' => PaymentChannel::MOBILE_MONEY->value,
        ];

        return $mapping[strtolower($providerMethod)] ?? null;
    }

    protected function mapFromStripe(string $providerMethod): ?string
    {
        $mapping = [
            'card' => PaymentChannel::CARD->value,
            'us_bank_account' => PaymentChannel::BANK_ACCOUNT->value,
            'link' => PaymentChannel::DIGITAL_WALLET->value,
            'apple_pay' => PaymentChannel::DIGITAL_WALLET->value,
            'google_pay' => PaymentChannel::DIGITAL_WALLET->value,
            'paypal' => PaymentChannel::PAYPAL->value,
        ];

        return $mapping[strtolower($providerMethod)] ?? null;
    }

    protected function mapFromMonnify(string $providerMethod): ?string
    {
        $mapping = [
            'CARD' => PaymentChannel::CARD->value,
            'ACCOUNT_TRANSFER' => PaymentChannel::BANK_ACCOUNT->value,
            'USSD' => PaymentChannel::USSD->value,
            'PHONE_NUMBER' => PaymentChannel::MOBILE_MONEY->value,
        ];

        return $mapping[strtoupper($providerMethod)] ?? null;
    }

    protected function mapFromMollie(string $providerMethod): ?string
    {
        $mapping = [
            'creditcard' => PaymentChannel::CARD->value,
            'banktransfer' => PaymentChannel::BANK_TRANSFER->value,
            'paypal' => PaymentChannel::PAYPAL->value,
            'ideal' => PaymentChannel::QR_CODE->value,
            'applepay' => PaymentChannel::DIGITAL_WALLET->value,
        ];

        return $mapping[strtolower($providerMethod)] ?? null;
    }

    protected function mapFromFlutterwave(string $providerMethod): ?string
    {
        $mapping = [
            'card' => PaymentChannel::CARD->value,
            'banktransfer' => PaymentChannel::BANK_TRANSFER->value,
            'ussd' => PaymentChannel::USSD->value,
            'mobilemoneyghana' => PaymentChannel::MOBILE_MONEY->value,
            'mobilemoneyfranco' => PaymentChannel::MOBILE_MONEY->value,
            'mobilemoneyuganda' => PaymentChannel::MOBILE_MONEY->value,
            'mobilemoneyrwanda' => PaymentChannel::MOBILE_MONEY->value,
            'mobilemoneyzambia' => PaymentChannel::MOBILE_MONEY->value,
            'mobilemoneytanzania' => PaymentChannel::MOBILE_MONEY->value,
            'mpesa' => PaymentChannel::MOBILE_MONEY->value,
            'nqr' => PaymentChannel::QR_CODE->value,
        ];

        return $mapping[strtolower($providerMethod)] ?? null;
    }

    protected function mapFromSquare(string $providerMethod): ?string
    {
        $mapping = [
            'CARD' => PaymentChannel::CARD->value,
            'OTHER' => PaymentChannel::BANK_ACCOUNT->value,
            'CASH' => PaymentChannel::BANK_TRANSFER->value,
            'SQUARE_GIFT_CARD' => PaymentChannel::CARD->value,
        ];

        return $mapping[strtoupper($providerMethod)] ?? null;
    }

    protected function mapFromOpay(string $providerMethod): ?string
    {
        $mapping = [
            'CARD' => PaymentChannel::CARD->value,
            'BANK_ACCOUNT' => PaymentChannel::BANK_TRANSFER->value,
            'OPAY_ACCOUNT' => PaymentChannel::USSD->value,
            'OPAY_QRCODE' => PaymentChannel::QR_CODE->value,
            'BALANCE' => PaymentChannel::MOBILE_MONEY->value,
        ];

        return $mapping[strtoupper($providerMethod)] ?? null;
    }
}
