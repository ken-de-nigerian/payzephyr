<?php

use KenDeNigerian\PayZephyr\Services\ChannelMapper;

test('channel mapper maps all unified channels to paystack', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels([
        ChannelMapper::CHANNEL_CARD,
        ChannelMapper::CHANNEL_BANK_TRANSFER,
        ChannelMapper::CHANNEL_USSD,
        ChannelMapper::CHANNEL_MOBILE_MONEY,
        ChannelMapper::CHANNEL_QR_CODE,
    ], 'paystack');

    expect($result)->toBe(['card', 'bank_transfer', 'ussd', 'mobile_money', 'qr']);
});

test('channel mapper maps all unified channels to monnify', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels([
        ChannelMapper::CHANNEL_CARD,
        ChannelMapper::CHANNEL_BANK_TRANSFER,
        ChannelMapper::CHANNEL_USSD,
        ChannelMapper::CHANNEL_MOBILE_MONEY,
    ], 'monnify');

    expect($result)->toBe(['CARD', 'ACCOUNT_TRANSFER', 'USSD', 'PHONE_NUMBER']);
});

test('channel mapper maps all unified channels to flutterwave', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels([
        ChannelMapper::CHANNEL_CARD,
        ChannelMapper::CHANNEL_BANK_TRANSFER,
        ChannelMapper::CHANNEL_USSD,
        ChannelMapper::CHANNEL_MOBILE_MONEY,
        ChannelMapper::CHANNEL_QR_CODE,
    ], 'flutterwave');

    expect($result)->toContain('card', 'banktransfer', 'ussd', 'mobilemoneyghana', 'nqr');
});

test('channel mapper maps all unified channels to stripe', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels([
        ChannelMapper::CHANNEL_CARD,
        ChannelMapper::CHANNEL_BANK_TRANSFER,
    ], 'stripe');

    expect($result)->toBe(['card', 'us_bank_account']);
});

test('channel mapper handles mixed case channel names', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['CARD', 'Bank_Transfer', 'UssD'], 'paystack');

    expect($result)->toBe(['card', 'bank_transfer', 'ussd']);
});

test('channel mapper handles unknown channels in paystack', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'unknown_channel'], 'paystack');

    expect($result)->toContain('card')
        ->and($result)->toContain('unknown_channel');
});

test('channel mapper filters out invalid channels for monnify', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'invalid', 'bank_transfer'], 'monnify');

    expect($result)->toHaveCount(2)
        ->and($result)->toContain('CARD', 'ACCOUNT_TRANSFER');
});

test('channel mapper filters out invalid channels for flutterwave', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'invalid', 'bank_transfer'], 'flutterwave');

    expect($result)->toContain('card', 'banktransfer')
        ->and($result)->not->toContain('invalid');
});

test('channel mapper filters out invalid channels for stripe', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'invalid', 'bank_transfer'], 'stripe');

    expect($result)->toContain('card', 'us_bank_account')
        ->and($result)->not->toContain('invalid');
});

test('channel mapper handles empty array', function () {
    $mapper = new ChannelMapper;

    expect($mapper->mapChannels([], 'paystack'))->toBeNull();
});

test('channel mapper handles null channels', function () {
    $mapper = new ChannelMapper;

    expect($mapper->mapChannels(null, 'paystack'))->toBeNull();
});

test('channel mapper returns null for paypal', function () {
    $mapper = new ChannelMapper;

    expect($mapper->mapChannels(['card'], 'paypal'))->toBeNull();
});

test('channel mapper returns channels as-is for unknown provider', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'bank'], 'unknown_provider');

    expect($result)->toBe(['card', 'bank']);
});

test('channel mapper getDefaultChannels returns correct defaults for all providers', function () {
    $mapper = new ChannelMapper;

    expect($mapper->getDefaultChannels('paystack'))->toBe(['card', 'bank_transfer'])
        ->and($mapper->getDefaultChannels('monnify'))->toBe(['CARD', 'ACCOUNT_TRANSFER'])
        ->and($mapper->getDefaultChannels('flutterwave'))->toBe(['card'])
        ->and($mapper->getDefaultChannels('stripe'))->toBe(['card'])
        ->and($mapper->getDefaultChannels('paypal'))->toBe([])
        ->and($mapper->getDefaultChannels('unknown'))->toBe(['card']);
});

test('channel mapper supportsChannels returns correct values for all providers', function () {
    $mapper = new ChannelMapper;

    expect($mapper->supportsChannels('paystack'))->toBeTrue()
        ->and($mapper->supportsChannels('monnify'))->toBeTrue()
        ->and($mapper->supportsChannels('flutterwave'))->toBeTrue()
        ->and($mapper->supportsChannels('stripe'))->toBeTrue()
        ->and($mapper->supportsChannels('paypal'))->toBeFalse()
        ->and($mapper->supportsChannels('unknown'))->toBeFalse();
});

test('channel mapper shouldIncludeChannels returns false for empty channels', function () {
    $mapper = new ChannelMapper;

    expect($mapper->shouldIncludeChannels('paystack', []))->toBeFalse()
        ->and($mapper->shouldIncludeChannels('paystack', null))->toBeFalse();
});

test('channel mapper shouldIncludeChannels returns false for paypal even with channels', function () {
    $mapper = new ChannelMapper;

    expect($mapper->shouldIncludeChannels('paypal', ['card']))->toBeFalse();
});

test('channel mapper getUnifiedChannels returns all constants', function () {
    $channels = ChannelMapper::getUnifiedChannels();

    expect($channels)->toContain(
        ChannelMapper::CHANNEL_CARD,
        ChannelMapper::CHANNEL_BANK_TRANSFER,
        ChannelMapper::CHANNEL_USSD,
        ChannelMapper::CHANNEL_MOBILE_MONEY,
        ChannelMapper::CHANNEL_QR_CODE
    )->and($channels)->toHaveCount(5);
});

test('channel mapper handles qr code for all providers', function () {
    $mapper = new ChannelMapper;

    expect($mapper->mapChannels(['qr_code'], 'paystack'))->toBe(['qr'])
        ->and($mapper->mapChannels(['qr_code'], 'flutterwave'))->toContain('nqr')
        ->and($mapper->mapChannels(['qr_code'], 'stripe'))->toBe([]); // Not in valid types
});

test('channel mapper handles mobile money for all providers', function () {
    $mapper = new ChannelMapper;

    expect($mapper->mapChannels(['mobile_money'], 'monnify'))->toBe(['PHONE_NUMBER'])
        ->and($mapper->mapChannels(['mobile_money'], 'flutterwave'))->toContain('mobilemoneyghana')
        ->and($mapper->mapChannels(['mobile_money'], 'paystack'))->toBe(['mobile_money']);
});
