<?php

use KenDeNigerian\PayZephyr\Enums\PaymentChannel;
use KenDeNigerian\PayZephyr\Services\ChannelMapper;

test('channel mapper maps all unified channels to paystack', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels([
        PaymentChannel::CARD->value,
        PaymentChannel::BANK_TRANSFER->value,
        PaymentChannel::USSD->value,
        PaymentChannel::MOBILE_MONEY->value,
        PaymentChannel::QR_CODE->value,
    ], 'paystack');

    expect($result)->toBe(['card', 'bank_transfer', 'ussd', 'mobile_money', 'qr']);
});

test('channel mapper maps all unified channels to monnify', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels([
        PaymentChannel::CARD->value,
        PaymentChannel::BANK_TRANSFER->value,
        PaymentChannel::USSD->value,
        PaymentChannel::MOBILE_MONEY->value,
    ], 'monnify');

    expect($result)->toBe(['CARD', 'ACCOUNT_TRANSFER', 'USSD', 'PHONE_NUMBER']);
});

test('channel mapper maps all unified channels to flutterwave', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels([
        PaymentChannel::CARD->value,
        PaymentChannel::BANK_TRANSFER->value,
        PaymentChannel::USSD->value,
        PaymentChannel::MOBILE_MONEY->value,
        PaymentChannel::QR_CODE->value,
    ], 'flutterwave');

    expect($result)->toContain('card', 'banktransfer', 'ussd', 'mobilemoneyghana', 'nqr');
});

test('channel mapper maps all unified channels to stripe', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels([
        PaymentChannel::CARD->value,
        PaymentChannel::BANK_TRANSFER->value,
    ], 'stripe');

    expect($result)->toBe(['card', 'us_bank_account']);
});

test('channel mapper handles mixed case channel names', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['CARD', 'Bank_Transfer', 'UssD'], 'paystack');

    expect($result)->toBe(['card', 'bank_transfer', 'ussd']);
});

test('channel mapper handles unknown channels in paystack', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'unknown_channel'], 'paystack');

    expect($result)->toContain('card')
        ->and($result)->toContain('unknown_channel');
});

test('channel mapper filters out invalid channels for monnify', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'invalid', 'bank_transfer'], 'monnify');

    expect($result)->toHaveCount(2)
        ->and($result)->toContain('CARD', 'ACCOUNT_TRANSFER');
});

test('channel mapper filters out invalid channels for flutterwave', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'invalid', 'bank_transfer'], 'flutterwave');

    expect($result)->toContain('card', 'banktransfer')
        ->and($result)->not->toContain('invalid');
});

test('channel mapper filters out invalid channels for stripe', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'invalid', 'bank_transfer'], 'stripe');

    expect($result)->toContain('card', 'us_bank_account')
        ->and($result)->not->toContain('invalid');
});

test('channel mapper handles empty array', function (): void {
    $mapper = new ChannelMapper;

    expect($mapper->mapChannels([], 'paystack'))->toBeNull();
});

test('channel mapper handles null channels', function (): void {
    $mapper = new ChannelMapper;

    expect($mapper->mapChannels(null, 'paystack'))->toBeNull();
});

test('channel mapper returns null for paypal', function (): void {
    $mapper = new ChannelMapper;

    expect($mapper->mapChannels(['card'], 'paypal'))->toBeNull();
});

test('channel mapper returns channels as-is for unknown provider', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'bank'], 'unknown_provider');

    expect($result)->toBe(['card', 'bank']);
});

test('channel mapper supportsChannels returns correct values for all providers', function (): void {
    $mapper = new ChannelMapper;

    expect($mapper->supportsChannels('paystack'))->toBeTrue()
        ->and($mapper->supportsChannels('monnify'))->toBeTrue()
        ->and($mapper->supportsChannels('flutterwave'))->toBeTrue()
        ->and($mapper->supportsChannels('stripe'))->toBeTrue()
        ->and($mapper->supportsChannels('paypal'))->toBeFalse()
        ->and($mapper->supportsChannels('unknown'))->toBeFalse();
});

test('channel mapper shouldIncludeChannels returns false for empty channels', function (): void {
    $mapper = new ChannelMapper;

    expect($mapper->shouldIncludeChannels('paystack', []))->toBeFalse()
        ->and($mapper->shouldIncludeChannels('paystack', null))->toBeFalse();
});

test('channel mapper shouldIncludeChannels returns false for paypal even with channels', function (): void {
    $mapper = new ChannelMapper;

    expect($mapper->shouldIncludeChannels('paypal', ['card']))->toBeFalse();
});

test('channel mapper getUnifiedChannels returns all enum values', function (): void {
    $channels = ChannelMapper::getUnifiedChannels();

    expect($channels)->toContain(
        PaymentChannel::CARD->value,
        PaymentChannel::BANK_TRANSFER->value,
        PaymentChannel::USSD->value,
        PaymentChannel::MOBILE_MONEY->value,
        PaymentChannel::QR_CODE->value,
        PaymentChannel::DIGITAL_WALLET->value,
        PaymentChannel::PAYPAL->value,
        PaymentChannel::BANK_ACCOUNT->value
    )->and($channels)->toHaveCount(8);
});

test('channel mapper handles qr code for all providers', function (): void {
    $mapper = new ChannelMapper;

    expect($mapper->mapChannels(['qr_code'], 'paystack'))->toBe(['qr'])
        ->and($mapper->mapChannels(['qr_code'], 'flutterwave'))->toContain('nqr')
        ->and($mapper->mapChannels(['qr_code'], 'stripe'))->toBe([]); // Not in valid types
});

test('channel mapper handles mobile money for all providers', function (): void {
    $mapper = new ChannelMapper;

    expect($mapper->mapChannels(['mobile_money'], 'monnify'))->toBe(['PHONE_NUMBER'])
        ->and($mapper->mapChannels(['mobile_money'], 'flutterwave'))->toContain('mobilemoneyghana')
        ->and($mapper->mapChannels(['mobile_money'], 'paystack'))->toBe(['mobile_money']);
});
