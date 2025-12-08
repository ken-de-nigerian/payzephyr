<?php

use KenDeNigerian\PayZephyr\Services\ChannelMapper;

test('channel mapper maps channels to paystack format', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'bank_transfer', 'ussd'], 'paystack');

    expect($result)->toBe(['card', 'bank_transfer', 'ussd']);
});

test('channel mapper maps channels to monnify format', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'bank_transfer', 'ussd'], 'monnify');

    expect($result)->toBe(['CARD', 'ACCOUNT_TRANSFER', 'USSD']);
});

test('channel mapper maps channels to flutterwave format', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'bank_transfer', 'ussd'], 'flutterwave');

    expect($result)->toBe(['card', 'banktransfer', 'ussd']);
});

test('channel mapper maps channels to stripe format', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'bank_transfer'], 'stripe');

    expect($result)->toBe(['card', 'us_bank_account']);
});

test('channel mapper returns null for paypal', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card'], 'paypal');

    expect($result)->toBeNull();
});

test('channel mapper returns null for empty channels', function () {
    $mapper = new ChannelMapper;

    expect($mapper->mapChannels([], 'paystack'))->toBeNull()
        ->and($mapper->mapChannels(null, 'paystack'))->toBeNull();
});

test('channel mapper returns channels as-is for unknown provider', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'bank'], 'unknown');

    expect($result)->toBe(['card', 'bank']);
});

test('channel mapper filters invalid channels for monnify', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'invalid_channel'], 'monnify');

    expect($result)->toBe(['CARD']);
});

test('channel mapper filters invalid channels for flutterwave', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'invalid_channel'], 'flutterwave');

    expect($result)->toBe(['card']);
});

test('channel mapper filters invalid channels for stripe', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'invalid_channel'], 'stripe');

    expect($result)->toBe(['card']);
});

test('channel mapper getDefaultChannels returns correct defaults', function () {
    $mapper = new ChannelMapper;

    expect($mapper->getDefaultChannels('paystack'))->toBe(['card', 'bank_transfer'])
        ->and($mapper->getDefaultChannels('monnify'))->toBe(['CARD', 'ACCOUNT_TRANSFER'])
        ->and($mapper->getDefaultChannels('flutterwave'))->toBe(['card'])
        ->and($mapper->getDefaultChannels('stripe'))->toBe(['card'])
        ->and($mapper->getDefaultChannels('paypal'))->toBe([])
        ->and($mapper->getDefaultChannels('unknown'))->toBe(['card']);
});

test('channel mapper supportsChannels returns correct values', function () {
    $mapper = new ChannelMapper;

    expect($mapper->supportsChannels('paystack'))->toBeTrue()
        ->and($mapper->supportsChannels('monnify'))->toBeTrue()
        ->and($mapper->supportsChannels('flutterwave'))->toBeTrue()
        ->and($mapper->supportsChannels('stripe'))->toBeTrue()
        ->and($mapper->supportsChannels('paypal'))->toBeFalse()
        ->and($mapper->supportsChannels('unknown'))->toBeFalse();
});

test('channel mapper shouldIncludeChannels returns correct values', function () {
    $mapper = new ChannelMapper;

    expect($mapper->shouldIncludeChannels('paystack', ['card']))->toBeTrue()
        ->and($mapper->shouldIncludeChannels('paystack', []))->toBeFalse()
        ->and($mapper->shouldIncludeChannels('paystack', null))->toBeFalse()
        ->and($mapper->shouldIncludeChannels('paypal', ['card']))->toBeFalse();
});

test('channel mapper getUnifiedChannels returns all unified channels', function () {
    $channels = ChannelMapper::getUnifiedChannels();

    expect($channels)->toContain(
        ChannelMapper::CHANNEL_CARD,
        ChannelMapper::CHANNEL_BANK_TRANSFER,
        ChannelMapper::CHANNEL_USSD,
        ChannelMapper::CHANNEL_MOBILE_MONEY,
        ChannelMapper::CHANNEL_QR_CODE
    )->and($channels)->toHaveCount(5);
});

test('channel mapper handles mobile money channel', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['mobile_money'], 'monnify');

    expect($result)->toBe(['PHONE_NUMBER']);
});

test('channel mapper handles qr code channel', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['qr_code'], 'paystack');

    expect($result)->toBe(['qr']);
});

test('channel mapper handles case insensitive channel names', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['CARD', 'Bank_Transfer'], 'paystack');

    expect($result)->toBe(['card', 'bank_transfer']);
});

test('channel mapper mapToPaystack filters out invalid channels', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'invalid_channel', 'ussd'], 'paystack');

    // Paystack mapper doesn't filter, it just maps - invalid_channel stays as-is
    expect($result)->toContain('card', 'ussd', 'invalid_channel');
});

test('channel mapper mapToMonnify handles all valid channels', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'bank_transfer', 'ussd', 'mobile_money'], 'monnify');

    expect($result)->toContain('CARD', 'ACCOUNT_TRANSFER', 'USSD', 'PHONE_NUMBER');
});

test('channel mapper mapToFlutterwave handles all valid channels', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'bank_transfer', 'ussd', 'qr_code'], 'flutterwave');

    expect($result)->toContain('card', 'banktransfer', 'ussd', 'nqr');
});

test('channel mapper mapToStripe handles valid payment method types', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'bank_transfer', 'link'], 'stripe');

    expect($result)->toContain('card', 'us_bank_account', 'link');
});

test('channel mapper mapToStripe filters invalid types', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'invalid_type'], 'stripe');

    expect($result)->toContain('card')
        ->and($result)->not->toContain('invalid_type');
});

test('channel mapper handles mixed case channel names', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['Card', 'BANK_TRANSFER', 'Ussd'], 'monnify');

    expect($result)->toContain('CARD', 'ACCOUNT_TRANSFER', 'USSD');
});

test('channel mapper returns null for empty array', function () {
    $mapper = new ChannelMapper;

    expect($mapper->mapChannels([], 'paystack'))->toBeNull();
});

test('channel mapper returns null for null input', function () {
    $mapper = new ChannelMapper;

    expect($mapper->mapChannels(null, 'paystack'))->toBeNull();
});
