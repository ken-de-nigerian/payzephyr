<?php

use KenDeNigerian\PayZephyr\Enums\PaymentChannel;
use KenDeNigerian\PayZephyr\Services\ChannelMapper;

test('channel mapper maps channels to paystack format', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'bank_transfer', 'ussd'], 'paystack');

    expect($result)->toBe(['card', 'bank_transfer', 'ussd']);
});

test('channel mapper maps channels to monnify format', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'bank_transfer', 'ussd'], 'monnify');

    expect($result)->toBe(['CARD', 'ACCOUNT_TRANSFER', 'USSD']);
});

test('channel mapper maps channels to flutterwave format', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'bank_transfer', 'ussd'], 'flutterwave');

    expect($result)->toBe(['card', 'banktransfer', 'ussd']);
});

test('channel mapper maps channels to stripe format', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'bank_transfer'], 'stripe');

    expect($result)->toBe(['card', 'us_bank_account']);
});

test('channel mapper returns null for paypal', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card'], 'paypal');

    expect($result)->toBeNull();
});

test('channel mapper returns null for empty channels', function (): void {
    $mapper = new ChannelMapper;

    expect($mapper->mapChannels([], 'paystack'))->toBeNull()
        ->and($mapper->mapChannels(null, 'paystack'))->toBeNull();
});

test('channel mapper returns channels as-is for unknown provider', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'bank'], 'unknown');

    expect($result)->toBe(['card', 'bank']);
});

test('channel mapper filters invalid channels for monnify', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'invalid_channel'], 'monnify');

    expect($result)->toBe(['CARD']);
});

test('channel mapper filters invalid channels for flutterwave', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'invalid_channel'], 'flutterwave');

    expect($result)->toBe(['card']);
});

test('channel mapper filters invalid channels for stripe', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'invalid_channel'], 'stripe');

    expect($result)->toBe(['card']);
});

test('channel mapper supportsChannels returns correct values', function (): void {
    $mapper = new ChannelMapper;

    expect($mapper->supportsChannels('paystack'))->toBeTrue()
        ->and($mapper->supportsChannels('monnify'))->toBeTrue()
        ->and($mapper->supportsChannels('flutterwave'))->toBeTrue()
        ->and($mapper->supportsChannels('stripe'))->toBeTrue()
        ->and($mapper->supportsChannels('paypal'))->toBeFalse()
        ->and($mapper->supportsChannels('unknown'))->toBeFalse();
});

test('channel mapper shouldIncludeChannels returns correct values', function (): void {
    $mapper = new ChannelMapper;

    expect($mapper->shouldIncludeChannels('paystack', ['card']))->toBeTrue()
        ->and($mapper->shouldIncludeChannels('paystack', []))->toBeFalse()
        ->and($mapper->shouldIncludeChannels('paystack', null))->toBeFalse()
        ->and($mapper->shouldIncludeChannels('paypal', ['card']))->toBeFalse();
});

test('channel mapper getUnifiedChannels returns all unified channels', function (): void {
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

test('channel mapper handles mobile money channel', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['mobile_money'], 'monnify');

    expect($result)->toBe(['PHONE_NUMBER']);
});

test('channel mapper handles qr code channel', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['qr_code'], 'paystack');

    expect($result)->toBe(['qr']);
});

test('channel mapper handles case insensitive channel names', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['CARD', 'Bank_Transfer'], 'paystack');

    expect($result)->toBe(['card', 'bank_transfer']);
});

test('channel mapper mapToPaystack filters out invalid channels', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'invalid_channel', 'ussd'], 'paystack');

    expect($result)->toContain('card', 'ussd', 'invalid_channel');
});

test('channel mapper mapToMonnify handles all valid channels', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'bank_transfer', 'ussd', 'mobile_money'], 'monnify');

    expect($result)->toContain('CARD', 'ACCOUNT_TRANSFER', 'USSD', 'PHONE_NUMBER');
});

test('channel mapper mapToFlutterwave handles all valid channels', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'bank_transfer', 'ussd', 'qr_code'], 'flutterwave');

    expect($result)->toContain('card', 'banktransfer', 'ussd', 'nqr');
});

test('channel mapper mapToStripe handles valid payment method types', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'bank_transfer', 'link'], 'stripe');

    expect($result)->toContain('card', 'us_bank_account', 'link');
});

test('channel mapper mapToStripe filters invalid types', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'invalid_type'], 'stripe');

    expect($result)->toContain('card')
        ->and($result)->not->toContain('invalid_type');
});

test('channel mapper handles mixed case channel names', function (): void {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['Card', 'BANK_TRANSFER', 'Ussd'], 'monnify');

    expect($result)->toContain('CARD', 'ACCOUNT_TRANSFER', 'USSD');
});

test('channel mapper returns null for empty array', function (): void {
    $mapper = new ChannelMapper;

    expect($mapper->mapChannels([], 'paystack'))->toBeNull();
});

test('channel mapper returns null for null input', function (): void {
    $mapper = new ChannelMapper;

    expect($mapper->mapChannels(null, 'paystack'))->toBeNull();
});
